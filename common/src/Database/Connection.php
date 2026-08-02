<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;

/**
 * PDO connection manager. Singleton per runtime.
 */
final class Connection
{
    private static ?PDO $instance = null;

    public static function instance(?string $dbName = null): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $config = config('database');
        $dbName ??= $config['name'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $dbName,
            $config['charset']
        );

        try {
            $pdo = new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        self::$instance = $pdo;
        return $pdo;
    }

    public static function begin(): void
    {
        self::instance()->beginTransaction();
    }

    public static function commit(): void
    {
        self::instance()->commit();
    }

    public static function rollback(): void
    {
        self::instance()->rollBack();
    }

    public static function disconnect(): void
    {
        self::$instance = null;
    }
}
