<?php

declare(strict_types=1);

/** Single user JSON feed for the edit modal. */

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Services\UserService;

SessionAuth::requireAuth();
SessionAuth::requirePermission('users.manage');

$id = (int) ($_GET['id'] ?? 0);
$user = (new UserService())->find($id);
if ($user === null) {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'User not found.']);
    exit;
}
unset($user['password_hash']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($user);
