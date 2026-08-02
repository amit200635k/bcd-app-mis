<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Services\RoleDashboardService;

SessionAuth::requireAuth();
$user = SessionAuth::user();
$svc = new RoleDashboardService();
if ($svc->roleOf($user) !== 'block') {
    redirect($user->homeUrl());
}

$stats = $svc->stats($user);
ob_start();
echo view('partials/role_dashboard', [
    'role'      => 'block',
    'pageTitle' => 'Block Dashboard',
    'stats'     => $stats,
    'user'      => $user,
]);
$content = ob_get_clean();

echo view('layout', [
    'title'   => 'Block Dashboard',
    'content' => $content,
    'user'    => $user,
    'page'    => 'dashboard',
]);
