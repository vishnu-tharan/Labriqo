<?php

require_once __DIR__ . '/../includes/auth.php';

$user = requireRole('student');
$name = $user['name'];
$email = $user['email'];
$department = $user['department'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && formValue('action') === 'change_password') {
    checkCsrf();
    $errors = changePassword(
        $user['id'], formValue('current_password'), formValue('new_password'), formValue('confirm_password')
    );

    if ($errors === []) {
        $_SESSION['notice'] = 'Password changed.';
        redirect('profile.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && formValue('action') !== 'change_password') {
    checkCsrf();

    $name = trim(formValue('name'));
    $email = trim(formValue('email'));
    $department = trim(formValue('department'));
    $errors = profileErrors($name, $email, $department);
    $changingEmail = $email !== $user['email'];
    $passwordHash = null;
    if ($changingEmail && $errors === []) {
        $database = db();
        $window = requestWindow($database);
        if (countAttempt($database, 'account-change:' . $user['id'], $window) > 5) {
            limitResponse($window);
            $errors[] = 'Too many email change attempts. Try again later.';
        } else {
            $lookup = $database->prepare('SELECT password FROM users WHERE id = ?');
            $lookup->execute([$user['id']]);
            $hash = $lookup->fetchColumn();
            $password = formValue('email_change_password');
            if (!is_string($hash) || $password === '' || strlen($password) > 72 || str_contains($password, "\0")
                || !password_verify($password, $hash)) {
                $errors[] = 'Enter your current password to change your email address.';
            } else {
                $passwordHash = $hash;
            }
        }
    }

    if ($errors === []) {
        try {
            if ($changingEmail) {
                $statement = db()->prepare(
                    'UPDATE users SET name = ?, email = ?, department = ?
                     WHERE id = ? AND BINARY password = ? AND BINARY email = ?'
                );
                $statement->execute([$name, $email, $department, $user['id'], $passwordHash, $user['email']]);
                if ($statement->rowCount() !== 1) {
                    throw new DomainException('Your account changed. Reload the page and try again.');
                }
            } else {
                $statement = db()->prepare('UPDATE users SET name = ?, department = ? WHERE id = ?');
                $statement->execute([$name, $department, $user['id']]);
            }

            $_SESSION['notice'] = 'Profile updated.';
            redirect('profile.php');
        } catch (DomainException $exception) {
            $errors[] = $exception->getMessage();
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }

            $errors[] = 'The profile could not be saved with those details.';
        }
    }
}

$basePath = '../';
$pageTitle = 'My profile';
$showPasswordForm = true;
require __DIR__ . '/../includes/header.php';
?>
<section class="panel form-panel" aria-labelledby="page-title">
    <h1 id="page-title">My profile</h1>
    <p class="intro">Student ID <strong><?= escape($user['student_id']) ?></strong></p>

    <form method="post" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">

        <div class="field">
            <label for="name">Full name</label>
            <input id="name" name="name" autocomplete="name" maxlength="120" value="<?= escape($name) ?>" required>
        </div>

        <div class="field">
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" autocomplete="email" maxlength="254"
                   value="<?= escape($email) ?>" required>
        </div>

        <div class="field">
            <label for="department">Department</label>
            <input id="department" name="department" maxlength="120" value="<?= escape($department) ?>" required>
        </div>

        <div class="field">
            <label for="email-change-password">Current password (required to change email)</label>
            <input id="email-change-password" name="email_change_password" type="password" autocomplete="current-password">
        </div>

        <button class="button" type="submit">Save changes</button>
    </form>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
