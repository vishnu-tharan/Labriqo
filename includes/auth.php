<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

set_exception_handler(function (Throwable $exception): void {
    error_log($exception->getMessage());
    http_response_code($exception instanceof PDOException ? 503 : 500);
    exit('Labriqo is temporarily unavailable. Please try again later.');
});

header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
$https = isset($_SERVER['HTTPS']) && in_array(strtolower($_SERVER['HTTPS']), ['on', '1'], true);
if (PHP_SAPI !== 'cli' && !$https && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Use HTTPS to connect from another computer.');
}

session_cache_limiter('');
session_name('LabriqoSession');
session_start([
    'use_strict_mode' => true,
    'use_only_cookies' => true,
    'cookie_httponly' => true,
    'cookie_secure' => $https,
    'cookie_samesite' => 'Lax',
]);

function countAttempt(PDO $database, string $scope, int $window): int
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

function requestWindow(PDO $database): int
{
    $window = intdiv(time(), 900) * 900;
    if (random_int(1, 100) === 1) {
        $cleanup = $database->prepare('DELETE FROM login_attempts WHERE window_start < ?');
        $cleanup->execute([$window - 900]);
    }
    return $window;
}

function ipAttemptScope(string $prefix): string
{
    return $prefix . ':' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function limitResponse(int $window): void
{
    http_response_code(429);
    header('Retry-After: ' . max(1, $window + 900 - time()));
}

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

    $now = time();
    if ($now - ($_SESSION['last_active_at'] ?? 0) >= 1800 || $now - ($_SESSION['signed_in_at'] ?? 0) >= 28800) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }

    $statement = db()->prepare(
        'SELECT id, student_id, name, email, department, role, password, is_approved FROM users WHERE id = ?'
    );
    $statement->execute([$_SESSION['user_id']]);
    $user = $statement->fetch();

    if ($user === false || ($user['role'] === 'student' && !$user['is_approved'])
        || !hash_equals(hash('sha256', $user['password']), $_SESSION['password_stamp'] ?? '')) {
        $_SESSION = [];
        return null;
    }

    $_SESSION['last_active_at'] = $now;
    unset($user['password'], $user['is_approved']);
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

    expireReservations(db());
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


function passwordPolicyError(string $password): ?string
{
    if (!mb_check_encoding($password, 'UTF-8') || mb_strlen($password, 'UTF-8') < 15
        || strlen($password) > 72 || str_contains($password, "\0")) {
        return 'Use a password with at least 15 characters and at most 72 bytes.';
    }

    $simplified = preg_replace('/[^a-z0-9]/', '', strtolower($password));
    if (preg_match('/\A(.)\1+\z/us', $password) === 1
        || ($simplified !== '' && ctype_digit($simplified))
        || preg_match('/\A(?:password|qwerty|letmein|admin)[0-9]*\z/', $simplified) === 1) {
        return 'Choose a less predictable password or passphrase.';
    }

    return null;
}

function safeEquipmentImage(?string $path): bool
{
    return $path !== null && strlen($path) <= 255
        && preg_match('~\Aassets/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.(?:png|jpe?g|webp|gif|avif)\z~i', $path) === 1;
}

function changePassword(int $userId, string $currentPassword, string $newPassword, string $confirmation): array
{
    $policyError = passwordPolicyError($newPassword);
    if ($policyError !== null) {
        return [$policyError];
    }

    if ($newPassword !== $confirmation) {
        return ['The new passwords do not match.'];
    }

    $database = db();
    $window = requestWindow($database);
    if (countAttempt($database, 'account-change:' . $userId, $window) > 5) {
        limitResponse($window);
        return ['Too many account change attempts. Try again later.'];
    }

    $statement = $database->prepare('SELECT password FROM users WHERE id = ?');
    $statement->execute([$userId]);
    $storedHash = $statement->fetchColumn();

    if ($storedHash === false || strlen($currentPassword) > 72 || str_contains($currentPassword, "\0")
        || !password_verify($currentPassword, $storedHash)) {
        return ['Your current password is incorrect.'];
    }

    // Match the old hash as well so another password change cannot be overwritten.
    $update = db()->prepare('UPDATE users SET password = ? WHERE id = ? AND BINARY password = ?');
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update->execute([$newHash, $userId, $storedHash]);

    if ($update->rowCount() !== 1) {
        return ['Your password changed in another session. Enter your current password and try again.'];
    }

    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Unable to renew the Labriqo session.');
    }
    $_SESSION['password_stamp'] = hash('sha256', $newHash);
    unset($_SESSION['csrf_token']);

    return [];
}


function downloadCsv(string $filename, array $columns, array $records): void
{
    $output = fopen('php://output', 'w');

    if ($output === false) {
        throw new RuntimeException('Unable to open the CSV download.');
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    // The UTF-8 marker helps spreadsheet programs display names correctly.
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array_values($columns), ',', '"', '');

    foreach ($records as $record) {
        $row = [];
        foreach ($columns as $field => $label) {
            $value = (string) ($record[$field] ?? '');

            // Keep user-entered spreadsheet formulas as plain text.
            if (preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $value) === 1) {
                $value = "'" . $value;
            }
            $row[] = $value;
        }
        fputcsv($output, $row, ',', '"', '');
    }

    fclose($output);
    exit;
}


