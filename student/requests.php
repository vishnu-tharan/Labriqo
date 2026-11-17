<?php

require_once __DIR__ . '/../includes/auth.php';

$user = requireRole('student');
$basePath = '../';
$pageTitle = 'My requests';
$database = db();
$errors = [];

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
            redirect('requests.php');
        }
    }

    $errors[] = 'This request could not be cancelled. Only your pending requests can be cancelled.';
}

$statement = $database->prepare(
    'SELECT r.id, r.equipment_id, r.quantity, r.status, DATE(r.request_date) AS requested_on,
            r.preferred_date, r.issue_date, r.due_date, r.return_date, e.name, e.equipment_code, e.is_active
     FROM borrow_requests r JOIN equipment e ON r.equipment_id = e.id
     WHERE r.user_id = ?
     ORDER BY r.request_date DESC, r.id DESC'
);
$statement->execute([$user['id']]);
$requests = $statement->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading page-actions">
    <h1>My requests</h1>
    <a class="button" href="equipment.php">Browse equipment</a>
</div>

<?php if ($requests === []): ?>
    <p class="panel">You have no borrowing requests yet.</p>
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
                        <td><span class="status status-<?= escape($request['status']) ?>"><?= escape(ucfirst($request['status'])) ?></span></td>
                        <td>
                            <?php if ($request['status'] === 'pending'): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <button class="button button-danger" type="submit"
                                            aria-label="Cancel request <?= (int) $request['id'] ?>">Cancel</button>
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
