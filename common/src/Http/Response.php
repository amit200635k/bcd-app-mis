<?php

declare(strict_types=1);

namespace App\Http;

/**
 * JSON response writer for the REST API layer.
 */
final class Response
{
    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200, bool $success = true): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['success' => $success] + $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    /** @param array<string,mixed> $data */
    public static function ok(array $data = []): never
    {
        self::json(['data' => $data], 200, true);
    }

    public static function created(array $data = []): never
    {
        self::json(['data' => $data], 201, true);
    }

    /** @param array<string,string> $errors */
    public static function error(string $message, int $status = 400, array $errors = []): never
    {
        self::json(['message' => $message, 'errors' => $errors], $status, false);
    }

    public static function notFound(string $message = 'Resource not found.'): never
    {
        self::error($message, 404);
    }

    public static function unauthorized(string $message = 'Unauthorized.'): never
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden.'): never
    {
        self::error($message, 403);
    }

    public static function validation(array $errors, string $message = 'Validation failed.'): never
    {
        self::error($message, 422, $errors);
    }
}
