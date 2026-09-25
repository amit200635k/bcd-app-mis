<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Database\Connection;
use App\Models\User;
use App\Services\RecordService;
use App\Services\ReportService;
use App\Services\RoleDashboardService;

SessionAuth::requireAuth();
SessionAuth::requirePermission('reports.view');

$user = SessionAuth::user();
$pdo = Connection::instance();
$reportService = new ReportService();
$dashboardService = new RoleDashboardService();
$role = $dashboardService->roleOf($user);
$scope = $user->scope();

// Get filter parameters
$formId = (int) ($_GET['form_id'] ?? 0);
$districtId = (int) ($_GET['district_id'] ?? 0);
$blockId = (int) ($_GET['block_id'] ?? 0);
$panchayatId = (int) ($_GET['panchayat_id'] ?? 0);
$villageId = (int) ($_GET['village_id'] ?? 0);
$status = (string) ($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

// Apply role-based default filters
if ($role === 'district' && !$districtId && !empty($scope['district_id'])) {
    $districtId = (int) $scope['district_id'];
}
if ($role === 'block' && !$blockId && !empty($scope['block_id'])) {
    $blockId = (int) $scope['block_id'];
}
if ($role === 'panchayat' && !$panchayatId && !empty($scope['panchayat_id'])) {
    $panchayatId = (int) $scope['panchayat_id'];
}
if ($role === 'village' && !$villageId && !empty($scope['village_id'])) {
    $villageId = (int) $scope['village_id'];
}

// Default to first accessible form if none selected
if ($formId === 0) {
    $forms = array_values(array_filter(
        $pdo->query("SELECT id, title FROM survey_forms WHERE status = 'published' AND is_active = 1 ORDER BY title")->fetchAll(),
        fn(array $f) => $user->canAccessForm((int) $f['id'])
    ));
    if ($forms !== []) {
        $formId = (int) $forms[0]['id'];
    }
}

// Verify form access
if ($formId > 0 && !$user->canAccessForm($formId)) {
    $formId = 0;
}

// Build filters array for ReportService
$filters = [
    'form_id'     => $formId,
    'status'      => $status,
    'district'    => '',
    'block'       => '',
    'date_from'   => '',
    'date_to'     => '',
    'surveyor_id' => 0,
    'keyword'     => '',
    'viewer'      => $user,
];

// Add location filters based on IDs
if ($districtId > 0) {
    $stmt = $pdo->prepare('SELECT name FROM districts WHERE id = :id');
    $stmt->execute(['id' => $districtId]);
    $filters['district'] = $stmt->fetchColumn() ?? '';
}
if ($blockId > 0) {
    $stmt = $pdo->prepare('SELECT name FROM blocks WHERE id = :id');
    $stmt->execute(['id' => $blockId]);
    $filters['block'] = $stmt->fetchColumn() ?? '';
}

// Get KPIs and data
$kpis = $formId > 0 ? $reportService->detailKpis($filters) : ['total' => 0, 'submitted' => 0, 'verified' => 0, 'approved' => 0, 'rejected' => 0, 'built_up_total' => 0, 'rooms_total' => 0, 'avg_built_up' => 0, 'districts' => 0, 'departments' => 0, 'categories' => 0];

// Get total count for pagination
$totalRecords = $kpis['total'];
$totalPages = (int) ceil($totalRecords / $perPage);
$offset = ($page - 1) * $perPage;

$rows = $formId > 0 ? $reportService->detailReport($filters, $perPage, $offset) : [];

$cols = $formId > 0 ? $reportService->detailColumns($formId) : [];

$statuses = RecordService::STATUSES;

// Get filter dropdown options
$districts = $formId > 0 ? $reportService->detailLocationDistinct('district', $formId, $user) : [];
$blocks = $formId > 0 ? $reportService->detailLocationDistinct('block', $formId, $user) : [];
$surveyors = $formId > 0 ? $reportService->detailSurveyors($formId, $user) : [];

$formsList = array_values(array_filter(
    $pdo->query("SELECT id, title, code FROM survey_forms WHERE status = 'published' ORDER BY title")->fetchAll(),
    static fn (array $f) => $user->canAccessForm((int) $f['id'])
));

$badges = [
    'draft' => 'secondary', 'submitted' => 'info', 'block_verified' => 'primary',
    'district_verified' => 'warning', 'approved' => 'success',
    'published' => 'success', 'rejected' => 'danger',
];

// Build current filter query string for pagination links
$currentQuery = $_GET;
unset($currentQuery['page']);

ob_start(); ?>
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h1 class="page-title mb-1"><i class="bi bi-table me-2"></i>Data View</h1>
        <div class="page-subtitle">Filtered records based on your role and selected criteria</div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end" id="filterForm">
            <input type="hidden" name="district_id" value="<?= $districtId ?>">
            <input type="hidden" name="block_id" value="<?= $blockId ?>">
            <input type="hidden" name="panchayat_id" value="<?= $panchayatId ?>">
            <input type="hidden" name="village_id" value="<?= $villageId ?>">
            
            <div class="col-md-3">
                <label class="form-label small mb-1">Form</label>
                <select name="form_id" class="form-select form-select-sm" id="formSelect">
                    <option value="0">— Select form —</option>
                    <?php foreach ($formsList as $f): ?>
                    <option value="<?= (int) $f['id'] ?>" <?= $formId === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <?php if ($role === 'state_admin' || $role === 'district'): ?>
            <div class="col-md-2">
                <label class="form-label small mb-1">District</label>
                <select name="district_id" class="form-select form-select-sm" id="districtSelect">
                    <option value="0">All Districts</option>
                    <?php foreach ($districts as $d): ?>
                    <option value="<?= e($d) ?>" <?= $filters['district'] === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <?php if (in_array($role, ['state_admin', 'district', 'block'], true)): ?>
            <div class="col-md-2">
                <label class="form-label small mb-1">Block</label>
                <select name="block_id" class="form-select form-select-sm" id="blockSelect">
                    <option value="0">All Blocks</option>
                    <?php foreach ($blocks as $b): ?>
                    <option value="<?= e($b) ?>" <?= $filters['block'] === $b ? 'selected' : '' ?>><?= e($b) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="col-md-2">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach ($statuses as $s): ?>
                    <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label small mb-1">Surveyor</label>
                <select name="surveyor_id" class="form-select form-select-sm">
                    <option value="0">All Surveyors</option>
                    <?php foreach ($surveyors as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= (int)($filters['surveyor_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label small mb-1">From Date</label>
                <input type="date" name="date_from" value="<?= e((string)($_GET['date_from'] ?? '')) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">To Date</label>
                <input type="date" name="date_to" value="<?= e((string)($_GET['date_to'] ?? '')) ?>" class="form-control form-control-sm">
            </div>
            
            <div class="col-md-3">
                <label class="form-label small mb-1">Search</label>
                <input type="text" name="keyword" value="<?= e((string)($_GET['keyword'] ?? '')) ?>" class="form-control form-control-sm" placeholder="Building name / code / UUID">
            </div>
            
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="data_view.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Reset</a>
            </div>
        </form>
    </div>
</div>

<?php if ($formId === 0): ?>
<div class="card">
    <div class="card-body text-center text-muted py-5">Select a survey form to view its records.</div>
</div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Total Records</div>
            <div class="fs-3 fw-bold"><?= number_format($kpis['total']) ?></div>
            <div class="small text-muted"><?= count($districts) ?> district<?= count($districts) === 1 ? '' : 's' ?> covered</div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Submitted</div>
            <div class="fs-3 fw-bold text-info"><?= number_format($kpis['submitted']) ?></div>
            <div class="small text-muted">awaiting verification</div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Verified</div>
            <div class="fs-3 fw-bold text-primary"><?= number_format($kpis['verified']) ?></div>
            <div class="small text-muted">block / district verified</div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Approved / Published</div>
            <div class="fs-3 fw-bold text-success"><?= number_format($kpis['approved']) ?></div>
            <div class="small text-muted">final status</div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Rejected</div>
            <div class="fs-3 fw-bold text-danger"><?= number_format($kpis['rejected']) ?></div>
            <div class="small text-muted">sent back for re-survey</div>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-buildings me-2"></i>Records (<?= number_format($kpis['total']) ?>)</span>
        <div class="d-flex gap-2">
            <span class="text-muted small align-self-center">Page <?= $page ?> of <?= max(1, $totalPages) ?></span>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($currentQuery, ['page' => $page - 1])) ?>"><i class="bi bi-chevron-left"></i></a>
                    </li>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($currentQuery, ['page' => $i])) ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($currentQuery, ['page' => $page + 1])) ?>"><i class="bi bi-chevron-right"></i></a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-sm table-hover align-middle mb-0 data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Building Name</th>
                    <th>Code</th>
                    <?php foreach ($cols as $k => $label): if ($k === 'building_name' || $k === 'building_code') { continue; } ?>
                    <th><?= e($label) ?></th>
                    <?php endforeach; ?>
                    <th>Surveyor</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
             <tbody>
             <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="text-muted small">#<?= (int) $r['id'] ?></td>
                    <td class="fw-semibold"><a class="text-decoration-none" href="records.php?id=<?= (int) $r['id'] ?>"><?= e((string) ($r['building_name'] ?? '—')) ?></a></td>
                    <td class="small"><?= e((string) ($r['building_code'] ?? '—')) ?></td>
                    <?php foreach ($cols as $k => $label): if ($k === 'building_name' || $k === 'building_code') { continue; } ?>
                        <?php
                        $v = $r[$k] ?? '';
                        if ($v !== '' && $v !== null && is_numeric($v)) {
                            $f = (float) $v;
                            $v = ($f == floor($f)) ? (string) (int) $f : number_format($f, 1);
                        }
                        ?>
                        <td class="small"><?= e($v === '' || $v === null ? '—' : (string) $v) ?></td>
                    <?php endforeach; ?>
                    <td class="small"><?= e((string) ($r['surveyor'] ?? '—')) ?></td>
                    <td><span class="badge bg-<?= $badges[$r['status']] ?? 'secondary' ?>"><?= e(ucwords(str_replace('_', ' ', (string) $r['status']))) ?></span></td>
                    <td class="small text-muted"><?= date('d M Y', strtotime((string) $r['created_at'])) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="records.php?id=<?= (int) $r['id'] ?>" title="View"><i class="bi bi-eye"></i></a></td>
                </tr>
             <?php endforeach; ?>
             <?php if ($rows === []): ?>
                <tr><td colspan="100" class="text-center text-muted py-4">No records found matching the criteria.</td></tr>
             <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php $content = ob_get_clean();

echo view('layout', [
    'title'      => 'Data View',
    'content'    => $content,
    'user'       => $user,
    'page'       => 'data_view',
    'breadcrumb' => [['MIS', $user->homeUrl()], ['Data View', '']],
]);