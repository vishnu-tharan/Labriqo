<?php

require_once __DIR__ . '/../includes/auth.php';

function approveRequest(PDO $database, int $requestId, string $dueDate): void
{
    $lookup = $database->prepare('SELECT equipment_id FROM borrow_requests WHERE id = ?');
    $lookup->execute([$requestId]);
    $equipmentId = $lookup->fetchColumn();

    if ($equipmentId === false) {
        throw new DomainException('Only pending requests can be approved.');
    }

    $database->beginTransaction();

    try {
        // Lock equipment first so approvals, returns, and inventory edits use the same order.
        $stock = $database->prepare('SELECT available_quantity, is_active FROM equipment WHERE id = ? FOR UPDATE');
        $stock->execute([$equipmentId]);
        $item = $stock->fetch();

        $pending = $database->prepare(
            "SELECT quantity, CURRENT_DATE() AS today FROM borrow_requests
             WHERE id = ? AND equipment_id = ? AND status = 'pending' FOR UPDATE"
        );
        $pending->execute([$requestId, $equipmentId]);
        $request = $pending->fetch();

        if ($request === false) {
            throw new DomainException('Only pending requests can be approved.');
        }
        if ($item === false || !$item['is_active']) {
            throw new DomainException('This equipment is no longer active.');
        }
        if ($dueDate < $request['today']) {
            throw new DomainException('Choose a return due date of today or later.');
        }
        if ($request['quantity'] > $item['available_quantity']) {
            throw new DomainException('There is not enough available stock to issue this request.');
        }

        $deduct = $database->prepare('UPDATE equipment SET available_quantity = available_quantity - ? WHERE id = ?');
        $deduct->execute([$request['quantity'], $equipmentId]);

        $issue = $database->prepare(
            "UPDATE borrow_requests SET status = 'borrowed', approval_date = ?, issue_date = ?, due_date = ?
             WHERE id = ?"
        );
        $issue->execute([$request['today'], $request['today'], $dueDate, $requestId]);

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
$errors = [];
$requestId = false;
$dueDate = '';
$rejectionReason = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $requestId = filter_var(formValue('request_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $decision = formValue('decision');
    $dueDate = formValue('due_date');
    $rejectionReason = trim(formValue('rejection_reason'));

    if ($requestId === false || !in_array($decision, ['approve', 'reject'], true)) {
        $errors[] = 'Choose a valid request and decision.';
    } elseif ($decision === 'approve' && !validDate($dueDate)) {
        $errors[] = 'Choose a valid return due date.';
    } elseif ($decision === 'reject' && !validText($rejectionReason, 1000)) {
        $errors[] = 'Enter a rejection reason of up to 1,000 characters.';
    } else {
        try {
            if ($decision === 'approve') {
                approveRequest($database, $requestId, $dueDate);
                $_SESSION['notice'] = 'Request approved and equipment issued.';
            } else {
                $reject = $database->prepare(
                    "UPDATE borrow_requests SET status = 'rejected', rejection_reason = ?
                     WHERE id = ? AND status = 'pending'"
                );
                $reject->execute([$rejectionReason, $requestId]);

                if ($reject->rowCount() !== 1) {
                    throw new DomainException('Only pending requests can be rejected.');
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
    'pending' => 'Pending',
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
        "SELECT r.id, r.status, r.quantity, r.reason, DATE(r.request_date) AS requested_on,
                r.preferred_date, r.approval_date, r.issue_date, r.due_date, r.return_date, r.rejection_reason,
                u.name AS student_name, u.student_id, u.department, u.email,
                e.name AS equipment_name, e.equipment_code, e.available_quantity
         FROM borrow_requests r
         JOIN users u ON r.user_id = u.id
         JOIN equipment e ON r.equipment_id = e.id
         WHERE LOCATE(?, u.student_id) > 0
           AND (? IS NULL OR r.equipment_id = ?)
           AND (? = 'all' OR r.status = ?)
         ORDER BY r.request_date DESC, r.id DESC"
    );
    $statement->execute([$studentId, $equipmentFilter, $equipmentFilter, $status, $status]);
    $requests = $statement->fetchAll();
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
        'quantity' => 'Quantity',
        'reason' => 'Borrowing reason',
        'status' => 'Status',
        'requested_on' => 'Request date',
        'preferred_date' => 'Preferred date',
        'approval_date' => 'Approval date',
        'issue_date' => 'Issue date',
        'due_date' => 'Due date',
        'return_date' => 'Return date',
        'rejection_reason' => 'Rejection reason',
    ], $requests);
}

require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading page-actions">
    <h1>Borrowing requests</h1>
    <a href="returns.php">View active loans and returns</a>
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
                            <p class="request-reason"><?= escape($request['reason']) ?></p>
                        </td>
                        <td>
                            <div>Requested <time><?= escape($request['requested_on']) ?></time></div>
                            <div>Preferred <time><?= escape($request['preferred_date']) ?></time></div>
                            <?php if ($request['issue_date'] !== null): ?>
                                <div>Issued <time><?= escape($request['issue_date']) ?></time></div>
                            <?php endif; ?>
                            <?php if ($request['due_date'] !== null): ?>
                                <div>Due <time><?= escape($request['due_date']) ?></time></div>
                            <?php endif; ?>
                            <?php if ($request['return_date'] !== null): ?>
                                <div>Returned <time><?= escape($request['return_date']) ?></time></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status status-<?= escape($request['status']) ?>"><?= escape($statusOptions[$request['status']]) ?></span>
                            <?php if ($request['status'] === 'rejected'): ?>
                                <p class="request-reason"><?= escape($request['rejection_reason'] ?? 'No reason recorded.') ?></p>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($request['status'] === 'pending'): ?>
                                <form method="post" class="loan-action">
                                    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <small><?= (int) $request['available_quantity'] ?> available</small>
                                    <label for="due-<?= (int) $request['id'] ?>">Return due date</label>
                                    <input id="due-<?= (int) $request['id'] ?>" name="due_date" type="date"
                                           min="<?= escape($today) ?>" required
                                           value="<?= (int) $request['id'] === $requestId ? escape($dueDate) : '' ?>">
                                    <button class="button" type="submit" name="decision" value="approve">Approve and issue</button>
                                </form>
                                <form method="post" class="loan-action rejection-form">
                                    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <label for="rejection-<?= (int) $request['id'] ?>">Rejection reason</label>
                                    <textarea id="rejection-<?= (int) $request['id'] ?>" name="rejection_reason"
                                              rows="3" maxlength="1000" required><?= (int) $request['id'] === $requestId ? escape($rejectionReason) : '' ?></textarea>
                                    <button class="button button-danger" type="submit" name="decision" value="reject">Reject request</button>
                                </form>
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
