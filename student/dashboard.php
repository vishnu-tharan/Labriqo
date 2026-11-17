<?php

require_once __DIR__ . '/../includes/auth.php';

$user = requireRole('student');
$basePath = '../';
$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <h1>Student dashboard</h1>
    <p class="intro">Welcome, <?= escape($user['name']) ?>.</p>
</div>

<section class="panel" aria-labelledby="account-title">
    <h2 id="account-title">Student account</h2>
    <dl class="details">
        <div>
            <dt>Student ID</dt>
            <dd><?= escape($user['student_id']) ?></dd>
        </div>
        <div>
            <dt>Department</dt>
            <dd><?= escape($user['department']) ?></dd>
        </div>
    </dl>
    <a class="button" href="profile.php">Edit profile</a>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
