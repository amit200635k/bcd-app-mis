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
$db = true;
try {
    $pdo->query('SELECT 1');
} catch (\Throwable) {
    $db = false;
}

$phpVersion = PHP_VERSION;
$phpExts = ['pdo_mysql', 'pdo_pgsql', 'mbstring', 'openssl', 'json', 'gd', 'fileinfo'];
$extStatus = [];
foreach ($phpExts as $ext) {
    $extStatus[$ext] = extension_loaded($ext);
}

$writable = ['logs', 'uploads'];
$dirStatus = [];
foreach ($writable as $d) {
    $path = base_path($d);
    $dirStatus[$d] = is_writable($path);
}

$errors = [];
if (!$db) { $errors[] = 'Database connection failed.'; }
if (!$extStatus['pdo_mysql']) { $errors[] = 'pdo_mysql extension missing.'; }
foreach ($dirStatus as $d => $ok) {
    if (!$ok) { $errors[] = "Directory {$d} is not writable."; }
}
if (config('app.env') === 'production' && config('app.debug')) {
    $errors[] = 'APP_DEBUG must be false in production.';
}

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-heart-pulse me-2"></i>System Health</h4>
    <span class="badge bg-<?= $errors === [] ? 'success' : 'danger' ?> fs-6">
        <?= $errors === [] ? 'ALL SYSTEMS OK' : count($errors) . ' ISSUE(S)' ?>
    </span>
</div>

<?php if ($errors !== []): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Environment</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">PHP</td><td><?= e($phpVersion) ?></td></tr>
                    <tr><td class="text-muted">App Env</td><td><span class="badge bg-info text-dark"><?= e((string) config('app.env')) ?></span></td></tr>
                    <tr><td class="text-muted">Debug</td><td><?= config('app.debug') ? 'ON' : 'OFF' ?></td></tr>
                    <tr><td class="text-muted">App Name</td><td><?= e((string) config('app.name')) ?></td></tr>
                    <tr><td class="text-muted">DB</td><td><?= $db ? '<span class="badge bg-success">connected</span>' : '<span class="badge bg-danger">failed</span>' ?></td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">PHP Extensions</div>
            <div class="card-body">
                <?php foreach ($extStatus as $ext => $loaded): ?>
                <span class="badge bg-<?= $loaded ? 'success' : 'danger' ?> me-1 mb-1"><?= e($ext) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Writable Directories</div>
            <div class="card-body">
                <?php foreach ($dirStatus as $d => $ok): ?>
                <div class="d-flex justify-content-between mb-2">
                    <code>/<?= e($d) ?></code>
                    <?= $ok ? '<span class="badge bg-success">writable</span>' : '<span class="badge bg-danger">read-only</span>' ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">API Health</div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-wifi fs-3 text-success"></i>
                    <div>
                        <a href="../api/index.php" target="_blank" class="small">/api/v1/health</a><br>
                        <span class="text-muted small">REST API is reachable via the front controller.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('admin_layout', [
    'title'   => 'System Health',
    'content' => $content,
    'user'    => $user,
    'page'    => 'health',
]);
