<?php
/**
 * Shared role-dashboard content for MIS landing pages.
 * @var string $role
 * @var string $pageTitle
 * @var array<string,mixed> $stats
 * @var \App\Models\User $user
 */
$role = $role ?? '';
$pageTitle = $pageTitle ?? 'Dashboard';
$stats = $stats ?? [];
$user = $user ?? null;

$isSurveyor = $role === 'surveyor';
$unit = (string) ($stats['unit'] ?? '');
$unitType = (string) ($stats['unit_type'] ?? '');

$byStatus = [];
foreach (($stats['records']['by_status'] ?? []) as $r) {
    $byStatus[(string) $r['status']] = (int) $r['c'];
}
$sumStatus = static function (array $keys) use ($byStatus): int {
    $t = 0;
    foreach ($keys as $k) {
        $t += $byStatus[$k] ?? 0;
    }
    return $t;
};

$submitted = $sumStatus(['submitted']);
$verified = $sumStatus(['block_verified', 'district_verified']);
$approved = $sumStatus(['approved', 'published']);
$rejected = $byStatus['rejected'] ?? 0;

$badges = [
    'draft' => 'secondary', 'submitted' => 'info', 'block_verified' => 'primary',
    'district_verified' => 'warning', 'approved' => 'success',
    'published' => 'success', 'rejected' => 'danger',
];
$statusLabel = static fn (string $s) => ucwords(str_replace('_', ' ', $s));
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h1 class="page-title mb-1"><i class="bi bi-columns-gap me-2"></i><?= e($pageTitle) ?></h1>
        <div class="page-subtitle">Welcome back, <?= e($user?->fullName() ?? '') ?></div>
    </div>
    <span class="text-muted small mt-1 d-none d-md-block"><?= date('d M Y, h:i A') ?></span>
</div>

