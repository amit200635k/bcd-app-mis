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

    /** Generate a random password that meets the password policy. */
    public static function generate(int $length = 10): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnpqrstuvwxyz';
        $digits = '23456789';
        $pool = $upper . $lower . $digits;

        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];
        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $pool[random_int(0, strlen($pool) - 1)];
        }
        shuffle($chars);
        return implode('', $chars);
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
