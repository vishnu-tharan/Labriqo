<?php

require_once __DIR__ . '/../includes/auth.php';

$user = requireRole('student');
$basePath = '../';
$pageTitle = 'Dashboard';
$database = db();

$availableTypes = $database->query(
    'SELECT COUNT(*) FROM equipment WHERE is_active = 1 AND is_under_maintenance = 0 AND available_quantity > 0'
)->fetchColumn();

$statement = $database->prepare(
    "SELECT COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_requests,
            COALESCE(SUM(CASE WHEN status = 'borrowed' THEN quantity - settled_quantity ELSE 0 END), 0) AS borrowed_units,
            COUNT(CASE WHEN status = 'borrowed' AND due_date < CURRENT_DATE() THEN 1 END) AS overdue_loans
     FROM borrow_requests
     WHERE user_id = ?"
);
$statement->execute([$user['id']]);
$totals = $statement->fetch();

$summary = [
    'Equipment types in stock' => [$availableTypes, 'equipment.php?available=1'],
    'Pending requests' => [$totals['pending_requests'], 'requests.php?status=pending'],
    'Borrowed units' => [$totals['borrowed_units'], 'requests.php?status=borrowed'],
    'Overdue loans' => [$totals['overdue_loans'], 'requests.php?status=overdue'],
];

$statement = $database->prepare(
    "SELECT r.id, r.quantity, r.status, r.due_date, (EXISTS (SELECT 1 FROM loan_settlements ls WHERE ls.request_id = r.id AND ls.outcome = 'missing')) AS has_missing, DATE(r.request_date) AS requested_on,
            e.name AS equipment_name, e.equipment_code,
            (r.status = 'borrowed' AND r.due_date < CURRENT_DATE()) AS is_overdue
     FROM borrow_requests r JOIN equipment e ON r.equipment_id = e.id
     WHERE r.user_id = ?
     ORDER BY COALESCE(r.return_date, r.issue_date, DATE(r.request_date)) DESC, r.request_date DESC, r.id DESC
     LIMIT 5"
);
$statement->execute([$user['id']]);
$activity = $statement->fetchAll();

$statement = $database->prepare(
    "SELECT r.id, r.quantity - r.settled_quantity AS quantity, r.due_date, DATEDIFF(r.due_date, CURRENT_DATE()) AS days_remaining,
            e.name AS equipment_name, e.equipment_code
     FROM borrow_requests r JOIN equipment e ON r.equipment_id = e.id
     WHERE r.user_id = ? AND r.status = 'borrowed'
       AND r.due_date BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 3 DAY)
     ORDER BY r.due_date, r.id"
);
$statement->execute([$user['id']]);
$dueSoon = $statement->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<?php $overdueCount = (int) $totals['overdue_loans']; ?>
<?php if ($overdueCount > 0): ?>
    <div class="notice notice-error dashboard-alert">
        <strong><?= $overdueCount ?> overdue <?= $overdueCount === 1 ? 'loan' : 'loans' ?>.</strong>
        <a href="requests.php?status=overdue">View overdue loans</a>
    </div>
<?php endif; ?>
<div class="page-heading page-actions dashboard-heading">
    <div>
        <h1>Student dashboard</h1>
        <p class="dashboard-welcome">Welcome, <?= escape($user['name']) ?>.</p>
        <p class="dashboard-meta"><?= escape($user['student_id']) ?> · <?= escape($user['department']) ?></p>
    </div>
    <a class="button" href="equipment.php">Browse equipment</a>
</div>

<div class="dashboard-stats" role="group" aria-label="Your borrowing summary">
    <?php foreach ($summary as $label => [$value, $url]): ?>
        <a class="stat-card<?= $label === 'Overdue loans' && (int) $value > 0 ? ' stat-card-overdue' : '' ?>"
           href="<?= escape($url) ?>">
            <span class="stat-label"><?= escape($label) ?></span>
            <strong class="stat-value"><?= number_format((int) $value) ?></strong>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($dueSoon !== []): ?>
    <section class="return-reminders" aria-labelledby="reminder-title">
        <div class="page-actions dashboard-section-heading">
            <h2 id="reminder-title">Returns due soon</h2>
            <a href="requests.php">View my borrowing records</a>
        </div>
        <p class="intro">Please return these items today or within the next three days.</p>
        <div class="table-scroll dashboard-table" tabindex="0" role="region" aria-labelledby="reminder-title">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Request</th>
                        <th scope="col">Equipment</th>
                        <th scope="col">Quantity</th>
                        <th scope="col">Due date</th>
                        <th scope="col">Reminder</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dueSoon as $loan): ?>
                        <tr>
                            <th scope="row">#<?= (int) $loan['id'] ?></th>
                            <td>
                                <?= escape($loan['equipment_name']) ?>
                                <small class="table-note"><?= escape($loan['equipment_code']) ?></small>
                            </td>
                            <td><?= (int) $loan['quantity'] ?></td>
                            <td><time><?= escape($loan['due_date']) ?></time></td>
                            <td>
                                <span class="status status-pending">
                                    <?php if ((int) $loan['days_remaining'] === 0): ?>
                                        Due today
                                    <?php elseif ((int) $loan['days_remaining'] === 1): ?>
                                        Due tomorrow
                                    <?php else: ?>
                                        Due in <?= (int) $loan['days_remaining'] ?> days
                                    <?php endif; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

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
                            <td><span class="status status-<?= escape($status) ?>"><?= escape(match ($status) { 'returned' => $request['has_missing'] ? 'Closed with missing units' : 'Returned', 'reserved' => 'Awaiting collection', 'expired' => 'Collection expired', default => ucfirst($status) }) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
