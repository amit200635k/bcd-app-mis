<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Database\Connection;

SessionAuth::requireAuth();

$user = SessionAuth::user();
if (!$user->isStateAdmin()) {
    http_response_code(403);
    exit('403 — Admin panel requires the state_admin role.');
}

$pdo = Connection::instance();

$stats = [
    'users'       => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')->fetchColumn(),
    'districts'   => (int) $pdo->query('SELECT COUNT(*) FROM districts')->fetchColumn(),
    'villages'    => (int) $pdo->query('SELECT COUNT(*) FROM villages')->fetchColumn(),
    'forms'       => (int) $pdo->query('SELECT COUNT(*) FROM survey_forms')->fetchColumn(),
    'records'     => (int) $pdo->query('SELECT COUNT(*) FROM survey_records')->fetchColumn(),
    'audit'       => (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn(),
    'replPending' => (int) $pdo->query('SELECT COUNT(*) FROM replication_queue WHERE status IN ("pending","processing")')->fetchColumn(),
    'replFailed'  => (int) $pdo->query('SELECT COUNT(*) FROM replication_queue WHERE status = "failed"')->fetchColumn(),
];

$recentAudit = $pdo->query(
    'SELECT a.id, a.action, a.module, a.ip_address, u.full_name AS user, a.created_at
     FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.id DESC LIMIT 8'
)->fetchAll();

ob_start(); ?>
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h1 class="page-title mb-1"><i class="bi bi-speedometer2 me-2"></i>Dashboard</h1>
        <div class="page-subtitle">System overview &amp; recent activity</div>
    </div>
    <span class="text-muted small mt-1 d-none d-md-block"><?= date('d M Y, h:i A') ?></span>
</div>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Users', $stats['users'], 'bi-people', 'primary'],
        ['Districts', $stats['districts'], 'bi-map', 'success'],
        ['Villages', $stats['villages'], 'bi-house-heart', 'info'],
        ['Survey Forms', $stats['forms'], 'bi-ui-checks', 'warning'],
        ['Records', number_format($stats['records']), 'bi-clipboard-check', 'info'],
        ['Audit Entries', $stats['audit'], 'bi-journal-text', 'secondary'],
        ['Replication Pending', $stats['replPending'], 'bi-arrow-repeat', 'danger'],
        ['Replication Failed', $stats['replFailed'], 'bi-exclamation-triangle', 'danger'],
    ];
    foreach ($cards as [$label, $val, $icon, $color]): ?>
    <div class="col-md-3 col-sm-6">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-<?= $color ?> bg-opacity-10 text-<?= $color ?>">
                    <i class="bi <?= $icon ?>"></i>
                </div>
                <div>
                    <div class="stat-value"><?= e((string) $val) ?></div>
                    <div class="stat-label"><?= e($label) ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Recent Audit Activity</span>
        <a href="audit.php" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-hover align-middle mb-0 data-table">
            <thead><tr><th>#</th><th>Action</th><th>Module</th><th>User</th><th>IP</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($recentAudit as $a): ?>
                <tr>
                    <td><?= (int) $a['id'] ?></td>
                    <td><code><?= e($a['action']) ?></code></td>
                    <td><?= e((string) ($a['module'] ?? '—')) ?></td>
                    <td><?= e((string) ($a['user'] ?? 'system')) ?></td>
                    <td class="text-muted"><?= e((string) ($a['ip_address'] ?? '—')) ?></td>
                    <td class="text-muted small"><?= date('d M H:i:s', strtotime((string) $a['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('admin_layout', [
    'title'      => 'Admin Dashboard',
    'content'    => $content,
    'user'       => $user,
    'page'       => 'dashboard',
    'breadcrumb' => [['Dashboard', '']],
]);