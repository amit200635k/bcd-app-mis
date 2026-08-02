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
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$action = trim((string) ($_GET['action'] ?? ''));

$where = '1=1';
$params = [];
if ($action !== '') {
    $where .= ' AND a.action LIKE :a';
    $params['a'] = '%' . $action . '%';
}

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a WHERE {$where}");
$totalStmt->execute($params);
$total = (int) $totalStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT a.*, u.full_name AS user
     FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
     WHERE {$where}
     ORDER BY a.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute($params);
$logs = $stmt->fetchAll();

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-journal-text me-2"></i>Audit Logs</h4>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-md-4">
                <input type="text" name="action" class="form-control form-control-sm" value="<?= e($action) ?>" placeholder="Filter by action…">
            </div>
            <div class="col-md-2"><button class="btn btn-sm btn-dark w-100">Filter</button></div>
        </form>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>#</th><th>User</th><th>Action</th><th>Module</th><th>Entity</th><th>IP</th><th>Time</th></tr></thead>
                <tbody>
                <?php if ($logs === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No audit logs.</td></tr>
                <?php else: foreach ($logs as $a): ?>
                    <tr>
                        <td><?= (int) $a['id'] ?></td>
                        <td><?= e((string) ($a['user'] ?? 'system')) ?></td>
                        <td><code><?= e($a['action']) ?></code></td>
                        <td><?= e((string) ($a['module'] ?? '—')) ?></td>
                        <td><small><?= e((string) ($a['entity_type'] ?? '')) ?><?= $a['entity_id'] ? ' #' . e($a['entity_id']) : '' ?></small></td>
                        <td class="text-muted small"><?= e((string) ($a['ip_address'] ?? '—')) ?></td>
                        <td class="text-muted small"><?= date('d M Y H:i:s', strtotime((string) $a['created_at'])) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total > $perPage): ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm mb-0">
                <?php $totalPages = (int) ceil($total / $perPage); ?>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="audit.php?action=<?= e($action) ?>&page=<?= $i ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('admin_layout', [
    'title'   => 'Audit Logs',
    'content' => $content,
    'user'    => $user,
    'page'    => 'audit',
]);
