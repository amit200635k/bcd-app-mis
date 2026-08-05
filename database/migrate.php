<?php

declare(strict_types=1);

/**
 * Database migration runner.
 *
 * Usage (from project root):
 *   php database/migrate.php            # run schema + seeds
 *   php database/migrate.php --seed     # run seeds only (requires schema)
 *   php database/migrate.php --fresh    # drop & re-create schema, then seed
 */

require __DIR__ . '/../common/bootstrap.php';

use App\Database\Connection;
use App\Support\Logger;

$flags = array_slice($argv, 1);
$fresh = in_array('--fresh', $flags, true);
$seedOnly = in_array('--seed', $flags, true);

$dbName = (string) config('database.name');

// Connect without a database so the DB can be created on first run.
$pdo = Connection::instance('mysql');
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
Connection::disconnect();
$pdo = Connection::instance();

function runSqlFile(PDO $pdo, string $file, string $label): void
{
    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('Empty or missing SQL file: ' . $file);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0;');
    $statements = splitStatements($sql);
    $count = 0;
    foreach ($statements as $stmt) {
        if ($stmt === '') {
            continue;
        }
        $pdo->exec($stmt);
        $count++;
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1;');
    echo "[OK] {$label}: {$count} statements executed." . PHP_EOL;
}

/** Split SQL on top-level semicolons (ignores those inside strings and comments). */
function splitStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inString = false;
    $quote = '';
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        // Line comment "//" or "--" (skip to end of line; MySQL also uses "#").
        if (!$inString && ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') || (!$inString && $ch === '#')) {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }

        // Block comment "/* ... */".
        if (!$inString && $ch === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
            $i += 2;
            while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                $i++;
            }
            $i++;
            continue;
        }

        $buffer .= $ch;
        if ($inString) {
            if ($ch === '\\' && $i + 1 < $len) {
                $buffer .= $sql[++$i];
                continue;
            }
            if ($ch === $quote) {
                $inString = false;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $inString = true;
            $quote = $ch;
            continue;
        }
        if ($ch === ';') {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return array_values(array_filter($statements));
}

try {
    if ($fresh) {
        echo "Dropping all tables..." . PHP_EOL;
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0;');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1;');
        echo "[OK] Dropped " . count($tables) . " tables." . PHP_EOL;
    }

    if (!$seedOnly) {
        runSqlFile($pdo, __DIR__ . '/schema.sql', 'Schema');
    }
    runSqlFile($pdo, __DIR__ . '/seed.sql', 'Seed');

    // Idempotent incremental migrations (CREATE TABLE IF NOT EXISTS etc.).
    $migrations = glob(__DIR__ . '/migrations/*.sql') ?: [];
    sort($migrations);
    foreach ($migrations as $migration) {
        runSqlFile($pdo, $migration, 'Migration ' . basename($migration));
    }

    echo PHP_EOL . "Migration completed successfully." . PHP_EOL;
} catch (Throwable $e) {
    Logger::error('Migration failed', ['message' => $e->getMessage()]);
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
