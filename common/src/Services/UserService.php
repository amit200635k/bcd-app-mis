<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Security\Password;
use RuntimeException;

/**
 * User administration with RBAC assignment and hierarchy scoping.
 */
final class UserService
{
    public function list(?string $search = '', int $page = 1, int $perPage = 25): array
    {
        $pdo = Connection::instance();
        $where = 'u.deleted_at IS NULL';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (u.full_name LIKE :q OR u.username LIKE :q OR u.mobile LIKE :q)';
            $params['q'] = '%' . $search . '%';
        }
        $offset = max(0, ($page - 1) * $perPage);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE {$where}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT u.*, GROUP_CONCAT(DISTINCT r.code) AS roles
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE {$where}
             GROUP BY u.id
             ORDER BY u.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return ['total' => $total, 'page' => $page, 'users' => $stmt->fetchAll()];
    }

    public function find(int $id): ?array
    {
        $pdo = Connection::instance();
        $stmt = $pdo->prepare(
            'SELECT u.*, GROUP_CONCAT(DISTINCT r.id) AS role_ids
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.id = :id AND u.deleted_at IS NULL
             GROUP BY u.id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(array $data, int $createdBy): int
    {
        $pdo = Connection::instance();
        $username = strtolower(trim((string) $data['username']));
        $password = (string) ($data['password'] ?? '');
        if ($password === '') {
            $password = 'Welcome@123';
        }
        if (!Password::meetsPolicy($password)) {
            throw new RuntimeException('Password must contain upper, lower, digit and be at least 8 chars.');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users
                    (username, password_hash, full_name, email, mobile, department_id, district_id, block_id, panchayat_id, village_id, status, created_by)
                 VALUES (:u, :p, :n, :e, :m, :dept, :dist, :block, :panch, :village, :s, :cb)'
            );
            $stmt->execute([
                'u' => $username,
                'p' => Password::hash($password),
                'n' => $data['full_name'],
                'e' => $data['email'] ?? null,
                'm' => $data['mobile'] ?? null,
                'dept' => $data['department_id'] ?: null,
                'dist' => $data['district_id'] ?: null,
                'block' => $data['block_id'] ?: null,
                'panch' => $data['panchayat_id'] ?: null,
                'village' => $data['village_id'] ?: null,
                's' => $data['status'] ?? 'active',
                'cb' => $createdBy,
            ]);
            $userId = (int) $pdo->lastInsertId();
            $this->assignRoles($userId, (array) ($data['roles'] ?? []));
            $pdo->commit();
            return $userId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): void
    {
        $pdo = Connection::instance();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE users SET full_name = :n, email = :e, mobile = :m, department_id = :dept,
                        district_id = :dist, block_id = :block, panchayat_id = :panch, village_id = :village, status = :s
                 WHERE id = :id'
            );
            $stmt->execute([
                'n' => $data['full_name'],
                'e' => $data['email'] ?? null,
                'm' => $data['mobile'] ?? null,
                'dept' => $data['department_id'] ?: null,
                'dist' => $data['district_id'] ?: null,
                'block' => $data['block_id'] ?: null,
                'panch' => $data['panchayat_id'] ?: null,
                'village' => $data['village_id'] ?: null,
                's' => $data['status'] ?? 'active',
                'id' => $id,
            ]);

            if (!empty($data['password'])) {
                if (!Password::meetsPolicy((string) $data['password'])) {
                    throw new RuntimeException('Password does not meet policy.');
                }
                $pdo->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
                    ->execute(['p' => Password::hash((string) $data['password']), 'id' => $id]);
            }

            $pdo->prepare('DELETE FROM user_roles WHERE user_id = :id')->execute(['id' => $id]);
            $this->assignRoles($id, (array) ($data['roles'] ?? []));
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function deactivate(int $id): void
    {
        Connection::instance()->prepare('UPDATE users SET status = "inactive", deleted_at = NOW() WHERE id = :id')
            ->execute(['id' => $id]);
    }

    private function assignRoles(int $userId, array $roleIds): void
    {
        $stmt = Connection::instance()->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:u, :r)');
        foreach ($roleIds as $roleId) {
            if ((int) $roleId > 0) {
                $stmt->execute(['u' => $userId, 'r' => (int) $roleId]);
            }
        }
    }

    public function roles(): array
    {
        return Connection::instance()->query('SELECT id, code, name FROM roles ORDER BY id')->fetchAll();
    }
}
