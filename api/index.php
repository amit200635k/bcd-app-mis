<?php

declare(strict_types=1);

/**
 * REST API front controller.
 * All requests under /api are rewritten here via .htaccess.
 */

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$router = new Router();

// ---------- Authentication ----------
$router->post('/v1/auth/login', \App\Api\Controllers\AuthController::login(...));
$router->post('/v1/auth/refresh', \App\Api\Controllers\AuthController::refresh(...));
$router->post('/v1/auth/logout', \App\Api\Controllers\AuthController::logout(...));
$router->get('/v1/auth/me', \App\Api\Controllers\AuthController::me(...));

// ---------- Masters ----------
$router->get('/v1/masters/locations', \App\Api\Controllers\MasterController::locations(...));
$router->get('/v1/masters', \App\Api\Controllers\MasterController::index(...));

// ---------- System ----------
$router->get('/v1/health', \App\Api\Controllers\HealthController::check(...));
$router->get('/v1/version', \App\Api\Controllers\HealthController::version(...));

try {
    $path = Request::path();
    // Strip leading /api prefix if present.
    if (str_starts_with($path, '/api')) {
        $path = substr($path, 4) ?: '/';
    }
    $router->dispatch(Request::method(), $path);
} catch (Throwable $e) {
    \App\Support\Logger::error('API error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    Response::error(exception_message($e), 500);
}
