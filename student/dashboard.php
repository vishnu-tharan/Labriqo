<?php

require_once __DIR__ . '/../includes/auth.php';

$user = requireRole('student');
$basePath = '../';
$pageTitle = 'Dashboard';
$database = db();

$availableTypes = $database->query(
    'SELECT COUNT(*) FROM equipment WHERE is_active = 1 AND available_quantity > 0'
)->fetchColumn();

$statement = $database->prepare(
    "SELECT COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_requests,
            COALESCE(SUM(CASE WHEN status = 'borrowed' THEN quantity ELSE 0 END), 0) AS borrowed_units,
            COUNT(CASE WHEN status = 'borrowed' AND due_date < CURRENT_DATE() THEN 1 END) AS overdue_loans
     FROM borrow_requests
     WHERE user_id = ?"
);
$statement->execute([$user['id']]);
$totals = $statement->fetch();

$summary = [
    'Equipment types in stock' => $availableTypes,
    'Pending requests' => $totals['pending_requests'],
    'Borrowed units' => $totals['borrowed_units'],
    'Overdue loans' => $totals['overdue_loans'],
];

$statement = $database->prepare(
    "SELECT r.id, r.quantity, r.status, r.due_date, DATE(r.request_date) AS requested_on,
            e.name AS equipment_name, e.equipment_code,
            (r.status = 'borrowed' AND r.due_date < CURRENT_DATE()) AS is_overdue
     FROM borrow_requests r JOIN equipment e ON r.equipment_id = e.id
     WHERE r.user_id = ?
     ORDER BY COALESCE(r.return_date, r.issue_date, DATE(r.request_date)) DESC, r.request_date DESC, r.id DESC
     LIMIT 5"
);
$statement->execute([$user['id']]);
$activity = $statement->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading page-actions dashboard-heading">
    <div>
        <h1>Student dashboard</h1>
        <p class="dashboard-welcome">Welcome, <?= escape($user['name']) ?>.</p>
        <p class="dashboard-meta"><?= escape($user['student_id']) ?> · <?= escape($user['department']) ?></p>
    </div>
    <a class="button" href="equipment.php">Browse equipment</a>
</div>

<dl class="dashboard-stats" aria-label="Your borrowing summary">
    <?php foreach ($summary as $label => $value): ?>
        <div class="stat-card">
            <dt><?= escape($label) ?></dt>
            <dd><?= number_format((int) $value) ?></dd>
        </div>
    <?php endforeach; ?>
</dl>

<section aria-labelledby="activity-title">
    <div class="page-actions dashboard-section-heading">
        <h2 id="activity-title">Recent borrowing activity</h2>
        <a href="requests.php">View all my requests</a>
    </div>

    <?php if ($activity === []): ?>
        <p class="panel dashboard-empty">You have no borrowing activity yet.</p>
    <?php else: ?>
        <div class="table-scroll dashboard-table" tabindex="0" role="region" aria-labelledby="activity-title">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Request</th>
                        <th scope="col">Equipment</th>
                        <th scope="col">Quantity</th>
                        <th scope="col">Requested</th>
                        <th scope="col">Due date</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activity as $request): ?>
                        <?php $status = $request['is_overdue'] ? 'overdue' : $request['status']; ?>
                        <tr>
                            <th scope="row">#<?= (int) $request['id'] ?></th>
                            <td>
                                <?= escape($request['equipment_name']) ?>
                                <small class="table-note"><?= escape($request['equipment_code']) ?></small>
                            </td>
                            <td><?= (int) $request['quantity'] ?></td>
                            <td><time><?= escape($request['requested_on']) ?></time></td>
                            <td>
                                <?php if ($request['due_date'] !== null): ?>
                                    <time><?= escape($request['due_date']) ?></time>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td><span class="status status-<?= escape($status) ?>"><?= escape(ucfirst($status)) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
