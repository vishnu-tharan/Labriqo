<?php

require_once __DIR__ . '/../includes/auth.php';

$user = requireRole('student');
$name = $user['name'];
$email = $user['email'];
$department = $user['department'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $name = trim(formValue('name'));
    $email = trim(formValue('email'));
    $department = trim(formValue('department'));
    $errors = profileErrors($name, $email, $department);

    if ($errors === []) {
        try {
            $statement = db()->prepare('UPDATE users SET name = ?, email = ?, department = ? WHERE id = ?');
            $statement->execute([$name, $email, $department, $user['id']]);

            $_SESSION['notice'] = 'Profile updated.';
            redirect('profile.php');
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }

            $errors[] = 'That email address is already in use.';
        }
    }
}

$basePath = '../';
$pageTitle = 'My profile';
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

        <button class="button" type="submit">Save changes</button>
    </form>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