function paginate(PDO $database, PDOStatement $statement, array $parameters, bool $allRecords = false): array
{
    if ($allRecords) {
        $statement->execute($parameters);
        return ['records' => $statement->fetchAll(), 'page' => 1, 'pages' => 1];
    }

    $count = $database->prepare('SELECT COUNT(*) FROM (' . $statement->queryString . ') AS filtered_records');
    $count->execute($parameters);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / 10));
    $requestedPage = filter_var(queryValue('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $page = min($requestedPage === false ? 1 : $requestedPage, $pages);
    $offset = ($page - 1) * 10;

    $limited = $database->prepare($statement->queryString . ' LIMIT 10 OFFSET ' . $offset);
    $limited->execute($parameters);

    return ['records' => $limited->fetchAll(), 'page' => $page, 'pages' => $pages];
}


function recordStaffActivity(PDO $database, int $staffId, string $action, string $target, int $targetId, ?string $detail = null): void
{
    $statement = $database->prepare(
        'INSERT INTO staff_activity (staff_id, action_name, target_type, target_id, detail) VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([$staffId, $action, $target, $targetId, $detail]);
}

function recordStockAdjustment(PDO $database, int $equipmentId, int $staffId, int $before, int $after, string $reason): void
{
    $statement = $database->prepare(
        'INSERT INTO stock_adjustments (equipment_id, staff_id, previous_total, new_total, reason)
         VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([$equipmentId, $staffId, $before, $after, $reason]);
}

function expireReservations(PDO $database): void
{
    $equipmentIds = $database->query(
        "SELECT DISTINCT equipment_id FROM borrow_requests
         WHERE status = 'reserved' AND collection_deadline <= CURRENT_TIMESTAMP() ORDER BY equipment_id"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($equipmentIds as $equipmentId) {
        $database->beginTransaction();
        try {
            $lock = $database->prepare('SELECT id FROM equipment WHERE id = ? FOR UPDATE');
            $lock->execute([$equipmentId]);
            $expired = $database->prepare(
                "SELECT id, quantity FROM borrow_requests
                 WHERE equipment_id = ? AND status = 'reserved' AND collection_deadline <= CURRENT_TIMESTAMP()
                 FOR UPDATE"
            );
            $expired->execute([$equipmentId]);

            $release = $database->prepare("UPDATE borrow_requests SET status = 'expired' WHERE id = ?");
            $quantity = 0;
            foreach ($expired->fetchAll() as $reservation) {
                $release->execute([$reservation['id']]);
                $quantity += (int) $reservation['quantity'];
            }
            if ($quantity > 0) {
                $stock = $database->prepare('UPDATE equipment SET available_quantity = available_quantity + ? WHERE id = ?');
                $stock->execute([$quantity, $equipmentId]);
            }
            $database->commit();
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }
}

function borrowedUnits(PDO $database, int $studentId): int
{
    $statement = $database->prepare(
        "SELECT COALESCE(SUM(quantity - settled_quantity), 0) FROM borrow_requests WHERE user_id = ? AND status IN ('borrowed', 'reserved')"
    );
    $statement->execute([$studentId]);
    return (int) $statement->fetchColumn();
}

function getBorrowingSlip(PDO $database, array $user, string $requestId): array
{
    $id = filter_var($requestId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $statement = $database->prepare(
        "SELECT r.id, r.quantity, r.settled_quantity, r.reason, r.status, r.issue_date, r.due_date, r.return_date, r.issue_condition, r.return_condition,
                u.name AS student_name, u.student_id, u.department, u.email,
                e.name AS equipment_name, e.equipment_code,
                (SELECT GROUP_CONCAT(a.asset_tag ORDER BY a.asset_tag SEPARATOR ', ')
                 FROM loan_assets x JOIN equipment_assets a ON a.id = x.asset_id WHERE x.request_id = r.id) AS asset_tags
         FROM borrow_requests r
         JOIN users u ON r.user_id = u.id
         JOIN equipment e ON r.equipment_id = e.id
         WHERE r.id = ? AND r.status IN ('borrowed', 'returned') AND (? = 'staff' OR r.user_id = ?)"
    );
    $statement->execute([$id === false ? 0 : $id, $user['role'], $user['id']]);
    $slip = $statement->fetch();

    if ($slip === false) {
        http_response_code(404);
        exit('This borrowing slip is unavailable.');
    }

    $events = $database->prepare(
        'SELECT quantity, outcome, condition_note, asset_tags, recorded_at
         FROM loan_settlements WHERE request_id = ? ORDER BY id'
    );
    $events->execute([$slip['id']]);
    $slip['settlements'] = $events->fetchAll();
    return $slip;
}
