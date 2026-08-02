<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Immutable HTTP request wrapper.
 */
final class Request
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = (string) config('app.base_path');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base)) ?: '/';
        }
        return $uri === '' ? '/' : $uri;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $data = self::json();
        if (is_array($data) && array_key_exists($key, $data)) {
            return $data[$key];
        }
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    /** @return array<string,mixed> merged JSON + POST + GET */
    public static function all(): array
    {
        $json = self::json();
        return array_merge($_GET, $_POST, is_array($json) ? $json : []);
    }

    /** @return array<string,mixed>|null parsed JSON body */
    public static function json(): ?array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public static function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? $default;
    }

    public static function bearerToken(): ?string
    {
        $auth = self::header('Authorization');
        if ($auth !== null && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function isAjax(): bool
    {
        return strtolower((string) self::header('X-Requested-With')) === 'xmlhttprequest';
    }
}
