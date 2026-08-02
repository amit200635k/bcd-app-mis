<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;

SessionAuth::requireAuth();

$user = SessionAuth::user();
$pdo = \App\Database\Connection::instance();

// Dashboard stats scoped to the user's admin boundary.
$scope = $user->scope();

$where = '1=1';
$params = [];
if ($scope['district_id']) {
    $where .= ' AND district_id = :district_id';
    $params['district_id'] = $scope['district_id'];
}
if ($scope['block_id']) {
    $where .= ' AND block_id = :block_id';
    $params['block_id'] = $scope['block_id'];
}

$stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM users WHERE status='active' AND deleted_at IS NULL AND {$where}");
$stmt->execute($params);
$stats['users'] = (int) $stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM survey_forms WHERE status='published' AND is_active=1");
$stmt->execute();
$stats['forms'] = (int) $stmt->fetch()['c'];

$stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM survey_records');
$stmt->execute();
$stats['records'] = (int) $stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT status, COUNT(*) AS c FROM survey_records GROUP BY status");
$stmt->execute();
$byStatus = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT f.title, COUNT(r.id) AS total
     FROM survey_forms f
     LEFT JOIN survey_records r ON r.form_id = f.id
     WHERE f.status = "published"
     GROUP BY f.id, f.title
     ORDER BY total DESC
     LIMIT 5'
);
$stmt->execute();
$topForms = $stmt->fetchAll();

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-speedometer2 me-2"></i>Dashboard</h4>
    <span class="text-muted small"><?= date('d M Y, h:i A') ?></span>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi bi-people fs-1 text-primary"></i>
                <div>
                    <div class="fs-4 fw-bold"><?= $stats['users'] ?></div>
                    <div class="text-muted small">Active Users</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi bi-ui-checks fs-1 text-success"></i>
                <div>
                    <div class="fs-4 fw-bold"><?= $stats['forms'] ?></div>
                    <div class="text-muted small">Published Forms</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi bi-clipboard-check fs-1 text-info"></i>
                <div>
                    <div class="fs-4 fw-bold"><?= number_format($stats['records']) ?></div>
                    <div class="text-muted small">Total Records</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi bi-cloud-arrow-up fs-1 text-warning"></i>
                <div>
                    <div class="fs-4 fw-bold">0</div>
                    <div class="text-muted small">Pending Sync</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Records by Status</div>
            <div class="card-body">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead>
                    <tbody>
                    <?php if ($byStatus === []): ?>
                        <tr><td colspan="2" class="text-muted text-center">No records yet.</td></tr>
                    <?php else: foreach ($byStatus as $row): ?>
                        <tr>
                            <td><span class="badge bg-secondary"><?= e($row['status']) ?></span></td>
                            <td class="text-end"><?= number_format((int) $row['c']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Top Forms by Records</div>
            <div class="card-body">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Form</th><th class="text-end">Records</th></tr></thead>
                    <tbody>
                    <?php if ($topForms === []): ?>
                        <tr><td colspan="2" class="text-muted text-center">No forms yet.</td></tr>
                    <?php else: foreach ($topForms as $row): ?>
                        <tr>
                            <td><?= e($row['title']) ?></td>
                            <td class="text-end"><?= number_format((int) $row['total']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('layout', [
    'title'   => 'Dashboard',
    'content' => $content,
    'user'    => $user,
    'page'    => 'dashboard',
]);
