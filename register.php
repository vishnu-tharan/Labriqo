<?php

require_once __DIR__ . '/includes/auth.php';

$user = currentUser();

if ($user !== null) {
    redirect(dashboardPath($user));
}

$name = '';
$studentId = '';
$email = '';
$department = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $name = trim(formValue('name'));
    $studentId = trim(formValue('student_id'));
    $email = trim(formValue('email'));
    $department = trim(formValue('department'));
    $password = formValue('password');
    $confirmation = formValue('confirm_password');

    $database = db();
    $window = requestWindow($database);
    $limited = countAttempt($database, ipAttemptScope('reg'), $window) > 5;
    if ($limited) {
        limitResponse($window);
    }
    $errors = $limited
        ? ['Too many registration attempts. Try again later.']
        : profileErrors($name, $email, $department);

    if (!validText($studentId, 30)) {
        $errors[] = 'Enter a student ID of up to 30 characters.';
    }

    $policyError = passwordPolicyError($password);
    if ($policyError !== null) {
        $errors[] = $policyError;
    }

    if ($password !== $confirmation) {
        $errors[] = 'The passwords do not match.';
    }

    if ($errors === []) {
        try {
            $statement = db()->prepare(
                "INSERT INTO users (student_id, name, email, password, department, role, is_approved)
                VALUES (?, ?, ?, ?, ?, 'student', 0)"
            );
            $statement->execute([$studentId, $name, $email, password_hash($password, PASSWORD_DEFAULT), $department]);

        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }

        }
        $_SESSION['notice'] = 'If this registration can be accepted, it will appear in the staff approval queue. Contact staff to verify your details.';
        redirect('login.php');
    }
}

$pageTitle = 'Student registration';
require __DIR__ . '/includes/header.php';
?>
<section class="panel form-panel" aria-labelledby="page-title">
    <h1 id="page-title">Create a student account</h1>
    <p class="intro">Use your university student ID and contact details.</p>

    <form method="post" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">

        <div class="form-grid">
            <div class="field">
                <label for="name">Full name</label>
                <input id="name" name="name" autocomplete="name" maxlength="120"
                       value="<?= escape($name) ?>" required>
            </div>

            <div class="field">
                <label for="student-id">Student ID</label>
                <input id="student-id" name="student_id" maxlength="30" value="<?= escape($studentId) ?>" required>
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
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="15"
                       aria-describedby="password-hint" required>
                <small id="password-hint">Use at least 15 characters.</small>
            </div>

            <div class="field">
                <label for="confirm-password">Confirm password</label>
                <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" required>
            </div>
        </div>

        <button class="button" type="submit">Create account</button>
    </form>

    <p class="form-link">Already registered? <a href="login.php">Sign in</a></p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
