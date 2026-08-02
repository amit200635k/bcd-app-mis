<?php
/**
 * Base MIS layout.
 * @var string $title
 * @var string $content
 * @var \App\Models\User|null $user
 */
$title = $title ?? 'BCD Survey Platform';
$user = $user ?? null;
$page = $page ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> — BCD Survey Platform</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background:#f4f6f9; }
        .sidebar { min-height:100vh; background:#1e293b; width:250px; position:fixed; top:0; left:0; overflow-y:auto; }
        .sidebar .nav-link { color:#cbd5e1; border-radius:.375rem; }
        .sidebar .nav-link:hover { background:#334155; color:#fff; }
        .sidebar .nav-link.active { background:#0d9488; color:#fff; }
        .main { margin-left:250px; }
        .topbar { background:#fff; border-bottom:1px solid #e2e8f0; }
        .brand { font-weight:700; color:#fff; font-size:1.1rem; }
    </style>
</head>
<body>
<?php if ($user !== null): ?>
<div class="sidebar p-3">
    <div class="brand mb-4"><i class="bi bi-clipboard-data me-2"></i>BCD Survey</div>
    <nav class="nav flex-column gap-1">
        <a class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>" href="<?= url($user->homeUrl()) ?>"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
        <?php if ($user->hasPermission('survey_builder.view')): ?>
        <a class="nav-link <?= $page === 'builder' ? 'active' : '' ?>" href="<?= url('mis/builder/index.php') ?>"><i class="bi bi-ui-checks me-2"></i>Survey Builder</a>
        <?php endif; ?>
        <?php if ($user->hasPermission('masters.view')): ?>
        <a class="nav-link <?= $page === 'masters' ? 'active' : '' ?>" href="<?= url('mis/masters/index.php') ?>"><i class="bi bi-list-ul me-2"></i>Masters</a>
        <?php endif; ?>
        <?php if ($user->hasPermission('monitoring.view')): ?>
        <a class="nav-link <?= $page === 'monitoring' ? 'active' : '' ?>" href="<?= url('mis/monitoring.php') ?>"><i class="bi bi-eye me-2"></i>Monitoring</a>
        <?php endif; ?>
        <?php if ($user->hasPermission('gis.view')): ?>
        <a class="nav-link <?= $page === 'gis' ? 'active' : '' ?>" href="<?= url('gis/index.php') ?>"><i class="bi bi-geo-alt me-2"></i>GIS</a>
        <?php endif; ?>
        <?php if ($user->hasPermission('reports.view')): ?>
        <a class="nav-link <?= $page === 'reports' ? 'active' : '' ?>" href="<?= url('mis/reports.php') ?>"><i class="bi bi-file-earmark-bar-graph me-2"></i>Reports</a>
        <a class="nav-link <?= $page === 'detail_report' ? 'active' : '' ?>" href="<?= url('mis/detail_report.php') ?>"><i class="bi bi-table me-2"></i>Detailed Report</a>
        <?php endif; ?>
        <?php if ($user->hasPermission('users.manage')): ?>
        <a class="nav-link <?= $page === 'users' ? 'active' : '' ?>" href="<?= url('mis/users/index.php') ?>"><i class="bi bi-people me-2"></i>Users</a>
        <?php endif; ?>
        <?php if ($user->isStateAdmin()): ?>
        <a class="nav-link" href="<?= url('admin/index.php') ?>"><i class="bi bi-shield-lock me-2"></i>Admin Panel</a>
        <?php endif; ?>
        <a class="nav-link" href="<?= url('mis/logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
    </nav>
</div>
<?php endif; ?>

<div class="main">
    <?php if ($user !== null): ?>
    <div class="topbar px-4 py-2 d-flex justify-content-between align-items-center">
        <div></div>
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted small"><?= e($user->fullName()) ?></span>
            <span class="badge bg-secondary"><?= e(implode(', ', $user->roleCodes())) ?></span>
        </div>
    </div>
    <?php endif; ?>
    <main class="p-4">
        <?php if ($msg = flash('success')): ?>
            <div class="alert alert-success alert-dismissible fade show"><?= e($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($msg = flash('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?= e($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?= $content ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
