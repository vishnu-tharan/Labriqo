<?php

require_once __DIR__ . '/../includes/auth.php';

$user = requireRole('staff');
$basePath = '../';
$pageTitle = 'My profile';
$errors = [];
$showPasswordForm = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    if (formValue('action') !== 'change_password') {
        http_response_code(400);
        exit('Choose a valid account action.');
    }
    $errors = changePassword(
        $user['id'], formValue('current_password'), formValue('new_password'), formValue('confirm_password')
    );

    if ($errors === []) {
        $_SESSION['notice'] = 'Password changed.';
        redirect('profile.php');
    }
}

require __DIR__ . '/../includes/header.php';
?>
<section class="panel form-panel" aria-labelledby="page-title">
    <h1 id="page-title">My profile</h1>
    <dl class="details">
        <div><dt>Name</dt><dd><?= escape($user['name']) ?></dd></div>
        <div><dt>Email</dt><dd><?= escape($user['email']) ?></dd></div>
        <div><dt>Department</dt><dd><?= escape($user['department']) ?></dd></div>
    </dl>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
