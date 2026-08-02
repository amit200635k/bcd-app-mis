<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Models\User;
use App\Security\Password;
use PDO;
use RuntimeException;

/**
 * User administration with RBAC assignment and hierarchy scoping.
 */
final class UserService
{
    /** Hierarchy level order: higher number = lower in the chain. */
    private const HIERARCHY = ['district' => 1, 'block' => 2, 'panchayat' => 3, 'village' => 4, 'surveyor' => 5];

    /**
     * List users. When an actor is provided, the result is restricted to the
     * hierarchy scope of that actor (state admin sees everyone).
     */
    public function list(?string $search = '', int $page = 1, int $perPage = 25, ?User $actor = null): array
    {
        $pdo = Connection::instance();
        $where = 'u.deleted_at IS NULL';
        $params = [];

        [$scopeSql, $scopeParams] = $actor !== null ? $this->scopeConditions($actor) : ['', []];
        if ($scopeSql !== '') {
            $where .= ' AND ' . $scopeSql;
            $params += $scopeParams;
        }

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
        if ($row === false) {
            return null;
        }
        $row['portals'] = $this->portalsOf($id);
        $row['form_ids'] = $this->formsOf($id);
        return $row;
    }

    public function create(array $data, int $createdBy, ?User $actor = null): int
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

        if ($actor !== null) {
            $this->assertInScope($actor, $data);
            $this->assertRolesAssignable($actor, (array) ($data['roles'] ?? []));
        }

