<?php

declare(strict_types=1);

use App\Config\Env;
use App\Support\Logger;

/**
 * Global bootstrap for every entry point (web, api, cli).
 */

define('APP_START', microtime(true));
define('BASE_PATH', dirname(__DIR__));

// Composer autoload (preferred) with PSR-4 fallback.
$autoload = BASE_PATH . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'App\\')) {
            $relative = str_replace('\\', '/', substr($class, 4));
            $file = BASE_PATH . '/common/src/' . $relative . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

// Core helpers.
require BASE_PATH . '/common/helpers.php';

// Environment + config.
Env::load(BASE_PATH . '/config/.env');

// Runtime settings.
date_default_timezone_set((string) config('app.timezone'));

// Error handling.
error_reporting(E_ALL);
ini_set('display_errors', config('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/logs/php-error.log');

set_exception_handler(static function (Throwable $e): void {
    Logger::error('Uncaught exception', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, Logger::formatException($e) . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    if (config('app.debug')) {
        echo nl2br(e(Logger::formatException($e)));
    } else {
        echo '<h1>500 — Internal Server Error</h1><p>Something went wrong. The error has been logged.</p>';
    }
    exit(1);
});

return true;
