<?php
/**
 * Admin panel layout — professional dark sidebar design.
 * @var string $title
 * @var string $content
 * @var \App\Models\User|null $user
 * @var string $page
 * @var string $breadcrumb
 */
$title = $title ?? 'Admin Panel';
$user = $user ?? null;
$page = $page ?? '';
$breadcrumb = $breadcrumb ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><?= e($title) ?> — BCD Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/2.1.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/3.1.2/css/buttons.bootstrap5.min.css">
    <style>
        :root {
            --sidebar-width: 260px;
            --sidebar-collapsed: 64px;
            --sidebar-bg: #1e1e2e;
            --sidebar-hover: #31304a;
            --sidebar-active: #7c3aed;
            --sidebar-text: #cdd6f4;
            --sidebar-muted: #a6adc8;
            --topbar-bg: #ffffff;
            --body-bg: #f8f9fc;
            --card-shadow: 0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.06);
            --card-shadow-hover: 0 4px 12px rgba(0,0,0,.1);
            --radius: .5rem;
            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        * { box-sizing: border-box; }

        body {
            background: var(--body-bg);
            font-family: var(--font-sans);
            overflow-x: hidden;
            color: #1e1e2e;
        }

        /* ── Sidebar ── */
        .sidebar {
            min-height: 100vh;
            background: var(--sidebar-bg);
            width: var(--sidebar-width);
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            overflow-y: auto;
            overflow-x: hidden;
            transition: width .25s ease, transform .25s ease;
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            height: 60px;
            display: flex;
            align-items: center;
            padding: 0 1rem;
            flex-shrink: 0;
            border-bottom: 1px solid rgba(255,255,255,.06);
        }

        .sidebar-brand .brand-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #7c3aed, #a78bfa);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .sidebar-brand .brand-text {
            color: #fff;
            font-weight: 700;
            font-size: .95rem;
            margin-left: .65rem;
            white-space: nowrap;
            overflow: hidden;
        }

        .sidebar-nav {
            padding: .5rem .65rem;
            flex: 1;
        }

        .sidebar-nav .nav-section {
            font-size: .65rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--sidebar-muted);
            padding: .75rem .5rem .35rem;
            font-weight: 600;
        }

        .sidebar-nav .nav-link {
            color: var(--sidebar-text);
            border-radius: 6px;
            padding: .5rem .75rem;
            font-size: .85rem;
            display: flex;
            align-items: center;
            gap: .65rem;
            transition: all .15s ease;
            white-space: nowrap;
            text-decoration: none;
        }

        .sidebar-nav .nav-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .sidebar-nav .nav-link.active {
            background: var(--sidebar-active);
            color: #fff;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(124,58,237,.35);
        }

        .sidebar-nav .nav-link i {
            width: 1.15rem;
            text-align: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .sidebar-nav .nav-link .nav-label {
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-footer {
            padding: .5rem .65rem;
            border-top: 1px solid rgba(255,255,255,.06);
            flex-shrink: 0;
        }

        .sidebar-footer .nav-link {
            color: var(--sidebar-muted);
            border-radius: 6px;
            padding: .4rem .75rem;
            font-size: .8rem;
            display: flex;
            align-items: center;
            gap: .65rem;
            text-decoration: none;
            transition: all .15s ease;
        }

        .sidebar-footer .nav-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        /* ── Sidebar collapsed ── */
        .sidebar.collapsed {
            width: var(--sidebar-collapsed);
        }

        .sidebar.collapsed .brand-text,
        .sidebar.collapsed .nav-section,
        .sidebar.collapsed .nav-label,
        .sidebar.collapsed .sidebar-footer .nav-link span {
            display: none;
        }

        .sidebar.collapsed .sidebar-brand {
            justify-content: center;
            padding: 0;
        }

        .sidebar.collapsed .sidebar-brand .brand-icon {
            margin: 0;
        }

        .sidebar.collapsed .sidebar-nav {
            padding: .5rem .25rem;
        }

        .sidebar.collapsed .sidebar-nav .nav-link {
            justify-content: center;
            padding: .5rem;
        }

        .sidebar.collapsed .sidebar-nav .nav-link i {
            margin: 0;
        }

        .sidebar.collapsed .sidebar-footer {
            padding: .5rem .25rem;
        }

        .sidebar.collapsed .sidebar-footer .nav-link {
            justify-content: center;
            padding: .4rem;
        }

        /* ── Main area ── */
        .main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: margin-left .25s ease;
        }

        .sidebar.collapsed ~ .main {
            margin-left: var(--sidebar-collapsed);
        }

        /* ── Topbar ── */
        .topbar {
            background: var(--topbar-bg);
            border-bottom: 1px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 999;
            height: 56px;
        }

        .topbar .breadcrumb {
            margin-bottom: 0;
            background: transparent;
            padding: 0;
            font-size: .8rem;
        }

        .topbar .breadcrumb-item + .breadcrumb-item::before {
            content: '/';
            color: #cbd5e1;
        }

        .topbar .breadcrumb-item a {
            color: #64748b;
            text-decoration: none;
        }

        .topbar .breadcrumb-item.active {
            color: #1e1e2e;
            font-weight: 500;
        }

        .topbar-search {
            max-width: 280px;
        }

        .topbar-search .form-control {
            background: #f1f5f9;
            border: none;
            font-size: .85rem;
            padding: .4rem .75rem .4rem 2.25rem;
        }

        .topbar-search .form-control:focus {
            background: #fff;
            box-shadow: 0 0 0 2px rgba(124,58,237,.15);
        }

        .topbar-search .input-group-text {
            background: transparent;
            border: none;
            padding-left: .5rem;
            color: #94a3b8;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        .user-menu .avatar {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, #7c3aed, #a78bfa);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .8rem;
            font-weight: 600;
        }

        .user-menu .user-name {
            font-size: .85rem;
            font-weight: 500;
            color: #1e1e2e;
        }

        .user-menu .user-role {
            font-size: .7rem;
            color: #94a3b8;
        }

        /* ── Content ── */
        .content {
            padding: 1.25rem 1.5rem;
        }

        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .main {
                margin-left: 0;
            }
            .sidebar.collapsed ~ .main {
                margin-left: 0;
            }
            .content {
                padding: 1rem;
            }
        }

        /* ── Cards ── */
        .card {
            border: none;
            border-radius: var(--radius);
            box-shadow: var(--card-shadow);
            transition: box-shadow .2s ease;
        }

        .card:hover {
            box-shadow: var(--card-shadow-hover);
        }

        .card-header {
            border-radius: var(--radius) var(--radius) 0 0 !important;
            background: #fff;
            border-bottom: 1px solid #f1f5f9;
            font-weight: 600;
            font-size: .85rem;
            padding: .75rem 1rem;
        }

        .card-body {
            padding: 1rem;
        }

        /* ── Stat cards ── */
        .stat-card {
            border-radius: var(--radius);
            border: none;
            box-shadow: var(--card-shadow);
            transition: box-shadow .2s ease, transform .15s ease;
        }

        .stat-card:hover {
            box-shadow: var(--card-shadow-hover);
            transform: translateY(-1px);
        }

        .stat-card .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .stat-card .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.2;
        }

        .stat-card .stat-label {
            font-size: .78rem;
            color: #64748b;
            font-weight: 500;
        }

        /* ── Tables ── */
        .table {
            margin-bottom: 0;
        }

        .table th {
            font-weight: 600;
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #64748b;
            background: #fafbfc;
            border-bottom: 2px solid #e2e8f0;
            padding: .65rem .75rem;
        }

        .table td {
            font-size: .85rem;
            vertical-align: middle;
            padding: .55rem .75rem;
        }

        .table.table-hover tbody tr:hover {
            background: #f8fafc;
        }

        /* ── Buttons ── */
        .btn {
            font-size: .85rem;
            font-weight: 500;
            border-radius: 6px;
            padding: .45rem .85rem;
        }

        .btn-sm {
            font-size: .8rem;
            padding: .3rem .6rem;
        }

        .btn-primary {
            background: #7c3aed;
            border-color: #7c3aed;
        }

        .btn-primary:hover {
            background: #6d28d9;
            border-color: #6d28d9;
        }

        .btn-outline-primary {
            color: #7c3aed;
            border-color: #7c3aed;
        }

        .btn-outline-primary:hover {
            background: #7c3aed;
            border-color: #7c3aed;
        }

        .btn-danger {
            background: #ef4444;
            border-color: #ef4444;
        }

        .btn-danger:hover {
            background: #dc2626;
            border-color: #dc2626;
        }

        /* ── Badges ── */
        .badge {
            font-weight: 500;
            font-size: .75rem;
            padding: .35em .65em;
        }

        /* ── Forms ── */
        .form-control, .form-select {
            font-size: .85rem;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            padding: .45rem .7rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124,58,237,.12);
        }

        .form-label {
            font-weight: 500;
            font-size: .8rem;
            color: #475569;
            margin-bottom: .35rem;
        }

        .form-text {
            font-size: .75rem;
        }

        /* ── Alerts ── */
        .alert {
            border-radius: var(--radius);
            font-size: .85rem;
        }

        /* ── Modal ── */
        .modal-content {
            border-radius: var(--radius);
        }

        .modal-header {
            border-bottom: 1px solid #f1f5f9;
        }

        .modal-footer {
            border-top: 1px solid #f1f5f9;
        }

        /* ── Pagination ── */
        .pagination .page-link {
            border-radius: 6px !important;
            font-size: .85rem;
            color: #64748b;
            border: none;
        }

        .pagination .page-item.active .page-link {
            background: #7c3aed;
            border-color: #7c3aed;
        }

        .pagination .page-item:hover .page-link {
            background: #f1f5f9;
        }

        /* ── DataTables ── */
        .dataTables_wrapper .dataTables_length select,
        .dataTables_wrapper .dataTables_filter input {
            font-size: .85rem;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            padding: .3rem .5rem;
        }

        .dataTables_wrapper .dataTables_filter {
            margin-bottom: .75rem;
        }

        .dataTables_wrapper .dataTables_filter input {
            padding-left: 2rem;
        }

        .dataTables_wrapper .dt-buttons .btn {
            font-size: .78rem;
            padding: .25rem .5rem;
        }

        /* ── Mobile toggle ── */
        .mobile-toggle { display: none; }

        @media (max-width: 991.98px) {
            .mobile-toggle { display: inline-flex; }
        }

        /* ── Responsive ── */
        @media (max-width: 575.98px) {
            .content { padding: .75rem; }
            .stat-card .stat-value { font-size: 1.2rem; }
            .stat-card .stat-icon { width: 36px; height: 36px; font-size: .9rem; }
            .btn { font-size: .8rem; padding: .35rem .6rem; }
            h4 { font-size: .95rem; }
            .topbar-search { display: none; }
            .user-menu .user-name { display: none; }
        }

        /* ── Sidebar overlay ── */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 998;
        }

        .sidebar-overlay.show {
            display: block;
        }

        /* ── Misc ── */
        code {
            font-size: .82rem;
            background: #f1f5f9;
            padding: .15em .4em;
            border-radius: 4px;
            color: #7c3aed;
        }

        .text-muted { color: #94a3b8 !important; }
        .fw-semibold { font-weight: 600; }
        .small { font-size: .8rem; }

        .page-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0;
            color: #1e1e2e;
        }

        .page-subtitle {
            font-size: .8rem;
            color: #94a3b8;
            margin-top: .15rem;
        }
    </style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar" aria-label="Admin navigation">
    <div class="sidebar-brand">
        <div class="brand-icon"><i class="bi bi-shield-lock"></i></div>
        <span class="brand-text">BCD Admin</span>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">Main</div>
        <a class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>" href="dashboard.php">
            <i class="bi bi-speedometer2"></i><span class="nav-label">Dashboard</span>
        </a>

        <div class="nav-section">Management</div>
        <a class="nav-link <?= $page === 'masters' ? 'active' : '' ?>" href="masters.php">
            <i class="bi bi-database"></i><span class="nav-label">Master Data</span>
        </a>
        <a class="nav-link <?= $page === 'access' ? 'active' : '' ?>" href="access.php">
            <i class="bi bi-shield-check"></i><span class="nav-label">Roles &amp; Access</span>
        </a>
        <a class="nav-link <?= $page === 'settings' ? 'active' : '' ?>" href="settings.php">
            <i class="bi bi-gear"></i><span class="nav-label">Settings</span>
        </a>

        <div class="nav-section">Monitoring</div>
        <a class="nav-link <?= $page === 'notifications' ? 'active' : '' ?>" href="notifications.php">
            <i class="bi bi-bell"></i><span class="nav-label">Notifications</span>
        </a>
        <a class="nav-link <?= $page === 'audit' ? 'active' : '' ?>" href="audit.php">
            <i class="bi bi-journal-text"></i><span class="nav-label">Audit Logs</span>
        </a>
        <a class="nav-link <?= $page === 'replication' ? 'active' : '' ?>" href="replication.php">
            <i class="bi bi-arrow-repeat"></i><span class="nav-label">Replication</span>
        </a>
        <a class="nav-link <?= $page === 'health' ? 'active' : '' ?>" href="health.php">
            <i class="bi bi-heart-pulse"></i><span class="nav-label">System Health</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <a class="nav-link" href="../mis/">
            <i class="bi bi-speedometer2"></i><span>MIS Portal</span>
        </a>
        <a class="nav-link" href="../mis/logout.php">
            <i class="bi bi-box-arrow-right"></i><span>Logout</span>
        </a>
    </div>
