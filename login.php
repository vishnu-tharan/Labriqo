<?php

require_once __DIR__ . '/includes/auth.php';

$user = currentUser();

if ($user !== null) {
    redirect(dashboardPath($user));
}

$email = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $email = trim(formValue('email'));
    $password = formValue('password');

    if (validEmail($email) && $password !== '' && strlen($password) <= 72 && !str_contains($password, "\0")) {
        $statement = db()->prepare('SELECT id, role, password FROM users WHERE email = ?');
        $statement->execute([$email]);
        $account = $statement->fetch();

        if ($account !== false && password_verify($password, $account['password'])) {
            if (!session_regenerate_id(true)) {
                throw new RuntimeException('Unable to renew the Labriqo session.');
            }

            $_SESSION = ['user_id' => $account['id']];
            redirect(dashboardPath($account));
        }
    }

    $errors[] = 'The email address or password is incorrect.';
}

$pageTitle = 'Sign in';
require __DIR__ . '/includes/header.php';
?>
<section class="panel auth-panel" aria-labelledby="page-title">
    <h1 id="page-title">Sign in</h1>
    <p class="intro">Access your student or laboratory staff account.</p>

    <form method="post" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">

        <div class="field">
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" autocomplete="username" maxlength="254"
                   value="<?= escape($email) ?>" required>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>

        <button class="button" type="submit">Sign in</button>
    </form>

    <p class="form-link">New student? <a href="register.php">Create a student account</a></p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