        // Raw password kept only for local/dev convenience; never in production.
        $plainPassword = config('app.env') !== 'production' ? $password : null;

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO users
                    (username, password_hash, plain_password, full_name, email, mobile, department_id, district_id, block_id, panchayat_id, village_id, status, created_by)
                 VALUES (:u, :p, :plain, :n, :e, :m, :dept, :dist, :block, :panch, :village, :s, :cb)'
            );
            $stmt->execute([
                'u' => $username,
                'p' => Password::hash($password),
                'plain' => $plainPassword,
                'n' => $data['full_name'],
                'e' => $data['email'] ?? null,
                'm' => $data['mobile'] ?? null,
                'dept' => ($data['department_id'] ?? '') !== '' ? $data['department_id'] : null,
                'dist' => ($data['district_id'] ?? '') !== '' ? $data['district_id'] : null,
                'block' => ($data['block_id'] ?? '') !== '' ? $data['block_id'] : null,
                'panch' => ($data['panchayat_id'] ?? '') !== '' ? $data['panchayat_id'] : null,
                'village' => ($data['village_id'] ?? '') !== '' ? $data['village_id'] : null,
                's' => $data['status'] ?? 'active',
                'cb' => $createdBy,
            ]);
            $userId = (int) $pdo->lastInsertId();
            $this->assignRoles($userId, (array) ($data['roles'] ?? []));
            $this->setPortals($userId, (array) ($data['portals'] ?? []), $createdBy);
            $this->setFormAccess($userId, (array) ($data['forms'] ?? []), $createdBy);
            $pdo->commit();
            return $userId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data, ?User $actor = null): void
    {
        $pdo = Connection::instance();

        if ($actor !== null) {
            $target = $this->find($id);
            if ($target === null) {
                throw new RuntimeException('User not found.');
            }
            $this->assertInScope($actor, array_merge($target, $data));
            $this->assertRolesAssignable($actor, (array) ($data['roles'] ?? []));
        }

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
                'dept' => ($data['department_id'] ?? '') !== '' ? $data['department_id'] : null,
                'dist' => ($data['district_id'] ?? '') !== '' ? $data['district_id'] : null,
                'block' => ($data['block_id'] ?? '') !== '' ? $data['block_id'] : null,
                'panch' => ($data['panchayat_id'] ?? '') !== '' ? $data['panchayat_id'] : null,
                'village' => ($data['village_id'] ?? '') !== '' ? $data['village_id'] : null,
                's' => $data['status'] ?? 'active',
                'id' => $id,
            ]);

            if (!empty($data['password'])) {
                if (!Password::meetsPolicy((string) $data['password'])) {
                    throw new RuntimeException('Password does not meet policy.');
                }
                $plainPassword = config('app.env') !== 'production' ? (string) $data['password'] : null;
                $pdo->prepare('UPDATE users SET password_hash = :p, plain_password = :plain WHERE id = :id')
                    ->execute(['p' => Password::hash((string) $data['password']), 'plain' => $plainPassword, 'id' => $id]);
            }

            $pdo->prepare('DELETE FROM user_roles WHERE user_id = :id')->execute(['id' => $id]);
            $this->assignRoles($id, (array) ($data['roles'] ?? []));
            if (isset($data['portals'])) {
                $this->setPortals($id, (array) $data['portals'], $actor?->id() ?? $id);
            }
            if (isset($data['forms'])) {
                $this->setFormAccess($id, (array) $data['forms'], $actor?->id() ?? $id);
            }
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

    // ---------- Portal & form access ----------

    /** Update only a user's portal + form access (leaves profile intact). */
    public function updateAccess(int $id, array $portals, array $formIds, int $grantedBy): void
    {
        $this->setPortals($id, $portals, $grantedBy);
        $this->setFormAccess($id, $formIds, $grantedBy);
    }

    /** Replace the portals granted to a user. */
    public function setPortals(int $userId, array $portals, int $grantedBy): void
    {
        $pdo = Connection::instance();
        $pdo->prepare('DELETE FROM user_portal_access WHERE user_id = :id')->execute(['id' => $userId]);
        $stmt = $pdo->prepare('INSERT INTO user_portal_access (user_id, portal, granted_by) VALUES (:u, :p, :g)');
        foreach (array_unique(array_map('strval', $portals)) as $portal) {
            if (in_array($portal, ['mis', 'admin'], true)) {
                $stmt->execute(['u' => $userId, 'p' => $portal, 'g' => $grantedBy]);
            }
        }
    }

    /** @return list<string> portals granted to the user. */
    public function portalsOf(int $userId): array
    {
        $stmt = Connection::instance()->prepare('SELECT portal FROM user_portal_access WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Replace the form grants for a user. */
    public function setFormAccess(int $userId, array $formIds, int $grantedBy): void
    {
        $pdo = Connection::instance();
        $pdo->prepare('DELETE FROM user_form_access WHERE user_id = :id')->execute(['id' => $userId]);
        $stmt = $pdo->prepare('INSERT INTO user_form_access (user_id, form_id, granted_by) VALUES (:u, :f, :g)');
        foreach (array_unique(array_filter(array_map('intval', $formIds))) as $formId) {
            $stmt->execute(['u' => $userId, 'f' => $formId, 'g' => $grantedBy]);
        }
    }

    /** @return list<int> form ids the user has explicit access to. */
    public function formsOf(int $userId): array
    {
        $stmt = Connection::instance()->prepare('SELECT form_id FROM user_form_access WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Published forms the given actor may grant to others (state admin → all). */
    public function assignableForms(User $actor): array
    {
        $pdo = Connection::instance();
        $rows = $pdo->query(
            'SELECT id, code, title FROM survey_forms WHERE status = "published" AND is_active = 1 ORDER BY title'
        )->fetchAll();
        if ($actor->isStateAdmin()) {
            return $rows;
        }
        $allowed = $actor->assignedFormIds();
        return array_values(array_filter($rows, static fn (array $f) => in_array((int) $f['id'], $allowed, true)));
    }

    /**
     * Roles the given actor is allowed to assign. Hierarchy roles may only
     * grant roles at or below their own level. State admins may assign all.
     */
    public function assignableRoles(User $actor): array
    {
        $roles = $this->roles();
        if ($actor->isStateAdmin()) {
            return $roles;
        }
        $myLevel = null;
        foreach ($actor->roleCodes() as $code) {
            if (isset(self::HIERARCHY[$code])) {
                $myLevel = $myLevel === null ? self::HIERARCHY[$code] : min($myLevel, self::HIERARCHY[$code]);
            }
        }
        if ($myLevel === null) {
            return [];
        }
        return array_values(array_filter(
            $roles,
            static fn (array $r) => isset(self::HIERARCHY[$r['code']]) && self::HIERARCHY[$r['code']] >= $myLevel
        ));
    }

    // ---------- Hierarchy scoping ----------

    /** @return array{string, array<string,mixed>} [scope_sql, params] for a non-state admin actor. */
    private function scopeConditions(User $actor): array
    {
        if ($actor->isStateAdmin()) {
            return ['', []];
        }
        $conds = [];
        $params = [];
        foreach (['district_id', 'block_id', 'panchayat_id', 'village_id'] as $col) {
            $val = (int) $actor->get($col);
            if ($val > 0) {
                $conds[] = "u.{$col} = :scope_{$col}";
                $params["scope_{$col}"] = $val;
            }
        }
        if ($conds === []) {
            // Scoped actor without any hierarchy anchor: only show their own account.
            return ['u.id = :scope_self', ['scope_self' => $actor->id()]];
        }
        return [implode(' AND ', $conds), $params];
    }

    /** Ensure the target record's hierarchy is within the actor's scope. */
    public function assertInScope(User $actor, array $data): void
    {
        if ($actor->isStateAdmin()) {
            return;
        }
        $scope = $actor->scope();
        foreach (['district_id', 'block_id', 'panchayat_id', 'village_id'] as $col) {
            $anchor = (int) ($scope[$col] ?? 0);
            if ($anchor > 0 && (int) ($data[$col] ?? 0) !== $anchor) {
                throw new RuntimeException('Cannot create/manage users outside your ' . str_replace('_id', '', $col) . ' scope.');
            }
        }
        // A scoped actor must always place the user at their own anchor level.
        if ((int) ($scope['district_id'] ?? 0) > 0 && empty($data['district_id'])) {
            throw new RuntimeException('A district is required within your scope.');
        }
    }

    /** Ensure the requested role ids may be assigned by the actor. */
    private function assertRolesAssignable(User $actor, array $roleIds): void
    {
        if ($actor->isStateAdmin()) {
            return;
        }
        $allowed = array_map('intval', array_column($this->assignableRoles($actor), 'id'));
        foreach (array_map('intval', $roleIds) as $rid) {
            if (!in_array($rid, $allowed, true)) {
                throw new RuntimeException('You cannot assign that role.');
            }
        }
    }
}
