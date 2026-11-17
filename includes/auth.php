<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

set_exception_handler(function (Throwable $exception): void {
    error_log($exception->getMessage());
    http_response_code($exception instanceof PDOException ? 503 : 500);
    exit('Labriqo is temporarily unavailable. Please try again later.');
});

session_name('LabriqoSession');
session_start([
    'use_strict_mode' => true,
    'use_only_cookies' => true,
    'cookie_httponly' => true,
    'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'cookie_samesite' => 'Lax',
]);

function redirect(string $path): void
{
    header('Location: ' . $path, true, 303);
    exit;
}

function dashboardPath(array $user): string
{
    return $user['role'] === 'staff' ? 'staff/dashboard.php' : 'student/dashboard.php';
}

function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT id, student_id, name, email, department, role FROM users WHERE id = ?'
    );
    $statement->execute([$_SESSION['user_id']]);
    $user = $statement->fetch();

    if ($user === false) {
        unset($_SESSION['user_id']);
        return null;
    }

    return $user;
}

function requireRole(string $role): array
{
    $user = currentUser();

    if ($user === null) {
        redirect('../login.php');
    }

    if ($user['role'] !== $role) {
        http_response_code(403);
        exit('You do not have access to this page.');
    }

    return $user;
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function checkCsrf(): void
{
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], formValue('csrf_token'))) {
        http_response_code(403);
        exit('This form has expired. Go back, refresh the page, and try again.');
    }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function formValue(string $field): string
{
    $value = $_POST[$field] ?? '';
    return is_string($value) ? $value : '';
}

function queryValue(string $field): string
{
    $value = $_GET[$field] ?? '';
    return is_string($value) ? $value : '';
}

function validText(string $value, int $maxLength): bool
{
    return $value !== '' && mb_check_encoding($value, 'UTF-8')
        && mb_strlen($value, 'UTF-8') <= $maxLength;
}

function validEmail(string $email): bool
{
    return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validDate(string $date): bool
{
    if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) !== 1) {
        return false;
    }

    [$year, $month, $day] = explode('-', $date);
    return checkdate((int) $month, (int) $day, (int) $year);
}

function profileErrors(string $name, string $email, string $department): array
{
    $errors = [];

    if (!validText($name, 120)) {
        $errors[] = 'Enter a full name of up to 120 characters.';
    }

    if (!validEmail($email)) {
        $errors[] = 'Enter a valid email address.';
    }

    if (!validText($department, 120)) {
        $errors[] = 'Enter a department of up to 120 characters.';
    }

    return $errors;
}
