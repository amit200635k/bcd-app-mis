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
// Allow state_admin, district, block to access panchayat dashboard for drill-down
if ($role !== 'panchayat' && $role !== 'state_admin' && $role !== 'district' && $role !== 'block') {
    redirect($user->homeUrl());
}

// Get parent panchayat ID for drill-down (block/district/state clicking on panchayat)
$parentPanchayatId = (int) ($_GET['panchayat_id'] ?? 0);
$formId = isset($_GET['form_id']) && $_GET['form_id'] !== '' ? (int) $_GET['form_id'] : null;

$stats = $svc->stats($user);
$chartData = $svc->getChartData($user, 'panchayat', $parentPanchayatId, $formId);
$stats['chart'] = $chartData;
$stats['unit_id'] = $parentPanchayatId;

// Load forms for filter dropdown
$pdo = Connection::instance();
$forms = $pdo->query("SELECT id, title FROM survey_forms WHERE status = 'published' AND is_active = 1 ORDER BY title")->fetchAll();
$stats['forms_list'] = $forms;
ob_start();
echo view('partials/role_dashboard', [
    'role'      => 'panchayat',
    'pageTitle' => 'Panchayat Dashboard',
    'stats'     => $stats,
    'user'      => $user,
]);
$content = ob_get_clean();

echo view('layout', [
    'title'   => 'Panchayat Dashboard',
    'content' => $content,
    'user'    => $user,
    'page'    => 'dashboard',
]);
