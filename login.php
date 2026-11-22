<?php

require_once __DIR__ . '/includes/auth.php';

function countLoginAttempt(PDO $database, string $scope, int $window): int
{
    $database->beginTransaction();
    try {
        $record = $database->prepare(
            'INSERT INTO login_attempts (scope_key, window_start) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE attempts = attempts + 1'
        );
        $record->execute([$scope, $window]);
        $count = $database->prepare('SELECT attempts FROM login_attempts WHERE scope_key = ? AND window_start = ?');
        $count->execute([$scope, $window]);
        $attempts = (int) $count->fetchColumn();
        $database->commit();
        return $attempts;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

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
    $now = time();
    $window = intdiv($now, 900) * 900;
    $ipScope = 'ip:' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if (random_int(1, 100) === 1) {
        $cleanup = $database->prepare('DELETE FROM login_attempts WHERE window_start < ?');
        $cleanup->execute([$window - 900]);
    }

    $limited = countLoginAttempt($database, $ipScope, $window) > 50;
    if (!$limited && validEmail($email) && $password !== '' && strlen($password) <= 72 && !str_contains($password, "\0")) {
        $statement = $database->prepare('SELECT id, role, password, is_approved FROM users WHERE email = ?');
        $statement->execute([$email]);
        $account = $statement->fetch();

        $limited = $account !== false && countLoginAttempt($database, 'user:' . $account['id'], $window) > 10;
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
                        'password_stamp' => hash('sha256', $account['password']),
                    ];
                    redirect(dashboardPath($account));
                }
            }
        }
    }

    if ($limited) {
        http_response_code(429);
        header('Retry-After: ' . ($window + 900 - $now));
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
