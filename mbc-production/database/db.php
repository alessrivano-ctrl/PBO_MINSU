<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbHost = getenv('DB_HOST') ?: (defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1');
    $dbPort = getenv('DB_PORT') ?: (defined('DB_PORT') ? (string) DB_PORT : '3306');
    $dbUser = getenv('DB_USER') ?: (defined('DB_USER') ? (string) DB_USER : 'root');
    $dbPass = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : (defined('DB_PASS') ? (string) DB_PASS : '');
    $dbNameInput = getenv('DB_NAME') ?: (defined('DB_NAME') ? (string) DB_NAME : 'u317918921_bpo_system');
    $dbCharset = getenv('DB_CHARSET') ?: (defined('DB_CHARSET') ? (string) DB_CHARSET : 'utf8mb4');

    $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbNameInput) ?: 'u317918921_bpo_system';
    $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', $dbHost, $dbPort, $dbCharset);

    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $skipCreate = getenv('DB_SKIP_CREATE') === '1';
    if (!$skipCreate) {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    $pdo->exec("USE `{$dbName}`");

    return $pdo;
}
