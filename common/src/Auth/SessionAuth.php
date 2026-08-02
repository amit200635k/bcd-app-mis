<?php

declare(strict_types=1);

namespace App\Auth;

use App\Audit\AuditLog;
use App\Database\Connection;
use App\Models\User;
use App\Security\Password;
use RuntimeException;

/**
 * Session-based authentication for the MIS/Admin portals.
 */
final class SessionAuth
{
    private const SESSION_KEY = 'bcd_user';
    private const MAX_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;

    public static function attempt(string $username, string $password, bool $remember = false): bool
    {
        $pdo = Connection::instance();

        $stmt = $pdo->prepare(
            'SELECT * FROM users WHERE username = :u AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();

        if ($row === false) {
            self::sleep();
            AuditLog::record('auth.login_failed', 'auth', 'user', null, [], ['username' => $username]);
            return false;
        }

        // Locked account check.
        if (isset($row['locked_until']) && $row['locked_until'] !== null) {
            if (strtotime((string) $row['locked_until']) > time()) {
                self::sleep();
                AuditLog::record('auth.login_blocked', 'auth', 'user', (string) $row['id'], [], ['username' => $username], (int) $row['id']);
                return false;
            }
            // Lock expired → reset attempts.
            self::resetLock((int) $row['id']);
            $row['login_attempts'] = 0;
        }

        if ($row['status'] !== 'active' || !Password::verify($password, (string) $row['password_hash'])) {
            $locked = self::incrementAttempts((int) $row['id'], $row['login_attempts']);
            self::sleep();
            AuditLog::record(
                $locked ? 'auth.account_locked' : 'auth.login_failed',
                'auth', 'user', (string) $row['id'], [], ['username' => $username], (int) $row['id']
            );
            return false;
        }

        // Success — reset attempts & timestamps.
        self::onSuccessfulLogin((int) $row['id']);
        AuditLog::record('auth.login', 'auth', 'user', (string) $row['id'], [], ['username' => $username], (int) $row['id']);

        $user = User::fromRow($row);
        if ($remember) {
            self::rememberUser($user);
        }
        $_SESSION[self::SESSION_KEY] = $user->id();

        return true;
    }

    public static function check(): bool
    {
        self::start();
        return isset($_SESSION[self::SESSION_KEY]);
    }

    public static function user(): ?User
    {
        self::start();
        $id = $_SESSION[self::SESSION_KEY] ?? null;
        if ($id === null) {
            return null;
        }
        $user = User::find((int) $id);
        if ($user === null) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }
        // Validate session lifetime.
        $lifetime = config('app.session_lifetime', 7200);
        if (isset($_SESSION['_bcd_last_activity']) && (time() - (int) $_SESSION['_bcd_last_activity']) > $lifetime) {
            self::logout();
            return null;
        }
        $_SESSION['_bcd_last_activity'] = time();
        return $user;
    }

    public static function id(): ?int
    {
        return self::user()?->id();
    }

    public static function logout(): void
    {
        self::start();
        $userId = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY], $_SESSION['_bcd_last_activity']);
        session_regenerate_id(true);
        if ($userId !== null) {
            AuditLog::record('auth.logout', 'auth', 'user', (string) $userId, [], [], (int) $userId);
        }
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            redirect('mis/login.php');
        }
    }

    public static function requirePermission(string $code): void
    {
        $user = self::user();
        if ($user === null || !$user->hasPermission($code)) {
            http_response_code(403);
            exit('403 — You do not have permission to access this page.');
        }
    }

    private static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    private static function rememberUser(User $user): void
    {
        $token = bin2hex(random_bytes(32));
        $pdo = Connection::instance();
        $stmt = $pdo->prepare(
            'INSERT INTO api_tokens (user_id, token_hash, name, expires_at) VALUES (:uid, :hash, :name, :exp)'
        );
        $stmt->execute([
            'uid'  => $user->id(),
            'hash' => hash('sha256', $token),
            'name' => 'remember-me',
            'exp'  => date('Y-m-d H:i:s', time() + 30 * 86400),
        ]);
        setcookie('bcd_remember', $token, [
            'expires'  => time() + 30 * 86400,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function incrementAttempts(int $userId, int $current): bool
    {
        $attempts = $current + 1;
        $lockedUntil = null;
        $locked = $attempts >= self::MAX_ATTEMPTS;
        if ($locked) {
            $lockedUntil = date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60);
            $attempts = 0;
        }
        $stmt = Connection::instance()->prepare(
            'UPDATE users SET login_attempts = :a, locked_until = :l WHERE id = :id'
        );
        $stmt->execute(['a' => $attempts, 'l' => $lockedUntil, 'id' => $userId]);
        return $locked;
    }

    private static function resetLock(int $userId): void
    {
        Connection::instance()->prepare('UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = :id')
            ->execute(['id' => $userId]);
    }

    private static function onSuccessfulLogin(int $userId): void
    {
        Connection::instance()->prepare(
            'UPDATE users SET login_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = :id'
        )->execute(['id' => $userId]);
    }

    private static function sleep(): void
    {
        usleep(random_int(200000, 400000));
    }
}
