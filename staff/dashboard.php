<?php

require_once __DIR__ . '/../includes/auth.php';

$user = requireRole('staff');
$basePath = '../';
$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <h1>Staff dashboard</h1>
    <p class="intro">Welcome, <?= escape($user['name']) ?>.</p>
</div>

<section class="panel" aria-labelledby="account-title">
    <h2 id="account-title">Laboratory staff account</h2>
    <dl class="details">
        <div>
            <dt>Email address</dt>
            <dd><?= escape($user['email']) ?></dd>
        </div>
        <div>
            <dt>Department</dt>
            <dd><?= escape($user['department']) ?></dd>
        </div>
    </dl>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
