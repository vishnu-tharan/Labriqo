<?php

require_once __DIR__ . '/../includes/auth.php';

function requestExtension(PDO $database, int $studentId, int $requestId, string $date, string $reason): void
{
    if (!validDate($date) || !validText($reason, 1000)) {
        throw new DomainException('Enter a valid requested due date and a reason of up to 1,000 characters.');
    }

    $database->beginTransaction();
    try {
        $statement = $database->prepare(
            "SELECT due_date, extension_status, CURRENT_DATE() AS today FROM borrow_requests
             WHERE id = ? AND user_id = ? AND status = 'borrowed' FOR UPDATE"
        );
        $statement->execute([$requestId, $studentId]);
        $loan = $statement->fetch();

        if ($loan === false || $loan['due_date'] === null) {
            throw new DomainException('Only your active loans with a due date can be extended.');
        }
        if ($loan['extension_status'] === 'pending') {
            throw new DomainException('This loan already has an extension awaiting review.');
        }
        if ($date <= $loan['due_date'] || $date < $loan['today']) {
            throw new DomainException('Choose a date later than the current due date and today or later.');
        }

        $update = $database->prepare(
            "UPDATE borrow_requests
             SET extension_due_date = ?, extension_reason = ?, extension_status = 'pending',
                 extension_version = extension_version + 1, extension_requested_at = CURRENT_TIMESTAMP(),
                 extension_decided_at = NULL, extension_review_note = NULL
             WHERE id = ?"
        );
        $update->execute([$date, $reason, $requestId]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

$user = requireRole('student');
$basePath = '../';
$pageTitle = 'My requests';
$database = db();

if (isset($_GET['slip'])) {
    $borrowingSlip = getBorrowingSlip($database, $user, queryValue('slip'));
    $pageTitle = 'Borrowing slip';
    require __DIR__ . '/../includes/header.php';
    require __DIR__ . '/../includes/footer.php';
    exit;
}
$errors = [];

$statusOptions = [
    'all' => 'All statuses',
    'pending' => 'Pending',
    'waitlisted' => 'Waitlisted',
    'reserved' => 'Awaiting collection',
    'expired' => 'Collection expired',
    'borrowed' => 'Borrowed',
    'overdue' => 'Overdue',
    'returned' => 'Returned',
    'rejected' => 'Rejected',
    'cancelled' => 'Cancelled',
];
$status = queryValue('status');
if (!isset($statusOptions[$status])) {
    $status = 'all';
}
$equipmentId = filter_var(queryValue('equipment_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$equipmentFilter = $equipmentId === false ? null : $equipmentId;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && formValue('action') === 'extension') {
    checkCsrf();
    $requestId = filter_var(formValue('request_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    try {
        if ($requestId === false) {
            throw new DomainException('Choose a valid active loan.');
        }
        requestExtension($database, $user['id'], $requestId, formValue('extension_due_date'), trim(formValue('extension_reason')));
        $_SESSION['notice'] = 'Extension requested. The current due date applies until staff approve it.';
        redirect('requests.php?' . http_build_query(['status' => $status, 'equipment_id' => $equipmentFilter, 'page' => queryValue('page')]));
    } catch (DomainException $exception) {
        $errors[] = $exception->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && formValue('action') !== 'extension') {
    checkCsrf();
    $requestId = filter_var(formValue('request_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($requestId !== false) {
        $cancel = $database->prepare(
            "UPDATE borrow_requests SET status = 'cancelled'
             WHERE id = ? AND user_id = ? AND status IN ('pending', 'waitlisted')"
        );
        $cancel->execute([$requestId, $user['id']]);

        if ($cancel->rowCount() === 1) {
            $_SESSION['notice'] = 'Request cancelled.';
            $page = filter_var(queryValue('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            redirect('requests.php?' . http_build_query([
                'status' => $status,
                'equipment_id' => $equipmentFilter,
                'page' => $page === false ? 1 : $page,
            ]));
        }
    }

    $errors[] = 'This request could not be cancelled. Only your pending or waitlisted requests can be cancelled.';
}

$equipmentStatement = $database->prepare(
    'SELECT DISTINCT e.id, e.name, e.equipment_code
     FROM equipment e JOIN borrow_requests r ON r.equipment_id = e.id
     WHERE r.user_id = ?
     ORDER BY e.name, e.equipment_code'
);
$equipmentStatement->execute([$user['id']]);
$equipmentOptions = $equipmentStatement->fetchAll();

$statement = $database->prepare(
    "SELECT r.id, r.equipment_id, r.quantity, r.status, r.collection_deadline,
            (SELECT COUNT(*) + 1 FROM borrow_requests w WHERE w.equipment_id = r.equipment_id
                AND w.status = 'waitlisted' AND w.id < r.id) AS queue_position,
            DATE(r.request_date) AS requested_on,
            r.extension_due_date, r.extension_reason, r.extension_status, r.extension_review_note,
            r.preferred_date, r.issue_date, r.due_date, r.return_date, r.rejection_reason, e.name, e.equipment_code, e.is_active,
            (r.status = 'borrowed' AND r.due_date < CURRENT_DATE()) AS is_overdue
     FROM borrow_requests r JOIN equipment e ON r.equipment_id = e.id
     WHERE r.user_id = ?
       AND (? IS NULL OR r.equipment_id = ?)
       AND (? = 'all' OR r.status = ? OR (? = 'overdue' AND r.status = 'borrowed' AND r.due_date < CURRENT_DATE()))
     ORDER BY r.request_date DESC, r.id DESC"
);
$pagination = paginate($database, $statement, [
    $user['id'], $equipmentFilter, $equipmentFilter, $status, $status, $status,
]);
$requests = $pagination['records'];

require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading page-actions">
    <h1>My requests</h1>
    <a class="button" href="equipment.php">Browse equipment</a>
</div>

<form method="get" class="panel filter-form">
    <div class="field">
        <label for="equipment-filter">Equipment</label>
        <select id="equipment-filter" name="equipment_id">
            <option value="">All equipment</option>
            <?php foreach ($equipmentOptions as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= $equipmentId === (int) $item['id'] ? 'selected' : '' ?>>
                    <?= escape($item['name']) ?> · <?= escape($item['equipment_code']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="status-filter">Status</label>
        <select id="status-filter" name="status">
            <?php foreach ($statusOptions as $value => $label): ?>
                <option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>><?= escape($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="button" type="submit">Filter</button>
    <?php if ($equipmentFilter !== null || $status !== 'all'): ?>
        <a class="filter-reset" href="requests.php">Clear filters</a>
    <?php endif; ?>
</form>

<?php if ($requests === []): ?>
    <p class="panel"><?= $equipmentOptions === [] ? 'You have no borrowing requests yet.' : 'No borrowing requests match these filters.' ?></p>
<?php else: ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="Borrowing requests and history">
        <table>
            <thead>
                <tr>
                    <th scope="col">Request</th>
                    <th scope="col">Equipment</th>
                    <th scope="col">Quantity</th>
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
                            <?php if ($request['is_active']): ?>
                                <a href="equipment.php?id=<?= (int) $request['equipment_id'] ?>"><?= escape($request['name']) ?></a>
                            <?php else: ?>
                                <?= escape($request['name']) ?>
                            <?php endif; ?>
                            <small class="table-note"><?= escape($request['equipment_code']) ?></small>
                        </td>
                        <td><?= (int) $request['quantity'] ?></td>
                        <td>
                            <div>Requested <time><?= escape($request['requested_on']) ?></time></div>
                            <div>Preferred <time><?= escape($request['preferred_date']) ?></time></div>
                            <?php if ($request['collection_deadline'] !== null && in_array($request['status'], ['reserved', 'expired'], true)): ?>
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
                                <?php if ($request['extension_review_note'] !== null): ?>
                                    <p class="request-reason"><?= escape($request['extension_review_note']) ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($request['return_date'] !== null): ?>
                                <div>Returned <time><?= escape($request['return_date']) ?></time></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($request['is_overdue']): ?>
                                <span class="status status-overdue">Overdue</span>
                            <?php else: ?>
                                <span class="status status-<?= escape($request['status']) ?>"><?= escape($statusOptions[$request['status']]) ?></span>
                            <?php endif; ?>
                            <?php if ($request['status'] === 'waitlisted'): ?>
                                <small class="table-note">Position <?= (int) $request['queue_position'] ?></small>
                            <?php endif; ?>
                            <?php if ($request['status'] === 'rejected'): ?>
                                <p class="request-reason"><?= escape($request['rejection_reason'] ?? 'No reason recorded.') ?></p>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (in_array($request['status'], ['pending', 'waitlisted'], true)): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <button class="button button-danger" type="submit"
                                            aria-label="Cancel request <?= (int) $request['id'] ?>">Cancel</button>
                                </form>
                            <?php elseif (in_array($request['status'], ['borrowed', 'returned'], true)): ?>
                                <a href="requests.php?slip=<?= (int) $request['id'] ?>">Borrowing slip</a>
                                <?php if ($request['status'] === 'borrowed' && $request['due_date'] !== null && $request['extension_status'] !== 'pending'): ?>
                                    <form method="post" class="loan-action rejection-form">
                                        <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="extension">
                                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                        <label for="extension-date-<?= (int) $request['id'] ?>">Requested due date</label>
                                        <input id="extension-date-<?= (int) $request['id'] ?>" name="extension_due_date"
                                               type="date" required>
                                        <label for="extension-reason-<?= (int) $request['id'] ?>">Extension reason</label>
                                        <textarea id="extension-reason-<?= (int) $request['id'] ?>" name="extension_reason"
                                                  rows="3" maxlength="1000" required></textarea>
                                        <button class="button" type="submit">Request extension</button>
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
