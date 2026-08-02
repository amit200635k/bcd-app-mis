<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

/**
 * Password hashing wrapper (PHP native bcrypt/argon).
 */
final class Password
{
    private const ALGO = PASSWORD_BCRYPT;
    private const COST = 12;

    public static function hash(string $plain): string
    {
        $hash = password_hash($plain, self::ALGO, ['cost' => self::COST]);
        if ($hash === false) {
            throw new RuntimeException('Unable to hash password.');
        }
        return $hash;
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::ALGO, ['cost' => self::COST]);
    }

    public static function meetsPolicy(string $plain): bool
    {
        $min = config('security.min_password_length', 8);
        if (strlen($plain) < $min) {
            return false;
        }
        return (bool) preg_match('/[A-Z]/', $plain)
            && (bool) preg_match('/[a-z]/', $plain)
            && (bool) preg_match('/[0-9]/', $plain);
    }
}
