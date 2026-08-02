<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Connection;
use PDO;

/**
 * User model with RBAC and hierarchy helpers.
 */
final class User
{
    private array $roles = [];
    private array $permissions = [];
    private array $portals = [];

    private function __construct(private array $data)
    {
        $this->loadAccess();
    }

    /** Build a User instance from an already-fetched users row. */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public static function find(int $id): ?self
    {
        $stmt = Connection::instance()->prepare('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : new self($row);
    }

    public static function findByUsername(string $username): ?self
    {
        $stmt = Connection::instance()->prepare('SELECT * FROM users WHERE username = :u AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();
        return $row === false ? null : new self($row);
    }

    public static function findByCredentials(string $username, string $password): ?self
    {
        $user = self::findByUsername($username);
        if ($user === null || $user->get('status') !== 'active') {
            return null;
        }
        if (!\App\Security\Password::verify($password, (string) $user->get('password_hash'))) {
            return null;
        }
        return $user;
    }

    private function loadAccess(): void
    {
        $pdo = Connection::instance();

        $stmt = $pdo->prepare(
            'SELECT r.code, r.name FROM roles r
             JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = :id'
        );
        $stmt->execute(['id' => $this->id()]);
        $this->roles = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT DISTINCT p.code FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = :id'
        );
        $stmt->execute(['id' => $this->id()]);
        $this->permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $pdo->prepare(
            'SELECT portal FROM user_portal_access WHERE user_id = :id'
        );
        $stmt->execute(['id' => $this->id()]);
        $this->portals = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function id(): int
    {
        return (int) $this->data['id'];
    }

    public function username(): string
    {
        return (string) $this->data['username'];
    }

    public function fullName(): string
    {
        return (string) $this->data['full_name'];
    }

    public function email(): ?string
    {
        return $this->data['email'] ?? null;
    }

    public function mobile(): ?string
    {
        return $this->data['mobile'] ?? null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $data = $this->data;
        unset($data['password_hash']);
        $data['roles'] = array_column($this->roles, 'code');
        return $data;
    }

    /** @return list<string> */
    public function roleCodes(): array
    {
        return array_column($this->roles, 'code');
    }

    public function hasRole(string $code): bool
    {
        return in_array($code, $this->roleCodes(), true);
    }

    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->permissions, true);
    }

    /** @return list<string> */
    public function portals(): array
    {
        return $this->portals;
    }

    /** Whether the user may access the given portal. State admins are implicitly granted all. */
    public function hasPortal(string $portal): bool
    {
        return $this->isStateAdmin() || in_array($portal, $this->portals, true);
    }

    /** @return list<int> form ids this user is explicitly granted access to. */
    public function assignedFormIds(): array
    {
        $stmt = Connection::instance()->prepare(
            'SELECT form_id FROM user_form_access WHERE user_id = :id'
        );
        $stmt->execute(['id' => $this->id()]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Whether the user may fill/view the given form. State admins implicitly have all forms. */
    public function canAccessForm(int $formId): bool
    {
        if ($this->isStateAdmin()) {
            return true;
        }
        return in_array($formId, $this->assignedFormIds(), true);
    }

    public function isStateAdmin(): bool
    {
        return $this->hasRole('state_admin');
    }

    /** Landing page for this user's role (login redirect + sidebar home). */
    public function homeUrl(): string
    {
        if ($this->isStateAdmin()) {
            return 'mis/dashboard.php';
        }
        foreach (['district' => 'home_district', 'block' => 'home_block', 'panchayat' => 'home_panchayat', 'village' => 'home_village'] as $role => $page) {
            if ($this->hasRole($role)) {
                return 'mis/' . $page . '.php';
            }
        }
        if ($this->hasRole('surveyor')) {
            return 'mis/home_surveyor.php';
        }
        return 'mis/dashboard.php';
    }

    /** Scope hierarchy: lowest admin unit this user belongs to (district_id, block_id, etc.). */
    public function scope(): array
    {
        return [
            'district_id'  => $this->data['district_id'] ?? null,
            'block_id'     => $this->data['block_id'] ?? null,
            'panchayat_id' => $this->data['panchayat_id'] ?? null,
            'village_id'   => $this->data['village_id'] ?? null,
            'department_id'=> $this->data['department_id'] ?? null,
        ];
    }
}
