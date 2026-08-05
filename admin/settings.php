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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = trim((string) $_POST['setting_key']);
    $value = (string) $_POST['setting_value'];
    if ($key !== '') {
        $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (:k, :v, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        )->execute(['k' => $key, 'v' => $value, 'u' => $user->id()]);
        \App\Audit\AuditLog::record('settings.update', 'settings', 'settings', $key, [], ['value' => $value], $user->id());
        flash('success', 'Setting saved.');
    }
    redirect('admin/settings.php');
}

$settings = $pdo->query('SELECT setting_key, setting_value, updated_at FROM settings ORDER BY setting_key')->fetchAll();

ob_start(); ?>
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h1 class="page-title mb-1"><i class="bi bi-gear me-2"></i>System Settings</h1>
        <div class="page-subtitle">Configure application settings</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">Add / Update Setting</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Key</label>
                        <input type="text" name="setting_key" class="form-control" placeholder="e.g. app.sync_interval" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Value</label>
                        <input type="text" name="setting_value" class="form-control" required>
                    </div>
                    <button class="btn btn-primary w-100"><i class="bi bi-save me-1"></i>Save</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Stored Settings</div>
            <div class="card-body table-responsive p-0">
                <table class="table table-sm table-hover align-middle mb-0 data-table">
                    <thead><tr><th>Key</th><th>Value</th><th>Updated</th></tr></thead>
                    <tbody>
                    <?php foreach ($settings as $s): ?>
                        <tr>
                            <td><code><?= e($s['setting_key']) ?></code></td>
                            <td><?= e($s['setting_value']) ?></td>
                            <td class="text-muted small"><?= date('d M H:i', strtotime((string) $s['updated_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('admin_layout', [
    'title'      => 'Settings',
    'content'    => $content,
    'user'       => $user,
    'page'       => 'settings',
    'breadcrumb' => [['Admin', 'dashboard.php'], ['Settings', '']],
]);