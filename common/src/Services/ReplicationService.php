<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

/**
 * Queue-based replication to external databases.
 * App writes only to MySQL; the replication service drains the queue
 * and applies changes to target databases with retry/failure recovery.
 */
final class ReplicationService
{
    /**
     * Enqueue a change for replication.
     */
    public function enqueue(string $entityType, string $entityId, string $operation, array $payload, ?int $targetDbId = null): void
    {
        Connection::instance()->prepare(
            'INSERT INTO replication_queue (entity_type, entity_id, operation, payload_json, target_db_id)
             VALUES (:et, :ei, :op, :pj, :td)'
        )->execute([
            'et' => $entityType,
            'ei' => $entityId,
            'op' => $operation,
            'pj' => json_encode($payload),
            'td' => $targetDbId,
        ]);
    }

    /** @return array<string,mixed>|null next pending job */
    public function nextPending(): ?array
    {
        $pdo = Connection::instance();
        $stmt = $pdo->query(
            'SELECT * FROM replication_queue WHERE status = "pending" ORDER BY id LIMIT 1'
        );
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $pdo->prepare('UPDATE replication_queue SET status = "processing", attempt_count = attempt_count + 1 WHERE id = :id')
            ->execute(['id' => $row['id']]);
        $row['payload'] = json_decode((string) $row['payload_json'], true);
        return $row;
    }

    /**
     * Process a single job.
     * @param callable $apply ($payload, $targetDbId) => bool
     */
    public function processOne(callable $apply, int $maxAttempts = 5): bool
    {
        $job = $this->nextPending();
        if ($job === null) {
            return false;
        }
        $pdo = Connection::instance();
        try {
            $ok = $apply($job['payload'], $job['target_db_id'] ? (int) $job['target_db_id'] : null);
            if ($ok) {
                $pdo->prepare('UPDATE replication_queue SET status = "success", processed_at = NOW() WHERE id = :id')
                    ->execute(['id' => $job['id']]);
            } else {
                throw new \RuntimeException('Replication apply returned false.');
            }
        } catch (\Throwable $e) {
            $failed = (int) $job['attempt_count'] >= $maxAttempts;
            $pdo->prepare(
                'UPDATE replication_queue SET status = :s, error_message = :m WHERE id = :id'
            )->execute([
                's' => $failed ? 'failed' : 'pending',
                'm' => substr($e->getMessage(), 0, 500),
                'id' => $job['id'],
            ]);
        }
        return true;
    }

    public function stats(): array
    {
        return Connection::instance()->query(
            'SELECT status, COUNT(*) AS c FROM replication_queue GROUP BY status'
        )->fetchAll();
    }

    public function retryFailed(): int
    {
        return (int) Connection::instance()->exec(
            'UPDATE replication_queue SET status = "pending", error_message = NULL WHERE status = "failed"'
        );
    }
}
