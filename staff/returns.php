<?php

require_once __DIR__ . '/../includes/auth.php';

function recordReturn(PDO $database, int $requestId, int $returnedQuantity): void
{
    $lookup = $database->prepare('SELECT equipment_id FROM borrow_requests WHERE id = ?');
    $lookup->execute([$requestId]);
    $equipmentId = $lookup->fetchColumn();

    if ($equipmentId === false) {
        throw new DomainException('Only active loans can be returned.');
    }

    $database->beginTransaction();

    try {
        // Follow the same lock order as approval and inventory removal.
        $stock = $database->prepare('SELECT total_quantity, available_quantity FROM equipment WHERE id = ? FOR UPDATE');
        $stock->execute([$equipmentId]);
        $item = $stock->fetch();

        $loan = $database->prepare(
            "SELECT quantity FROM borrow_requests
             WHERE id = ? AND equipment_id = ? AND status = 'borrowed' FOR UPDATE"
        );
        $loan->execute([$requestId, $equipmentId]);
        $issuedQuantity = $loan->fetchColumn();

        if ($issuedQuantity === false) {
            throw new DomainException('Only active loans can be returned.');
        }
        if ($returnedQuantity !== (int) $issuedQuantity) {
            throw new DomainException("Enter the full issued quantity of {$issuedQuantity} to confirm the return.");
        }
        if ($item === false || $returnedQuantity > $item['total_quantity'] - $item['available_quantity']) {
            throw new DomainException('The current stock count does not allow this return. Check the equipment inventory.');
        }

        $restock = $database->prepare('UPDATE equipment SET available_quantity = available_quantity + ? WHERE id = ?');
        $restock->execute([$returnedQuantity, $equipmentId]);

        $returned = $database->prepare(
            "UPDATE borrow_requests SET status = 'returned', return_date = CURRENT_DATE() WHERE id = ?"
        );
        $returned->execute([$requestId]);

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
$pageTitle = 'Returns';
$database = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $requestId = filter_var(formValue('request_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $quantity = filter_var(formValue('returned_quantity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($requestId === false || $quantity === false) {
        $errors[] = 'Choose an active loan and enter a whole returned quantity of at least 1.';
    } else {
        try {
            recordReturn($database, $requestId, $quantity);
            $_SESSION['notice'] = 'Return recorded. The units are available again.';
            redirect('returns.php');
        } catch (DomainException $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

$studentId = trim(queryValue('student_id'));
$view = queryValue('view') === 'overdue' ? 'overdue' : 'all';
$loans = [];

if ($studentId !== '' && !validText($studentId, 30)) {
    $errors[] = 'Search using a student ID of up to 30 characters.';
} else {
    $statement = $database->prepare(
        "SELECT r.id, r.quantity, r.due_date, DATEDIFF(CURRENT_DATE(), r.due_date) AS days_overdue,
                u.name AS student_name, u.student_id, e.name AS equipment_name, e.equipment_code
         FROM borrow_requests r
         JOIN users u ON r.user_id = u.id
         JOIN equipment e ON r.equipment_id = e.id
         WHERE r.status = 'borrowed' AND LOCATE(?, u.student_id) > 0
           AND (? = 'all' OR r.due_date < CURRENT_DATE())
         ORDER BY r.due_date IS NULL, r.due_date, r.id"
    );
    $statement->execute([$studentId, $view]);
    $loans = $statement->fetchAll();
}

require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading page-actions">
    <h1>Equipment returns</h1>
    <a href="requests.php?status=returned">View return history</a>
</div>

<form method="get" class="panel filter-form">
    <div class="field">
        <label for="student-id">Student ID</label>
        <input id="student-id" name="student_id" type="search" maxlength="30" value="<?= escape($studentId) ?>">
    </div>
    <div class="field">
        <label for="view">Show</label>
        <select id="view" name="view">
            <option value="all" <?= $view === 'all' ? 'selected' : '' ?>>All active loans</option>
            <option value="overdue" <?= $view === 'overdue' ? 'selected' : '' ?>>Overdue only</option>
        </select>
    </div>
    <button class="button" type="submit">Filter</button>
</form>

<?php if ($loans === []): ?>
    <p class="panel"><?= $view === 'overdue' ? 'No overdue loans match these filters.' : 'No active loans match these filters.' ?></p>
<?php else: ?>
    <div class="table-scroll" tabindex="0" role="region" aria-label="Active equipment loans">
        <table>
            <thead>
                <tr>
                    <th scope="col">Request</th>
                    <th scope="col">Student</th>
                    <th scope="col">Equipment</th>
                    <th scope="col">Issued quantity</th>
                    <th scope="col">Due date</th>
                    <th scope="col">Return</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($loans as $loan): ?>
                    <tr>
                        <th scope="row">#<?= (int) $loan['id'] ?></th>
                        <td>
                            <?= escape($loan['student_name']) ?>
                            <small class="table-note"><?= escape($loan['student_id']) ?></small>
                        </td>
                        <td>
                            <?= escape($loan['equipment_name']) ?>
                            <small class="table-note"><?= escape($loan['equipment_code']) ?></small>
                        </td>
                        <td><?= (int) $loan['quantity'] ?></td>
                        <td>
                            <?php if ($loan['due_date'] !== null): ?>
                                <time><?= escape($loan['due_date']) ?></time>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                            <?php if ($loan['days_overdue'] > 0): ?>
                                <div class="overdue-note">
                                    <span class="status status-overdue">
                                        <?= (int) $loan['days_overdue'] ?>
                                        <?= (int) $loan['days_overdue'] === 1 ? 'day' : 'days' ?> overdue
                                    </span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" class="loan-action">
                                <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                <input type="hidden" name="request_id" value="<?= (int) $loan['id'] ?>">
                                <label for="quantity-<?= (int) $loan['id'] ?>">Returned quantity</label>
                                <input id="quantity-<?= (int) $loan['id'] ?>" name="returned_quantity" type="number"
                                       min="<?= (int) $loan['quantity'] ?>" max="<?= (int) $loan['quantity'] ?>" required>
                                <button class="button" type="submit">Confirm return</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
