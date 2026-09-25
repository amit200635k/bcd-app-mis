<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database connector for external database targets.
 * Supports: mssql, postgres, mysql, oracle (via PDO).
 */
final class ExternalDbConnector
{
    private PDO $pdo;
    private string $dbType;
    private array $config;

    /**
     * @param array{
     *   db_type: 'mssql'|'postgres'|'mysql'|'oracle',
     *   host: string,
     *   port: int|null,
     *   database_name: string,
     *   username: string,
     *   password: string
     * } $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->dbType = $config['db_type'];
        $this->pdo = $this->createConnection($config);
    }

    /**
     * Create PDO connection based on database type.
     */
    private function createConnection(array $config): PDO
    {
        $dsn = $this->buildDsn($config);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
            // Set timezone to UTC for consistency
            $pdo->exec("SET time_zone = '+00:00'");
            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Failed to connect to {$config['db_type']} at {$config['host']}: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Build DSN string for the database type.
     */
    private function buildDsn(array $config): string
    {
        $host = $config['host'];
        $port = $config['port'] ?? $this->getDefaultPort($config['db_type']);
        $dbname = $config['database_name'];

        return match ($config['db_type']) {
            'mssql' => "sqlsrv:Server={$host},{$port};Database={$dbname};TrustServerCertificate=true",
            'postgres' => "pgsql:host={$host};port={$port};dbname={$dbname}",
            'mysql' => "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
            'oracle' => "oci:dbname=//{$host}:{$port}/{$dbname}",
            default => throw new RuntimeException("Unsupported database type: {$config['db_type']}"),
        };
    }

    /**
     * Get default port for database type.
     */
    private function getDefaultPort(string $dbType): int
    {
        return match ($dbType) {
            'mssql' => 1433,
            'postgres' => 5432,
            'mysql' => 3306,
            'oracle' => 1521,
            default => 3306,
        };
    }

    /**
     * Get the underlying PDO instance.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Get database type.
     */
    public function getDbType(): string
    {
        return $this->dbType;
    }

    /**
     * Test the connection.
     */
    public function testConnection(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Begin transaction.
     */
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    /**
     * Commit transaction.
     */
    public function commit(): void
    {
        $this->pdo->commit();
    }

    /**
     * Rollback transaction.
     */
    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    /**
     * Execute a prepared statement.
     *
     * @return int number of affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Fetch all rows.
     *
     * @return array<string,mixed>[]
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Fetch single row.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Get last insert ID.
     */
    public function lastInsertId(?string $sequence = null): string|false
    {
        return $this->pdo->lastInsertId($sequence);
    }

    /**
     * Quote a value for use in SQL.
     */
    public function quote(string $value): string
    {
        return $this->pdo->quote($value);
    }

    /**
     * Get column type mapping for survey data.
     *
     * Maps survey field types to target database column types.
     */
    public function getColumnType(string $fieldType, array $settings = []): string
    {
        return match ($this->dbType) {
            'mssql' => $this->getMssqlType($fieldType, $settings),
            'postgres' => $this->getPostgresType($fieldType, $settings),
            'mysql' => $this->getMysqlType($fieldType, $settings),
            'oracle' => $this->getOracleType($fieldType, $settings),
            default => 'TEXT',
        };
    }

    private function getMssqlType(string $fieldType, array $settings): string
    {
        return match ($fieldType) {
            'textbox', 'textarea' => 'NVARCHAR(MAX)',
            'number' => 'BIGINT',
            'decimal' => 'DECIMAL(20,6)',
            'date' => 'DATE',
            'time' => 'TIME',
            'dropdown', 'radio', 'checkbox' => 'NVARCHAR(255)',
            'multi_select' => 'NVARCHAR(MAX)',
            'master' => 'NVARCHAR(255)',
            'location_cascade' => 'NVARCHAR(MAX)',
            'gps' => 'NVARCHAR(255)',
            'camera', 'signature', 'file_upload' => 'NVARCHAR(MAX)',
            'auto_number' => 'NVARCHAR(255)',
            'geometry' => 'GEOMETRY',
            default => 'NVARCHAR(MAX)',
        };
    }

    private function getPostgresType(string $fieldType, array $settings): string
    {
        return match ($fieldType) {
            'textbox', 'textarea' => 'TEXT',
            'number' => 'BIGINT',
            'decimal' => 'NUMERIC(20,6)',
            'date' => 'DATE',
            'time' => 'TIME',
            'dropdown', 'radio', 'checkbox' => 'VARCHAR(255)',
            'multi_select' => 'TEXT',
            'master' => 'VARCHAR(255)',
            'location_cascade' => 'JSONB',
            'gps' => 'VARCHAR(255)',
            'camera', 'signature', 'file_upload' => 'TEXT',
            'auto_number' => 'VARCHAR(255)',
            default => 'TEXT',
        };
    }

    private function getMysqlType(string $fieldType, array $settings): string
    {
        return match ($fieldType) {
            'textbox', 'textarea' => 'TEXT',
            'number' => 'BIGINT',
            'decimal' => 'DECIMAL(20,6)',
            'date' => 'DATE',
            'time' => 'TIME',
            'dropdown', 'radio', 'checkbox' => 'VARCHAR(255)',
            'multi_select' => 'TEXT',
            'master' => 'VARCHAR(255)',
            'location_cascade' => 'JSON',
            'gps' => 'VARCHAR(255)',
            'camera', 'signature', 'file_upload' => 'TEXT',
            'auto_number' => 'VARCHAR(255)',
            default => 'TEXT',
        };
    }

    private function getOracleType(string $fieldType, array $settings): string
    {
        return match ($fieldType) {
            'textbox', 'textarea' => 'CLOB',
            'number' => 'NUMBER(20)',
            'decimal' => 'NUMBER(20,6)',
            'date' => 'DATE',
            'time' => 'VARCHAR2(20)',
            'dropdown', 'radio', 'checkbox' => 'VARCHAR2(255)',
            'multi_select' => 'CLOB',
            'master' => 'VARCHAR2(255)',
            'location_cascade' => 'CLOB',
            'gps' => 'VARCHAR2(255)',
            'camera', 'signature', 'file_upload' => 'CLOB',
            'auto_number' => 'VARCHAR2(255)',
            default => 'CLOB',
        };
    }

    /**
     * Normalize a value for the target database.
     */
    public function normalizeValue(string $fieldType, mixed $value, array $settings = []): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Handle location_cascade as JSON
        if ($fieldType === 'location_cascade' && is_array($value)) {
            return json_encode($value);
        }

        // Handle multi_select as JSON
        if ($fieldType === 'multi_select' && is_array($value)) {
            return json_encode($value);
        }

        // Handle master as JSON with master_id and name
        if ($fieldType === 'master' && is_array($value)) {
            return json_encode([
                'master_id' => $value['master_id'] ?? $value['id'] ?? null,
                'name' => $value['name'] ?? $value['option_label'] ?? '',
            ]);
        }

        // Handle GPS
        if ($fieldType === 'gps' && is_array($value)) {
            return json_encode($value);
        }

        return $value;
    }
}