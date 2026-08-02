<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Minimal .env loader.
 * Reads config/.env into a static store and exposes typed getters.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $items = [];

    /** @var string */
    private static string $path = '';

    public static function load(string $path): void
    {
        self::$path = $path;
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'");
            $value = self::substitute($value);
            self::$items[$key] = $value;
        }
    }

    private static function substitute(string $value): string
    {
        if (preg_match('/^\$\{([A-Z0-9_]+)\}$/', $value, $m)) {
            return (string) (self::get($m[1]) ?? getenv($m[1]));
        }
        return $value;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$items)) {
            return self::$items[$key];
        }
        $env = getenv($key);
        return $env !== false ? $env : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v !== null && $v !== '' ? (int) $v : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return self::$items;
    }
}
