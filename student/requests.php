<?php

require_once __DIR__ . '/../includes/auth.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $requestId = filter_var(formValue('request_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($requestId !== false) {
        $cancel = $database->prepare(
            "UPDATE borrow_requests SET status = 'cancelled'
             WHERE id = ? AND user_id = ? AND status = 'pending'"
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

    $errors[] = 'This request could not be cancelled. Only your pending requests can be cancelled.';
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
    "SELECT r.id, r.equipment_id, r.quantity, r.status, DATE(r.request_date) AS requested_on,
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
                            <?php if ($request['is_overdue']): ?>
                                <span class="status status-overdue">Overdue</span>
                            <?php else: ?>
                                <span class="status status-<?= escape($request['status']) ?>"><?= escape(ucfirst($request['status'])) ?></span>
                            <?php endif; ?>
                            <?php if ($request['status'] === 'rejected'): ?>
                                <p class="request-reason"><?= escape($request['rejection_reason'] ?? 'No reason recorded.') ?></p>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($request['status'] === 'pending'): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <button class="button button-danger" type="submit"
                                            aria-label="Cancel request <?= (int) $request['id'] ?>">Cancel</button>
                                </form>
                            <?php elseif (in_array($request['status'], ['borrowed', 'returned'], true)): ?>
                                <a href="requests.php?slip=<?= (int) $request['id'] ?>">Borrowing slip</a>
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
