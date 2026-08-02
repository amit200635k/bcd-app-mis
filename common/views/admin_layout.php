<?php
/**
 * Admin panel layout (distinct dark sidebar from MIS).
 * @var string $title
 * @var string $content
 * @var \App\Models\User|null $user
 */
$title = $title ?? 'Admin Panel';
$user = $user ?? null;
$page = $page ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> — BCD Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background:#f4f6f9; }
        .sidebar { min-height:100vh; background:#111827; width:230px; position:fixed; top:0; left:0; overflow-y:auto; }
        .sidebar .nav-link { color:#cbd5e1; border-radius:.375rem; }
        .sidebar .nav-link:hover { background:#374151; color:#fff; }
        .sidebar .nav-link.active { background:#dc2626; color:#fff; }
        .main { margin-left:230px; }
        .topbar { background:#fff; border-bottom:1px solid #e2e8f0; }
        .brand { font-weight:700; color:#fff; font-size:1.05rem; }
    </style>
</head>
<body>
<div class="sidebar p-3">
    <div class="brand mb-4"><i class="bi bi-shield-lock me-2"></i>BCD Admin</div>
    <nav class="nav flex-column gap-1">
        <a class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>" href="dashboard.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
        <a class="nav-link <?= $page === 'access' ? 'active' : '' ?>" href="access.php"><i class="bi bi-shield-check me-2"></i>Roles &amp; Access</a>
        <a class="nav-link <?= $page === 'settings' ? 'active' : '' ?>" href="settings.php"><i class="bi bi-gear me-2"></i>Settings</a>
        <a class="nav-link <?= $page === 'notifications' ? 'active' : '' ?>" href="notifications.php"><i class="bi bi-bell me-2"></i>Notifications</a>
        <a class="nav-link <?= $page === 'audit' ? 'active' : '' ?>" href="audit.php"><i class="bi bi-journal-text me-2"></i>Audit Logs</a>
        <a class="nav-link <?= $page === 'masters' ? 'active' : '' ?>" href="masters.php"><i class="bi bi-database me-2"></i>Master Data</a>
        <a class="nav-link <?= $page === 'replication' ? 'active' : '' ?>" href="replication.php"><i class="bi bi-arrow-repeat me-2"></i>Replication</a>
        <a class="nav-link <?= $page === 'health' ? 'active' : '' ?>" href="health.php"><i class="bi bi-heart-pulse me-2"></i>System Health</a>
        <hr class="my-2 border-secondary">
        <a class="nav-link" href="../mis/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
    </nav>
</div>

<div class="main">
    <div class="topbar px-4 py-2 d-flex justify-content-between align-items-center">
        <span class="small text-muted">System Administration</span>
        <div class="d-flex align-items-center gap-3">
            <span class="text-muted small"><?= e($user?->fullName() ?? 'Admin') ?></span>
            <span class="badge bg-danger">ADMIN</span>
        </div>
    </div>
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