</aside>

<div class="main">
    <header class="topbar px-3 px-md-4">
        <div class="d-flex align-items-center gap-3 h-100 w-100">
            <button class="btn btn-sm btn-outline-secondary mobile-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Open menu">
                <i class="bi bi-list"></i>
            </button>

            <button class="btn btn-sm btn-outline-secondary d-none d-lg-inline-flex" type="button" id="sidebarCollapse" aria-label="Collapse sidebar" title="Collapse sidebar">
                <i class="bi bi-sidebar"></i>
            </button>

            <nav class="breadcrumb" aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Admin</a></li>
                    <?php foreach ($breadcrumb as [$label, $url]): ?>
                        <?php if ($url): ?>
                            <li class="breadcrumb-item"><a href="<?= e($url) ?>"><?= e($label) ?></a></li>
                        <?php else: ?>
                            <li class="breadcrumb-item active"><?= e($label) ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ol>
            </nav>

            <div class="ms-auto d-flex align-items-center gap-3">
                <div class="topbar-search d-none d-md-block">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control" placeholder="Search…" aria-label="Search">
                    </div>
                </div>
                <div class="user-menu">
                    <div class="avatar"><?= e(strtoupper(substr($user?->fullName() ?? 'A', 0, 2))) ?></div>
                    <div class="d-none d-md-block">
                        <div class="user-name"><?= e($user?->fullName() ?? 'Admin') ?></div>
                        <div class="user-role">State Admin</div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="content">
        <?php if ($msg = flash('success')): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center">
                <i class="bi bi-check-circle me-2"></i><?= e($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($msg = flash('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center">
                <i class="bi bi-exclamation-triangle me-2"></i><?= e($msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?= $content ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.1.8/js/dataTables.min.js"></script>
<script src="https://cdn.datatables.net/2.1.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.1.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.1.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.1.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.1.2/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.1.2/js/buttons.colVis.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.12/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.12/vfs_fonts.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.data-table').forEach(function(table) {
        new DataTable(table, {
            autoWidth: false,
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
            dom: 'Blfrtip',
            buttons: ['csv', 'excel', 'pdf', 'print', 'colvis'],
            language: {
                search: '_INPUT_',
                searchPlaceholder: 'Search records.',
                emptyTable: 'No data available',
                zeroRecords: 'No matching records found',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                infoEmpty: 'Showing 0 to 0 of 0 entries'
            }
        });
    });

    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');

    if (sidebar && overlay) {
        sidebar.addEventListener('shown.bs.offcanvas', function() { overlay.classList.add('show'); });
        sidebar.addEventListener('hidden.bs.offcanvas', function() { overlay.classList.remove('show'); });
        overlay.addEventListener('click', function() { var offcanvas = bootstrap.Offcanvas.getInstance(sidebar); if (offcanvas) offcanvas.hide(); });
    }

    var collapseBtn = document.getElementById('sidebarCollapse');
    if (collapseBtn && sidebar) {
        var collapsed = localStorage.getItem('admin-sidebar-collapsed') === 'true';
        if (collapsed) { sidebar.classList.add('collapsed'); }
        collapseBtn.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('admin-sidebar-collapsed', sidebar.classList.contains('collapsed'));
        });
    }
});
</script>
</body>
</html>