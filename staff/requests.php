<?php

require_once __DIR__ . '/../includes/auth.php';

function assignLoanAssets(PDO $database, int $requestId, int $equipmentId, int $quantity, string $tagInput): void
{
    $tags = array_map('trim', explode(',', $tagInput));
    if (count($tags) !== $quantity) {
        throw new DomainException('Enter exactly one asset tag for each unit being issued.');
    }

    $find = $database->prepare(
        'SELECT id FROM equipment_assets WHERE equipment_id = ? AND asset_tag = ?
         AND retired_at IS NULL AND current_request_id IS NULL AND is_damaged = 0'
    );
    $assign = $database->prepare('UPDATE equipment_assets SET current_request_id = ? WHERE id = ?');
    $history = $database->prepare('INSERT INTO loan_assets (request_id, asset_id) VALUES (?, ?)');
    foreach ($tags as $tag) {
        if (!validText($tag, 80)) {
            throw new DomainException('Enter a valid tag for each unit being issued.');
        }
        $find->execute([$equipmentId, $tag]);
        $assetId = $find->fetchColumn();
        if ($assetId === false) {
            throw new DomainException('Check that each selected tag is distinct and in storage for this equipment.');
        }
        $assign->execute([$requestId, $assetId]);
        $history->execute([$requestId, $assetId]);
    }
}

