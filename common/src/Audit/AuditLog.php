<?php

declare(strict_types=1);

namespace App\Audit;

use App\Database\Connection;
use App\Http\Request;

/**
 * Writes entries to the audit_logs table.
 */
final class AuditLog
{
    public static function record(
        string $action,
        ?string $module = null,
        ?string $entityType = null,
        ?string $entityId = null,
        array $before = [],
        array $after = [],
        ?int $userId = null
    ): void {
        try {
            $pdo = Connection::instance();
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs
                    (user_id, action, module, entity_type, entity_id, before_json, after_json, ip_address, user_agent)
                 VALUES (:user_id, :action, :module, :entity_type, :entity_id, :before_json, :after_json, :ip, :ua)'
            );
            $stmt->execute([
                'user_id'      => $userId,
                'action'       => $action,
                'module'       => $module,
                'entity_type'  => $entityType,
                'entity_id'    => $entityId,
                'before_json'  => $before !== [] ? json_encode($before) : null,
                'after_json'   => $after !== [] ? json_encode($after) : null,
                'ip'           => Request::clientIp(),
                'ua'           => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 255),
            ]);
        } catch (\Throwable) {
            // Auditing must never break the main flow.
        }
    }
}
