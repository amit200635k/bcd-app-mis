<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Flat-file logger. Writes daily logs under /logs.
 */
final class Logger
{
    private static ?string $dir = null;

    public static function init(string $dir): void
    {
        self::$dir = rtrim($dir, '/\\');
        if (!is_dir(self::$dir) && !mkdir(self::$dir, 0775, true) && !is_dir(self::$dir)) {
            throw new RuntimeException('Unable to create log directory: ' . self::$dir);
        }
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARN', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write('DEBUG', $message, $context);
    }

    public static function write(string $level, string $message, array $context = []): void
    {
        $dir = self::$dir ?? BASE_PATH . '/logs';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $file = $dir . '/app-' . date('Y-m-d') . '.log';
        $line = sprintf(
            "[%s] %s: %s %s%s",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context !== [] ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
            PHP_EOL
        );
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function formatException(\Throwable $e): string
    {
        return sprintf(
            "%s: %s\nStack trace:\n%s",
            get_class($e),
            $e->getMessage(),
            $e->getTraceAsString()
        );
    }
}
