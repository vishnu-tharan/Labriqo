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

    $database = db();
    $window = requestWindow($database);
    $limited = countAttempt($database, ipAttemptScope('ip'), $window) > 50;
    if (!$limited && validEmail($email) && $password !== '' && strlen($password) <= 72 && !str_contains($password, "\0")) {
        $statement = $database->prepare('SELECT id, role, password, is_approved FROM users WHERE email = ?');
        $statement->execute([$email]);
        $account = $statement->fetch();

        $emailScope = 'email:' . hash('sha256', strtolower($email));
        $limited = countAttempt($database, $emailScope, $window) > 10;
        if (!$limited && $account !== false) {
            // The account counter also covers equivalent email spellings accepted by the database.
            $limited = countAttempt($database, 'user:' . $account['id'], $window) > 10;
        }
        if (!$limited) {
            // Match the work of checking a wrong password when the email is unknown.
            $hash = $account['password'] ?? '$2y$10$FAMih48I4dBocbVDWm8Fs.Vy/Cii/G/dMNewIL1JnSGl08CXH/y';
            if (password_verify($password, $hash) && $account !== false) {
                if ($account['role'] === 'student' && !$account['is_approved']) {
                    $errors[] = 'Your student account is awaiting staff approval.';
                } else {
                    if (!session_regenerate_id(true)) {
                        throw new RuntimeException('Unable to renew the Labriqo session.');
                    }

                    $_SESSION = [
                        'user_id' => $account['id'],
                        'signed_in_at' => time(),
                        'last_active_at' => time(),
                        'password_stamp' => hash('sha256', $account['password']),
                    ];
                    redirect(dashboardPath($account));
                }
            }
        }
    }

    if ($limited) {
        limitResponse($window);
        $errors[] = 'Too many sign-in attempts. Try again later.';
    } elseif ($errors === []) {
        $errors[] = 'The email address or password is incorrect.';
    }
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
