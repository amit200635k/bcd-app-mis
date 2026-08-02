<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Database\Connection;

SessionAuth::requireAuth();

$user = SessionAuth::user();
if (!$user->isStateAdmin()) {
    exit('403');
}

$pdo = Connection::instance();

if (($_GET['action'] ?? '') === 'retry') {
    $affected = (new \App\Services\ReplicationService())->retryFailed();
    flash('success', "Re-queued {$affected} failed job(s).");
    redirect('admin/replication.php');
}

if (($_GET['action'] ?? '') === 'drain') {
    $service = new \App\Services\ReplicationService();
    $count = 0;
    while ($service->processOne(fn() => true)) {
        $count++;
    }
    flash('success', "Processed {$count} job(s).");
    redirect('admin/replication.php');
}

$queue = $pdo->query(
    'SELECT id, entity_type, entity_id, operation, status, attempt_count, error_message, created_at, processed_at
     FROM replication_queue ORDER BY id DESC LIMIT 30'
)->fetchAll();
$configs = $pdo->query('SELECT id, name, db_type, host, database_name, enabled, last_success_at FROM external_db_configs ORDER BY id')->fetchAll();

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>Replication Monitor</h4>
    <div>
        <a href="replication.php?action=retry" class="btn btn-sm btn-outline-danger" onclick="return confirm('Re-queue all failed jobs?')"><i class="bi bi-arrow-counterclockwise me-1"></i>Retry Failed</a>
        <a href="replication.php?action=drain" class="btn btn-sm btn-dark" onclick="return confirm('Drain the queue now?')"><i class="bi bi-play-fill me-1"></i>Drain Queue</a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">External Database Targets</div>
    <div class="card-body table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Name</th><th>Type</th><th>Host</th><th>Database</th><th>Status</th><th>Last Success</th></tr></thead>
            <tbody>
            <?php if ($configs === []): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No external database configured.</td></tr>
            <?php else: foreach ($configs as $c): ?>
                <tr>
                    <td><?= e($c['name']) ?></td>
                    <td><span class="badge bg-secondary text-uppercase"><?= e($c['db_type']) ?></span></td>
                    <td><?= e($c['host']) ?></td>
                    <td><?= e((string) ($c['database_name'] ?? '—')) ?></td>
                    <td><?= $c['enabled'] ? '<span class="badge bg-success">enabled</span>' : '<span class="badge bg-secondary">disabled</span>' ?></td>
                    <td class="text-muted small"><?= $c['last_success_at'] ? date('d M H:i', strtotime((string) $c['last_success_at'])) : '—' ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Replication Queue (last 30)</div>
    <div class="card-body table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>#</th><th>Entity</th><th>Op</th><th>Status</th><th>Attempts</th><th>Error</th><th>Created</th></tr></thead>
            <tbody>
            <?php if ($queue === []): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Queue is empty.</td></tr>
            <?php else: foreach ($queue as $q): ?>
                <tr>
                    <td><?= (int) $q['id'] ?></td>
                    <td><code><?= e($q['entity_type']) ?> #<?= e($q['entity_id']) ?></code></td>
                    <td><span class="badge bg-info text-dark"><?= e($q['operation']) ?></span></td>
                    <td>
                        <?php
                        $b = ['pending' => 'secondary', 'processing' => 'warning', 'success' => 'success', 'failed' => 'danger'][$q['status']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $b ?>"><?= e($q['status']) ?></span>
                    </td>
                    <td><?= (int) $q['attempt_count'] ?></td>
                    <td class="text-muted small" title="<?= e((string) $q['error_message']) ?>"><?= e(mb_strimwidth((string) ($q['error_message'] ?? '—'), 0, 40, '…')) ?></td>
                    <td class="text-muted small"><?= date('d M H:i', strtotime((string) $q['created_at'])) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('admin_layout', [
    'title'   => 'Replication Monitor',
    'content' => $content,
    'user'    => $user,
    'page'    => 'replication',
]);
