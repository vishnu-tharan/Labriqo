<?php

require_once __DIR__ . '/../includes/auth.php';

function recordReturn(PDO $database, int $requestId, int $returnedQuantity, string $condition, string $assetTags, int $expectedSettled, int $staffId, string $outcome): void
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
        $stock = $database->prepare('SELECT total_quantity, available_quantity, damaged_quantity, tracks_assets FROM equipment WHERE id = ? FOR UPDATE');
        $stock->execute([$equipmentId]);
        $item = $stock->fetch();

        $loan = $database->prepare(
            "SELECT quantity, settled_quantity FROM borrow_requests
             WHERE id = ? AND equipment_id = ? AND status = 'borrowed' FOR UPDATE"
        );
        $loan->execute([$requestId, $equipmentId]);
        $issued = $loan->fetch();

        if ($issued === false) {
            throw new DomainException('Only active loans can be returned.');
        }
        $settled = (int) $issued['settled_quantity'];
        if ($settled !== $expectedSettled) {
            throw new DomainException('This loan changed since the form was opened. Review the remaining units and try again.');
        }
        $remaining = (int) $issued['quantity'] - $settled;
        if ($returnedQuantity > $remaining) {
            throw new DomainException("Only {$remaining} units remain on this loan.");
        }
        if ($item === false || $returnedQuantity > $item['total_quantity'] - $item['available_quantity'] - $item['damaged_quantity']) {
            throw new DomainException('The current stock count does not allow this return. Check the equipment inventory.');
        }

        $confirmed = [];
        if ($item['tracks_assets']) {
            $issued = $database->prepare(
                'SELECT asset_tag FROM equipment_assets WHERE equipment_id = ? AND current_request_id = ?'
            );
            $issued->execute([$equipmentId, $requestId]);
            $expected = $issued->fetchAll(PDO::FETCH_COLUMN);
            $confirmed = array_map('trim', explode(',', $assetTags));
            if (count($confirmed) !== $returnedQuantity || count(array_unique($confirmed)) !== $returnedQuantity
                || array_diff($confirmed, $expected) !== []) {
                throw new DomainException('Enter one distinct tag per unit from the tags still on this loan.');
            }

            $release = $database->prepare(
                'UPDATE equipment_assets SET current_request_id = NULL, is_damaged = ?,
                 retired_at = CASE WHEN ? THEN CURRENT_TIMESTAMP() ELSE retired_at END
                 WHERE equipment_id = ? AND current_request_id = ? AND asset_tag = ?'
            );
            foreach ($confirmed as $tag) {
                $release->execute([(int) ($outcome === 'damaged'), (int) ($outcome === 'missing'), $equipmentId, $requestId, $tag]);
                if ($release->rowCount() !== 1) {
                    throw new DomainException('The tagged assets do not match the current loan.');
                }
            }
        }

        $stockChange = $database->prepare(
            'UPDATE equipment SET available_quantity = available_quantity + ?, damaged_quantity = damaged_quantity + ?,
             total_quantity = total_quantity - ? WHERE id = ?'
        );
        $stockChange->execute([
            $outcome === 'returned' ? $returnedQuantity : 0,
            $outcome === 'damaged' ? $returnedQuantity : 0,
            $outcome === 'missing' ? $returnedQuantity : 0,
            $equipmentId,
        ]);
        if ($outcome === 'missing') {
            $before = (int) $item['total_quantity'];
            recordStockAdjustment($database, $equipmentId, $staffId, $before, $before - $returnedQuantity, "Missing from loan #{$requestId}");
        }

        $event = $database->prepare(
            "INSERT INTO loan_settlements (request_id, staff_id, quantity, outcome, condition_note, asset_tags)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $event->execute([$requestId, $staffId, $returnedQuantity, $outcome, $condition, $confirmed === [] ? null : implode(', ', $confirmed)]);
        $progress = $database->prepare('UPDATE borrow_requests SET settled_quantity = ?, return_condition = ? WHERE id = ?');
        $progress->execute([$settled + $returnedQuantity, $condition, $requestId]);

        if ($returnedQuantity === $remaining) {
            $close = $database->prepare(
                "UPDATE borrow_requests SET status = 'returned', return_date = CURRENT_DATE() WHERE id = ?"
            );
            $close->execute([$requestId]);
        }

        $closeExtension = $database->prepare(
            "UPDATE borrow_requests SET extension_status = 'cancelled', extension_decided_at = CURRENT_TIMESTAMP(),
             extension_review_note = 'Loan closed before extension review.'
             WHERE id = ? AND status = 'returned' AND extension_status = 'pending'"
        );
        $closeExtension->execute([$requestId]);

        $action = match ($outcome) {
            'damaged' => 'Damaged return recorded',
            'missing' => 'Missing units recorded',
            default => 'Return recorded',
        };
        recordStaffActivity($database, $staffId, $action, 'Loan', $requestId, "{$returnedQuantity} units");
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

    $condition = trim(formValue('return_condition'));
    $outcome = formValue('outcome');
    $expectedSettled = filter_var(formValue('settled_quantity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($requestId === false || $quantity === false || $expectedSettled === false) {
        $errors[] = 'Choose an active loan and enter a whole returned quantity of at least 1.';
    } elseif (!in_array($outcome, ['returned', 'damaged', 'missing'], true)) {
        $errors[] = 'Choose a valid outcome.';
    } elseif (!validText($condition, 1000)) {
        $errors[] = 'Enter the condition at return, up to 1,000 characters.';
    } else {
        try {
            recordReturn($database, $requestId, $quantity, $condition, formValue('asset_tags'), $expectedSettled, $user['id'], $outcome);
            $_SESSION['notice'] = 'Loan update recorded.';
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
        "SELECT r.id, r.quantity - r.settled_quantity AS quantity, r.settled_quantity, r.due_date, DATEDIFF(CURRENT_DATE(), r.due_date) AS days_overdue,
                u.name AS student_name, u.student_id, e.name AS equipment_name, e.equipment_code,
                (SELECT GROUP_CONCAT(a.asset_tag ORDER BY a.asset_tag SEPARATOR ', ')
                 FROM equipment_assets a WHERE a.current_request_id = r.id) AS asset_tags
         FROM borrow_requests r
         JOIN users u ON r.user_id = u.id
         JOIN equipment e ON r.equipment_id = e.id
         WHERE r.status = 'borrowed' AND LOCATE(?, u.student_id) > 0
           AND (? = 'all' OR r.due_date < CURRENT_DATE())
         ORDER BY r.due_date IS NULL, r.due_date, r.id"
    );
    $pagination = paginate($database, $statement, [$studentId, $view]);
    $loans = $pagination['records'];
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
                    <th scope="col">Units outstanding</th>
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
                            <?php if ($loan['asset_tags'] !== null): ?>
                                <small class="table-note">Verify tags <?= escape($loan['asset_tags']) ?></small>
                            <?php endif; ?>
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
                                <input type="hidden" name="settled_quantity" value="<?= (int) $loan['settled_quantity'] ?>">
                                <label for="quantity-<?= (int) $loan['id'] ?>">Units to record</label>
                                <input id="quantity-<?= (int) $loan['id'] ?>" name="returned_quantity" type="number"
                                       min="1" max="<?= (int) $loan['quantity'] ?>" required>
                                <label for="outcome-<?= (int) $loan['id'] ?>">Outcome</label>
                                <select id="outcome-<?= (int) $loan['id'] ?>" name="outcome">
                                    <option value="returned">Returned, ready to lend</option>
                                    <option value="damaged">Returned, damaged</option>
                                    <option value="missing">Missing</option>
                                </select>
                                <label for="return-condition-<?= (int) $loan['id'] ?>">Condition or reason</label>
                                <textarea id="return-condition-<?= (int) $loan['id'] ?>" name="return_condition" rows="2" maxlength="1000" required></textarea>
                                <?php if ($loan['asset_tags'] !== null): ?>
                                    <label for="return-assets-<?= (int) $loan['id'] ?>">Asset tags for this outcome, separated by commas</label>
                                    <input id="return-assets-<?= (int) $loan['id'] ?>" name="asset_tags" required>
                                <?php endif; ?>
                                <button class="button" type="submit">Record outcome</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
