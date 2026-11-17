<?php

require_once __DIR__ . '/../includes/auth.php';

$user = requireRole('staff');
$basePath = '../';
$pageTitle = 'Dashboard';
$database = db();

$inventory = $database->query(
    'SELECT COUNT(*) AS equipment_types, COALESCE(SUM(total_quantity), 0) AS total_units
     FROM equipment WHERE is_active = 1'
)->fetch();

$lending = $database->query(
    "SELECT COALESCE(SUM(CASE WHEN status = 'borrowed' THEN quantity ELSE 0 END), 0) AS borrowed_units,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_requests,
            COUNT(CASE WHEN status = 'borrowed' AND due_date < CURRENT_DATE() THEN 1 END) AS overdue_loans
     FROM borrow_requests"
)->fetch();

$summary = [
    'Active equipment types' => $inventory['equipment_types'],
    'Total equipment units' => $inventory['total_units'],
    'Borrowed units' => $lending['borrowed_units'],
    'Pending requests' => $lending['pending_requests'],
    'Overdue loans' => $lending['overdue_loans'],
];

$recentReturns = $database->query(
    "SELECT r.id, r.quantity, r.return_date, u.name AS student_name, u.student_id,
            e.name AS equipment_name, e.equipment_code
     FROM borrow_requests r
     JOIN users u ON r.user_id = u.id
     JOIN equipment e ON r.equipment_id = e.id
     WHERE r.status = 'returned' AND r.return_date IS NOT NULL
     ORDER BY r.return_date DESC, r.id DESC
     LIMIT 5"
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading page-actions dashboard-heading">
    <div>
        <h1>Staff dashboard</h1>
        <p class="dashboard-welcome">Welcome, <?= escape($user['name']) ?>.</p>
        <p class="dashboard-meta"><?= escape($user['department']) ?> · <?= escape($user['email']) ?></p>
    </div>
    <a class="button" href="requests.php">Review requests</a>
</div>

<dl class="dashboard-stats" aria-label="Inventory and lending summary">
    <?php foreach ($summary as $label => $value): ?>
        <div class="stat-card">
            <dt><?= escape($label) ?></dt>
            <dd><?= number_format((int) $value) ?></dd>
        </div>
    <?php endforeach; ?>
</dl>

<section aria-labelledby="return-title">
    <div class="page-actions dashboard-section-heading">
        <h2 id="return-title">Recently returned equipment</h2>
        <a href="requests.php?status=returned">View return history</a>
    </div>

    <?php if ($recentReturns === []): ?>
        <p class="panel dashboard-empty">No equipment has been returned yet.</p>
    <?php else: ?>
        <div class="table-scroll dashboard-table" tabindex="0" role="region" aria-labelledby="return-title">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Request</th>
                        <th scope="col">Student</th>
                        <th scope="col">Equipment</th>
                        <th scope="col">Quantity</th>
                        <th scope="col">Returned on</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentReturns as $return): ?>
                        <tr>
                            <th scope="row">#<?= (int) $return['id'] ?></th>
                            <td>
                                <?= escape($return['student_name']) ?>
                                <small class="table-note"><?= escape($return['student_id']) ?></small>
                            </td>
                            <td>
                                <?= escape($return['equipment_name']) ?>
                                <small class="table-note"><?= escape($return['equipment_code']) ?></small>
                            </td>
                            <td><?= (int) $return['quantity'] ?></td>
                            <td><time><?= escape($return['return_date']) ?></time></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
