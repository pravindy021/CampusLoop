<?php
declare(strict_types=1);

function database_connection(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $settings = require __DIR__ . '/app.php';
    $database = $settings['database'];
    if ($settings['environment'] === 'production'
        && ($database['password'] === '' || $settings['app_secret'] === '')) {
        throw new RuntimeException('Production database credentials and application secret must be configured.');
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $database['host'],
        $database['port'],
        $database['name']
    );
    $mysqlInitCommandAttribute = defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
        ? constant('Pdo\\Mysql::ATTR_INIT_COMMAND')
        : PDO::MYSQL_ATTR_INIT_COMMAND;

    try {
        $connection = new PDO($dsn, $database['user'], $database['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            $mysqlInitCommandAttribute => "SET time_zone = '+00:00'",
        ]);
    } catch (PDOException $exception) {
        error_log('Database connection failed: ' . $exception->getCode());
        throw new RuntimeException('The service is temporarily unavailable.', 0, $exception);
    }

    return $connection;
}
