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
// Allow state_admin to access district dashboard for drill-down
if ($role !== 'district' && $role !== 'state_admin') {
    redirect($user->homeUrl());
}

// Get parent district ID for drill-down (state admin clicking on district)
$parentDistrictId = (int) ($_GET['district_id'] ?? 0);
$formId = isset($_GET['form_id']) && $_GET['form_id'] !== '' ? (int) $_GET['form_id'] : null;

$stats = $svc->stats($user);
$chartData = $svc->getChartData($user, 'district', $parentDistrictId, $formId);
$stats['chart'] = $chartData;
$stats['unit_id'] = $parentDistrictId;

// Load forms for filter dropdown
$pdo = Connection::instance();
$forms = $pdo->query("SELECT id, title FROM survey_forms WHERE status = 'published' AND is_active = 1 ORDER BY title")->fetchAll();
$stats['forms_list'] = $forms;
ob_start();
echo view('partials/role_dashboard', [
    'role'      => 'district',
    'pageTitle' => 'District Dashboard',
    'stats'     => $stats,
    'user'      => $user,
]);
$content = ob_get_clean();

echo view('layout', [
    'title'   => 'District Dashboard',
    'content' => $content,
    'user'    => $user,
    'page'    => 'dashboard',
]);
