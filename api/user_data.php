<?php

declare(strict_types=1);

/** Single user JSON feed for the edit modal. */

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Services\UserService;

SessionAuth::requireAuth();
SessionAuth::requirePermission('users.manage');

$actor = SessionAuth::user();
$service = new UserService();

$id = (int) ($_GET['id'] ?? 0);
$user = $service->find($id);
if ($user === null) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'User not found.']);
    exit;
}

// Scoped actors may only fetch users within their scope.
try {
    $service->assertInScope($actor, $user);
} catch (Throwable) {
    header('Content-Type: application/json');
    http_response_code(403);
    echo json_encode(['error' => 'Not allowed to view this user.']);
    exit;
}

unset($user['password_hash'], $user['plain_password']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($user);