function approveRequest(PDO $database, int $requestId, string $dueDate, string $condition, string $decision, string $assetTags, int $staffId): void
{
    $lookup = $database->prepare('SELECT equipment_id, user_id FROM borrow_requests WHERE id = ?');
    $lookup->execute([$requestId]);
    $requestOwner = $lookup->fetch();

    if ($requestOwner === false) {
        throw new DomainException('Only pending requests can be approved.');
    }

    $equipmentId = $requestOwner['equipment_id'];
    $database->beginTransaction();

    try {
        // Lock equipment first so approvals, returns, and inventory edits use the same order.
        $stock = $database->prepare('SELECT available_quantity, is_active, is_under_maintenance, tracks_assets FROM equipment WHERE id = ? FOR UPDATE');
        $stock->execute([$equipmentId]);
        $item = $stock->fetch();

        // Serialize approvals for one student, even when they borrow different equipment.
        $student = $database->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' FOR UPDATE");
        $student->execute([$requestOwner['user_id']]);
        if ($student->fetchColumn() === false) {
            throw new DomainException('The request must belong to a student.');
        }

        $loan = $database->prepare(
            'SELECT quantity, status, collection_deadline, CURRENT_DATE() AS today,
                    CURRENT_TIMESTAMP() AS now_time
             FROM borrow_requests WHERE id = ? AND equipment_id = ? FOR UPDATE'
        );
        $loan->execute([$requestId, $equipmentId]);
        $request = $loan->fetch();
        $collecting = $decision === 'collect';
        $reserving = $decision === 'reserve';

        if ($request === false || ($collecting
            ? ($request['status'] !== 'reserved' || $request['collection_deadline'] <= $request['now_time'])
            : !in_array($request['status'], ['pending', 'waitlisted'], true))) {
            throw new DomainException('This request is no longer eligible for the selected action.');
        }
        if ($item === false || !$item['is_active']) {
            throw new DomainException('This equipment is no longer active.');
        }
        if ($item['is_under_maintenance']) {
            throw new DomainException('This equipment is under maintenance.');
        }
        if (!$reserving && $dueDate < $request['today']) {
            throw new DomainException('Choose a return due date of today or later.');
        }

        if (!$collecting) {
            $head = $database->prepare(
                "SELECT id FROM borrow_requests WHERE equipment_id = ? AND status = 'waitlisted' ORDER BY id LIMIT 1"
            );
            $head->execute([$equipmentId]);
            $firstWaiting = $head->fetchColumn();
            if ($firstWaiting !== false && (int) $firstWaiting !== $requestId) {
                throw new DomainException('Serve the oldest waitlisted request for this equipment first.');
            }
            if ($request['quantity'] + borrowedUnits($database, $requestOwner['user_id']) > MAX_BORROWED_UNITS) {
                throw new DomainException('This action exceeds the student borrowing limit of ' . MAX_BORROWED_UNITS . ' units.');
            }
            if ($request['quantity'] > $item['available_quantity']) {
                throw new DomainException('There is not enough available stock for this request.');
            }

            $deduct = $database->prepare('UPDATE equipment SET available_quantity = available_quantity - ? WHERE id = ?');
            $deduct->execute([$request['quantity'], $equipmentId]);
        }

        if ($reserving) {
            $reserve = $database->prepare(
                "UPDATE borrow_requests SET status = 'reserved', approval_date = CURRENT_DATE(),
                 collection_deadline = CURRENT_TIMESTAMP() + INTERVAL 48 HOUR WHERE id = ?"
            );
            $reserve->execute([$requestId]);
        } else {
            if ($item['tracks_assets']) {
                assignLoanAssets($database, $requestId, $equipmentId, (int) $request['quantity'], $assetTags);
            }
            $issue = $database->prepare(
                "UPDATE borrow_requests SET status = 'borrowed', approval_date = COALESCE(approval_date, ?),
                 issue_date = ?, due_date = ?, issue_condition = ? WHERE id = ?"
            );
            $issue->execute([$request['today'], $request['today'], $dueDate, $condition, $requestId]);
        }

        recordStaffActivity($database, $staffId, $reserving ? 'Reserved for pickup' : 'Equipment issued', 'Loan', $requestId);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function reviewExtension(PDO $database, int $requestId, int $version, string $decision, string $note, int $staffId): void
{
    if (!in_array($decision, ['approve', 'reject'], true)
        || ($note !== '' && !validText($note, 1000)) || ($decision === 'reject' && $note === '')) {
        throw new DomainException('Choose an extension decision and enter a rejection reason of up to 1,000 characters when rejecting.');
    }

    $database->beginTransaction();
    try {
        $statement = $database->prepare(
            "SELECT due_date, extension_due_date, CURRENT_DATE() AS today
             FROM borrow_requests
             WHERE id = ? AND status = 'borrowed' AND extension_status = 'pending'
               AND extension_version = ? FOR UPDATE"
        );
        $statement->execute([$requestId, $version]);
        $loan = $statement->fetch();

        if ($loan === false) {
            throw new DomainException('This extension is no longer awaiting review. Refresh the page.');
        }
        if ($decision === 'approve' && ($loan['extension_due_date'] <= $loan['due_date']
            || $loan['extension_due_date'] < $loan['today'])) {
            throw new DomainException('The requested date must still be later than the current due date and today or later.');
        }

        $newDueDate = $decision === 'approve' ? $loan['extension_due_date'] : $loan['due_date'];
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $update = $database->prepare(
            'UPDATE borrow_requests SET due_date = ?, extension_status = ?,
             extension_review_note = ?, extension_decided_at = CURRENT_TIMESTAMP() WHERE id = ?'
        );
        $update->execute([$newDueDate, $status, $note === '' ? null : $note, $requestId]);
        recordStaffActivity($database, $staffId, $decision === 'approve' ? 'Extension approved' : 'Extension rejected', 'Loan', $requestId);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

$user = requireRole('staff');
$basePath = '../';
$pageTitle = 'Borrowing requests';
$database = db();

if (isset($_GET['slip'])) {
    $borrowingSlip = getBorrowingSlip($database, $user, queryValue('slip'));
    $pageTitle = 'Borrowing slip';
    require __DIR__ . '/../includes/header.php';
    require __DIR__ . '/../includes/footer.php';
    exit;
}
$errors = [];
$requestId = false;
$dueDate = '';
$rejectionReason = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && formValue('action') === 'extension') {
    checkCsrf();
    $requestId = filter_var(formValue('request_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $version = filter_var(formValue('extension_version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    try {
        if ($requestId === false || $version === false) {
            throw new DomainException('Choose a valid pending extension.');
        }
        reviewExtension($database, $requestId, $version, formValue('decision'), trim(formValue('extension_review_note')), $user['id']);
        $_SESSION['notice'] = 'Extension decision saved.';
        redirect('requests.php?status=extensions');
    } catch (DomainException $exception) {
        $errors[] = $exception->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && formValue('action') !== 'extension') {
    checkCsrf();
    $requestId = filter_var(formValue('request_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $decision = formValue('decision');
    $dueDate = formValue('due_date');
    $condition = trim(formValue('issue_condition'));
    $rejectionReason = trim(formValue('rejection_reason'));

    if ($requestId === false || !in_array($decision, ['approve', 'reserve', 'collect', 'reject'], true)) {
        $errors[] = 'Choose a valid request and decision.';
    } elseif (in_array($decision, ['approve', 'collect'], true) && !validDate($dueDate)) {
        $errors[] = 'Choose a valid return due date.';
    } elseif (in_array($decision, ['approve', 'collect'], true) && !validText($condition, 1000)) {
        $errors[] = 'Enter the condition at issue, up to 1,000 characters.';
    } elseif ($decision === 'reject' && !validText($rejectionReason, 1000)) {
        $errors[] = 'Enter a rejection reason of up to 1,000 characters.';
    } else {
        try {
            if ($decision !== 'reject') {
                approveRequest($database, $requestId, $dueDate, $condition, $decision, formValue('asset_tags'), $user['id']);
                $_SESSION['notice'] = $decision === 'reserve'
                    ? 'Equipment reserved for 48 hours.'
                    : 'Equipment issued.';
            } else {
                $database->beginTransaction();
                try {
                    $reject = $database->prepare(
                        "UPDATE borrow_requests SET status = 'rejected', rejection_reason = ?
                         WHERE id = ? AND status IN ('pending', 'waitlisted')"
                    );
                    $reject->execute([$rejectionReason, $requestId]);
                    if ($reject->rowCount() !== 1) {
                        throw new DomainException('Only pending or waitlisted requests can be rejected.');
                    }
                    recordStaffActivity($database, $user['id'], 'Request rejected', 'Loan', $requestId);
                    $database->commit();
                } catch (Throwable $exception) {
                    if ($database->inTransaction()) {
                        $database->rollBack();
                    }
                    throw $exception;
                }
                $_SESSION['notice'] = 'Request rejected.';
            }

            redirect('requests.php');
        } catch (DomainException $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

$statusOptions = [
    'all' => 'All statuses',
    'extensions' => 'Extension requests',
    'pending' => 'Pending',
    'waitlisted' => 'Waitlisted',
    'reserved' => 'Awaiting collection',
    'expired' => 'Collection expired',
    'borrowed' => 'Borrowed',
    'returned' => 'Returned',
    'rejected' => 'Rejected',
    'cancelled' => 'Cancelled',
];
$status = queryValue('status');
if (!isset($statusOptions[$status])) {
    $status = 'pending';
}

$studentId = trim(queryValue('student_id'));
$equipmentId = filter_var(queryValue('equipment_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$equipmentOptions = $database->query('SELECT id, name, equipment_code FROM equipment ORDER BY name, equipment_code')->fetchAll();
$today = $database->query('SELECT CURRENT_DATE()')->fetchColumn();
$requests = [];

if ($studentId !== '' && !validText($studentId, 30)) {
    $errors[] = 'Search using a student ID of up to 30 characters.';
} else {
    $equipmentFilter = $equipmentId === false ? null : $equipmentId;
    $statement = $database->prepare(
        "SELECT r.id, r.status, r.quantity, r.settled_quantity, r.reason, r.collection_deadline, (EXISTS (SELECT 1 FROM loan_settlements ls WHERE ls.request_id = r.id AND ls.outcome = 'missing')) AS has_missing, DATE(r.request_date) AS requested_on,
                r.extension_due_date, r.extension_reason, r.extension_status, r.extension_version, r.extension_review_note,
                r.preferred_date, r.approval_date, r.issue_date, r.issue_condition, r.return_condition, r.due_date, r.return_date, r.rejection_reason,
                u.name AS student_name, u.student_id, u.department, u.email,
                e.name AS equipment_name, e.equipment_code, e.available_quantity, e.is_under_maintenance, e.tracks_assets, e.id AS equipment_id,
                (SELECT GROUP_CONCAT(a.asset_tag ORDER BY a.asset_tag SEPARATOR ', ')
                 FROM loan_assets x JOIN equipment_assets a ON a.id = x.asset_id WHERE x.request_id = r.id) AS asset_tags
         FROM borrow_requests r
         JOIN users u ON r.user_id = u.id
         JOIN equipment e ON r.equipment_id = e.id
         WHERE LOCATE(?, u.student_id) > 0
           AND (? IS NULL OR r.equipment_id = ?)
           AND (? = 'all' OR r.status = ? OR (? = 'extensions' AND r.status = 'borrowed' AND r.extension_status = 'pending'))
         ORDER BY CASE WHEN ? = 'waitlisted' THEN r.id END, r.request_date DESC, r.id DESC"
    );
    $pagination = paginate($database, $statement, [$studentId, $equipmentFilter, $equipmentFilter, $status, $status, $status, $status], $_SERVER['REQUEST_METHOD'] === 'GET' && queryValue('export') === 'csv');
    $requests = $pagination['records'];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && queryValue('export') === 'csv' && $errors === []) {
    downloadCsv('labriqo-borrowing-records.csv', [
        'id' => 'Request ID',
        'student_id' => 'Student ID',
        'student_name' => 'Student name',
        'email' => 'Email',
        'department' => 'Department',
        'equipment_code' => 'Equipment code',
        'equipment_name' => 'Equipment name',
        'asset_tags' => 'Asset tags',
        'quantity' => 'Quantity',
        'settled_quantity' => 'Units returned or accounted for',
        'reason' => 'Borrowing reason',
        'status' => 'Status',
        'requested_on' => 'Request date',
        'preferred_date' => 'Preferred date',
        'collection_deadline' => 'Collection deadline',
        'approval_date' => 'Approval date',
        'issue_date' => 'Issue date',
        'issue_condition' => 'Condition at issue',
        'return_condition' => 'Condition at return',
        'due_date' => 'Due date',
        'return_date' => 'Return date',
        'rejection_reason' => 'Rejection reason',
        'extension_due_date' => 'Requested extension date',
        'extension_status' => 'Extension status',
        'extension_reason' => 'Extension reason',
        'extension_review_note' => 'Extension review note',
    ], $requests);
}

require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading page-actions">
    <h1>Borrowing requests</h1>
    <div class="form-actions">
        <a href="requests.php?status=waitlisted">Waitlist</a>
        <a href="requests.php?status=reserved">Awaiting collection</a>
        <a href="requests.php?status=extensions">Review extensions</a>
        <a href="returns.php">View active loans and returns</a>
    </div>
</div>

<form method="get" class="panel filter-form">
    <div class="field">
        <label for="student-id">Student ID</label>
        <input id="student-id" name="student_id" type="search" maxlength="30" value="<?= escape($studentId) ?>">
    </div>
    <div class="field">
        <label for="equipment">Equipment</label>
        <select id="equipment" name="equipment_id">
            <option value="">All equipment</option>
            <?php foreach ($equipmentOptions as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= $equipmentId === (int) $item['id'] ? 'selected' : '' ?>>
                    <?= escape($item['name']) ?> · <?= escape($item['equipment_code']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
            <?php foreach ($statusOptions as $value => $label): ?>
                <option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>><?= escape($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="button" type="submit">Filter</button>
    <button class="button" type="submit" name="export" value="csv">Download CSV</button>
</form>

<?php if ($requests === []): ?>
    <p class="panel">No borrowing requests match these filters.</p>
<?php else: ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="Borrowing requests and history">
        <table>
            <thead>
                <tr>
                    <th scope="col">Request</th>
                    <th scope="col">Student</th>
                    <th scope="col">Equipment</th>
                    <th scope="col">Dates</th>
                    <th scope="col">Status</th>
                    <th scope="col">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <th scope="row">#<?= (int) $request['id'] ?></th>
                        <td>
                            <strong><?= escape($request['student_name']) ?></strong>
                            <small class="table-note"><?= escape($request['student_id']) ?> · <?= escape($request['department']) ?></small>
                            <small class="table-note"><?= escape($request['email']) ?></small>
                        </td>
                        <td>
                            <?= escape($request['equipment_name']) ?> × <?= (int) $request['quantity'] ?>
                            <small class="table-note"><?= escape($request['equipment_code']) ?></small>
                            <?php if ($request['asset_tags'] !== null): ?>
                                <small class="table-note">Assets <?= escape($request['asset_tags']) ?></small>
                            <?php endif; ?>
                            <p class="request-reason"><?= escape($request['reason']) ?></p>
                        </td>
                        <td>
                            <div>Requested <time><?= escape($request['requested_on']) ?></time></div>
                            <div>Preferred <time><?= escape($request['preferred_date']) ?></time></div>
                            <?php if ($request['collection_deadline'] !== null): ?>
                                <div>Collect by <time><?= escape($request['collection_deadline']) ?></time></div>
                            <?php endif; ?>
                            <?php if ($request['issue_date'] !== null): ?>
                                <div>Issued <time><?= escape($request['issue_date']) ?></time></div>
                            <?php endif; ?>
                            <?php if ($request['due_date'] !== null): ?>
                                <div>Due <time><?= escape($request['due_date']) ?></time></div>
                            <?php endif; ?>
                            <?php if ($request['extension_status'] !== null): ?>
                                <p class="request-reason">
                                    Extension <?= escape($request['extension_status']) ?>:
                                    <time><?= escape($request['extension_due_date']) ?></time>
                                </p>
                                <p class="request-reason"><?= escape($request['extension_reason']) ?></p>
                                <?php if ($request['extension_review_note'] !== null): ?>
                                    <p class="request-reason"><?= escape($request['extension_review_note']) ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($request['return_date'] !== null): ?>
                                <div>Returned <time><?= escape($request['return_date']) ?></time></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status status-<?= escape($request['status']) ?>"><?= escape($request['status'] === 'returned' && $request['has_missing'] ? 'Closed with missing units' : $statusOptions[$request['status']]) ?></span>
                            <?php if ($request['status'] === 'rejected'): ?>
                                <p class="request-reason"><?= escape($request['rejection_reason'] ?? 'No reason recorded.') ?></p>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (in_array($request['status'], ['pending', 'waitlisted', 'reserved'], true)): ?>
                                <form method="post" class="loan-action">
                                    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <small><?= (int) $request['available_quantity'] ?> available</small>
                                    <?php if ($request['is_under_maintenance']): ?>
                                        <span class="status status-maintenance">Under maintenance</span>
                                    <?php endif; ?>
                                    <label for="due-<?= (int) $request['id'] ?>">Return due date</label>
                                    <input id="due-<?= (int) $request['id'] ?>" name="due_date" type="date"
                                           min="<?= escape($today) ?>" required
                                           value="<?= (int) $request['id'] === $requestId ? escape($dueDate) : '' ?>">
                                    <label for="issue-condition-<?= (int) $request['id'] ?>">Condition at issue</label>
                                    <textarea id="issue-condition-<?= (int) $request['id'] ?>" name="issue_condition" rows="2" maxlength="1000" required></textarea>
                                    <?php if ($request['tracks_assets']): ?>
                                        <label for="issue-assets-<?= (int) $request['id'] ?>">Asset tags, separated by commas</label>
                                        <input id="issue-assets-<?= (int) $request['id'] ?>" name="asset_tags" required>
                                        <a href="equipment.php?mode=assets&amp;id=<?= (int) $request['equipment_id'] ?>">View tagged units in storage</a>
                                    <?php endif; ?>
                                    <?php if ($request['status'] === 'reserved'): ?>
                                        <button class="button" type="submit" name="decision" value="collect">Confirm collection</button>
                                    <?php else: ?>
                                        <button class="button" type="submit" name="decision" value="approve">Approve and issue</button>
                                        <button class="button" type="submit" name="decision" value="reserve" formnovalidate>Reserve for pickup</button>
                                    <?php endif; ?>
                                </form>
                                <?php if ($request['status'] !== 'reserved'): ?>
                                    <form method="post" class="loan-action rejection-form">
                                        <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                        <label for="rejection-<?= (int) $request['id'] ?>">Rejection reason</label>
                                        <textarea id="rejection-<?= (int) $request['id'] ?>" name="rejection_reason"
                                                  rows="3" maxlength="1000" required><?= (int) $request['id'] === $requestId ? escape($rejectionReason) : '' ?></textarea>
                                        <button class="button button-danger" type="submit" name="decision" value="reject">Reject request</button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif (in_array($request['status'], ['borrowed', 'returned'], true)): ?>
                                <a href="requests.php?slip=<?= (int) $request['id'] ?>">Borrowing slip</a>
                                <?php if ($request['status'] === 'borrowed' && $request['extension_status'] === 'pending'): ?>
                                    <form method="post" class="loan-action rejection-form">
                                        <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="extension">
                                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                        <input type="hidden" name="extension_version" value="<?= (int) $request['extension_version'] ?>">
                                        <label for="extension-note-<?= (int) $request['id'] ?>">Review note (required for rejection)</label>
                                        <textarea id="extension-note-<?= (int) $request['id'] ?>" name="extension_review_note"
                                                  rows="3" maxlength="1000"></textarea>
                                        <button class="button" type="submit" name="decision" value="approve">Approve extension</button>
                                        <button class="button button-danger" type="submit" name="decision" value="reject">Reject extension</button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
