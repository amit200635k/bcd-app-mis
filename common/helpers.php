<?php

declare(strict_types=1);

use App\Config\Env;

/** Absolute path to the project root. */
function base_path(string $path = ''): string
{
    return dirname(__DIR__) . ($path !== '' ? DIRECTORY_SEPARATOR . $path : '');
}

/** Load or return a config array from config/<file>.php */
function config(string $key, mixed $default = null): mixed
{
    $parts = explode('.', $key);
    $file = array_shift($parts);
    $path = base_path('config/' . $file . '.php');

    static $cache = [];
    if (!isset($cache[$file])) {
        $cache[$file] = is_file($path) ? (require $path) : [];
    }

    $value = $cache[$file];
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function env(string $key, ?string $default = null): ?string
{
    return Env::get($key, $default);
}

/** Render a view file from common/views. */
function view(string $name, array $data = []): string
{
    $file = base_path('common/views/' . $name . '.php');
    if (!is_file($file)) {
        throw new RuntimeException('View not found: ' . $name);
    }
    extract($data, EXTR_SKIP);
    ob_start();
    require $file;
    return (string) ob_get_clean();
}

/** e() — escape output for HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Flash message helpers (session-based). */
function flash(string $key, ?string $message = null): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $value;
}

/** Redirect to an app-relative path. */
function redirect(string $path): never
{
    header('Location: ' . config('app.url') . '/' . ltrim($path, '/'));
    exit;
}

/** Build an app-relative asset/url. */
function url(string $path = ''): string
{
    return config('app.url') . '/' . ltrim($path, '/');
}

/** Convert an error into a readable message. */
function exception_message(Throwable $e): string
{
    if (config('app.debug')) {
        return $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    }
    return 'An unexpected error occurred. Please try again.';
}
