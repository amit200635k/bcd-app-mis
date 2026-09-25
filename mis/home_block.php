<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Database\Connection;
use App\Services\RoleDashboardService;

SessionAuth::requireAuth();
$user = SessionAuth::user();
$svc = new RoleDashboardService();
$role = $svc->roleOf($user);
// Allow state_admin and district to access block dashboard for drill-down
if ($role !== 'block' && $role !== 'state_admin' && $role !== 'district') {
    redirect($user->homeUrl());
}

// Get parent block ID for drill-down (district/state clicking on block)
$parentBlockId = (int) ($_GET['block_id'] ?? 0);
$formId = isset($_GET['form_id']) && $_GET['form_id'] !== '' ? (int) $_GET['form_id'] : null;

$stats = $svc->stats($user);
$chartData = $svc->getChartData($user, 'block', $parentBlockId, $formId);
$stats['chart'] = $chartData;
$stats['unit_id'] = $parentBlockId;

// Load forms for filter dropdown
$pdo = Connection::instance();
$forms = $pdo->query("SELECT id, title FROM survey_forms WHERE status = 'published' AND is_active = 1 ORDER BY title")->fetchAll();
$stats['forms_list'] = $forms;
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
