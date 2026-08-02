<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;

/**
 * In-app + push notification delivery.
 */
final class NotificationService
{
    /** Create a notification targeting a role, a user, or all. */
    public function send(
        string $title,
        string $body,
        ?int $targetRoleId = null,
        ?int $targetUserId = null,
        int $createdBy = 0,
        string $type = 'info'
    ): int {
        $pdo = Connection::instance();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO notifications (title, body, type, target_role_id, target_user_id, created_by)
                 VALUES (:t, :b, :ty, :r, :u, :cb)'
            );
            $stmt->execute([
                't'  => $title,
                'b'  => $body,
                'ty' => $type,
                'r'  => $targetRoleId,
                'u'  => $targetUserId,
                'cb' => $createdBy,
            ]);
            $notificationId = (int) $pdo->lastInsertId();

            // Resolve recipients.
            $sql = 'SELECT id FROM users WHERE deleted_at IS NULL AND status = "active"';
            $params = [];
            if ($targetRoleId !== null) {
                $sql .= ' AND id IN (SELECT user_id FROM user_roles WHERE role_id = :r)';
                $params['r'] = $targetRoleId;
            }
            if ($targetUserId !== null) {
                $sql .= ' AND id = :u';
                $params['u'] = $targetUserId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $userIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $ins = $pdo->prepare(
                'INSERT IGNORE INTO notification_recipients (notification_id, user_id) VALUES (:n, :u)'
            );
            foreach ($userIds as $uid) {
                $ins->execute(['n' => $notificationId, 'u' => (int) $uid]);
            }

            $pdo->commit();
            return $notificationId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function forUser(int $userId, int $limit = 20): array
    {
        $stmt = Connection::instance()->prepare(
            'SELECT n.id, n.title, n.body, n.type, n.created_at, nr.is_read, nr.read_at
             FROM notifications n
             JOIN notification_recipients nr ON nr.notification_id = n.id
             WHERE nr.user_id = :u
             ORDER BY n.created_at DESC
             LIMIT :l'
        );
        $stmt->bindValue(':u', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':l', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function unreadCount(int $userId): int
    {
        $stmt = Connection::instance()->prepare(
            'SELECT COUNT(*) FROM notification_recipients WHERE user_id = :u AND is_read = 0'
        );
        $stmt->execute(['u' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function markRead(int $notificationId, int $userId): void
    {
        Connection::instance()->prepare(
            'UPDATE notification_recipients SET is_read = 1, read_at = NOW()
             WHERE notification_id = :n AND user_id = :u'
        )->execute(['n' => $notificationId, 'u' => $userId]);
    }
}
