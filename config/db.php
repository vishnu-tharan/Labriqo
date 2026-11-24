<?php

declare(strict_types=1);

// Maximum physical units a student may hold across active loans.
const MAX_BORROWED_UNITS = 5;

function db(): PDO
{
    static $connection = null;

    if ($connection === null) {
        $host = '127.0.0.1';
        $port = getenv('LABRIQO_DB_PORT') ?: '3306';
        $database = 'labriqo';
        $username = getenv('LABRIQO_DB_USER');
        $password = getenv('LABRIQO_DB_PASSWORD');
        if ($username === false && $password === false) {
            // XAMPP credentials live above the htdocs directory.
            $localConfig = dirname(__DIR__, 3) . '/labriqo-db.local.php';
            if (is_file($localConfig)) {
                $credentials = require $localConfig;
                $username = $credentials['username'] ?? '';
                $password = $credentials['password'] ?? '';
            }
        }

        if (!is_string($username) || trim($username) === '' || strtolower(trim($username)) === 'root'
            || !is_string($password) || $password === '') {
            throw new RuntimeException('Set a dedicated Labriqo database user and a non-empty password.');
        }

        $connection = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    return $connection;
}