<?php if ($unit !== ''): ?>
<div class="alert alert-light border d-flex flex-wrap justify-content-between align-items-center py-2 mb-3">
    <span><i class="bi bi-geo-alt me-1 text-primary"></i><strong><?= e(ucfirst($unitType)) ?>:</strong> <?= e($unit) ?></span>
    <?php if ($user !== null): ?>
    <span class="small text-muted">Logged in as <?= e($user->fullName()) ?> · <?= e(implode(', ', $user->roleCodes())) ?></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-2 g-md-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-2 gap-md-3">
                <div class="stat-icon bg-info bg-opacity-10 text-info">
                    <i class="bi bi-clipboard-check"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format((int) ($stats['records']['total'] ?? 0)) ?></div>
                    <div class="stat-label"><?= $isSurveyor ? 'My Submissions' : 'Records in scope' ?></div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$isSurveyor): ?>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-2 gap-md-3">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-people"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format((int) ($stats['users']['total'] ?? 0)) ?></div>
                    <div class="stat-label">Active users in scope</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-2 gap-md-3">
                <div class="stat-icon bg-success bg-opacity-10 text-success">
                    <i class="bi bi-person-check"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format((int) ($stats['users']['surveyors'] ?? 0)) ?></div>
                    <div class="stat-label">Surveyors</div>
                </div>
            </div>
        </div>
    </div>
    <?php foreach (($stats['children'] ?? []) as $c): ?>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-2 gap-md-3">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                    <i class="bi bi-diagram-3"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format((int) $c['count']) ?></div>
                    <div class="stat-label"><?= e((string) $c['label']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-2 gap-md-3">
                <div class="stat-icon bg-secondary bg-opacity-10 text-secondary">
                    <i class="bi bi-ui-checks"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format((int) ($stats['forms'] ?? 0)) ?></div>
                    <div class="stat-label">Surveys you can access</div>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-2 gap-md-3">
                <div class="stat-icon bg-info bg-opacity-10 text-info">
                    <i class="bi bi-send"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($submitted) ?></div>
                    <div class="stat-label">Submitted</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-2 gap-md-3">
                <div class="stat-icon bg-success bg-opacity-10 text-success">
                    <i class="bi bi-check2-circle"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($approved) ?></div>
                    <div class="stat-label">Approved / Published</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-2 gap-md-3">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                    <i class="bi bi-x-circle"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($rejected) ?></div>
                    <div class="stat-label">Rejected</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-2 gap-md-3">
                <div class="stat-icon bg-secondary bg-opacity-10 text-secondary">
                    <i class="bi bi-ui-checks"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format((int) ($stats['forms'] ?? 0)) ?></div>
                    <div class="stat-label">Surveys you can access</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="row g-2 g-md-3 mb-4">
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-graph-up me-2"></i>Records by Status</div>
            <div class="card-body">
                <?php if ($byStatus === []): ?>
                    <p class="text-muted small mb-0">No records yet.</p>
                <?php else: ?>
                <table class="table table-sm align-middle mb-0 data-tablex">
                    <tbody>
                    <?php foreach ($byStatus as $s => $c): ?>
                        <tr>
                            <td><span class="badge bg-<?= $badges[$s] ?? 'secondary' ?>"><?= e($statusLabel((string) $s)) ?></span></td>
                            <td class="text-end fw-semibold"><?= number_format($c) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-clock-history me-2"></i>Latest Submissions</div>
            <div class="card-body">
                <?php if (($stats['records']['latest'] ?? []) === []): ?>
                    <p class="text-muted small mb-0">No submissions yet.</p>
                <?php else: ?>
                <table class="table table-sm table-hover align-middle mb-0 data-tablex">
                    <thead><tr><th>Record</th><th>Status</th><th class="text-end">Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($stats['records']['latest'] as $row): ?>
                        <tr>
                            <td>
                                <a class="text-decoration-none" href="<?= url('mis/records.php?id=' . (int) $row['id']) ?>">#<?= (int) $row['id'] ?></a>
                                <div class="small text-muted text-truncate"><?= e((string) ($row['form_title'] ?? '')) ?></div>
                            </td>
                            <td><span class="badge bg-<?= $badges[(string) $row['status']] ?? 'secondary' ?>"><?= e($statusLabel((string) $row['status'])) ?></span></td>
                            <td class="small text-muted text-end"><?= date('d M y', strtotime((string) $row['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-bar-chart me-2"></i>Top Surveys by Records</div>
            <div class="card-body">
                <?php if (($stats['records']['per_form'] ?? []) === []): ?>
                    <p class="text-muted small mb-0">No submissions yet.</p>
                <?php else: ?>
                <table class="table table-sm align-middle mb-0 data-tablex">
                    <tbody>
                    <?php foreach ($stats['records']['per_form'] as $row): ?>
                        <tr>
                            <td><?= e((string) ($row['form_title'] ?? '')) ?></td>
                            <td class="text-end fw-semibold"><?= number_format((int) $row['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-lightning-charge me-2"></i>Quick Actions</div>
    <div class="card-body d-flex flex-wrap gap-2">
        <?php if ($user !== null && $user->hasPermission('monitoring.view')): ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= url('mis/monitoring.php') ?>"><i class="bi bi-eye me-1"></i>Monitoring</a>
        <?php endif; ?>
        <?php if ($user !== null && $user->hasPermission('reports.view')): ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= url('mis/reports.php') ?>"><i class="bi bi-file-earmark-bar-graph me-1"></i>Reports</a>
        <a class="btn btn-sm btn-outline-primary" href="<?= url('mis/detail_report.php') ?>"><i class="bi bi-table me-1"></i>Detailed Report</a>
        <?php endif; ?>
        <?php if ($user !== null && $user->hasPermission('users.manage')): ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= url('mis/users/index.php') ?>"><i class="bi bi-people me-1"></i>Manage Users</a>
        <?php endif; ?>
        <?php if ($isSurveyor): ?>
        <span class="align-self-center small text-muted"><i class="bi bi-phone me-1"></i>Field data is collected via the mobile app — your submissions appear here.</span>
        <?php endif; ?>
    </div>
</div>