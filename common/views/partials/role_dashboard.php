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

// Chart data is passed via $stats['chart'] from the controller
$chartData = $stats['chart'] ?? null;
$chartLevel = $chartData['level'] ?? '';
$chartLabels = $chartData['labels'] ?? [];
$chartValues = $chartData['data'] ?? [];
$chartByStatus = $chartData['by_status'] ?? [];
$chartItems = $chartData['items'] ?? [];

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

<?php if ($chartData !== null && $chartLabels !== []): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-bar-chart me-2"></i><?= ucfirst($chartLevel) ?>-wise Records</span>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <label class="form-label small mb-0">Form:</label>
            <select id="formFilter" class="form-select form-select-sm" style="width: auto;">
                <option value="0">All Forms</option>
            </select>
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-primary active" data-chart-type="bar" title="Bar Chart"><i class="bi bi-bar-chart"></i></button>
                <button type="button" class="btn btn-outline-primary" data-chart-type="pie" title="Pie Chart"><i class="bi bi-pie-chart"></i></button>
                <button type="button" class="btn btn-outline-primary" data-chart-type="doughnut" title="Doughnut Chart"><i class="bi bi-pie-chart-fill"></i></button>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div style="height: 350px; position: relative;">
            <canvas id="dashboardChart"></canvas>
        </div>
        <div class="mt-3">
            <small class="text-muted">Click on a bar/segment to drill down</small>
        </div>
    </div>
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

<?php if ($chartData !== null && $chartLabels !== []): ?>
<script>
// Chart.js configuration
const chartLabels = <?= json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const chartValues = <?= json_encode($chartValues, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const chartByStatus = <?= json_encode($chartByStatus, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const chartItems = <?= json_encode($chartItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const currentRole = <?= json_encode($role, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const currentUnitType = <?= json_encode($unitType, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const currentUnitId = <?= json_encode((int)($stats['unit_id'] ?? 0), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const chartLevel = <?= json_encode($chartLevel ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

let currentChart = null;
let currentChartType = 'bar';

const chartColors = [
    'rgba(13, 148, 136, 0.8)',
    'rgba(59, 130, 246, 0.8)',
    'rgba(249, 115, 22, 0.8)',
    'rgba(168, 85, 247, 0.8)',
    'rgba(236, 72, 153, 0.8)',
    'rgba(34, 197, 94, 0.8)',
    'rgba(234, 179, 8, 0.8)',
    'rgba(239, 68, 68, 0.8)',
];

const chartBorderColors = [
    'rgba(13, 148, 136, 1)',
    'rgba(59, 130, 246, 1)',
    'rgba(249, 115, 22, 1)',
    'rgba(168, 85, 247, 1)',
    'rgba(236, 72, 153, 1)',
    'rgba(34, 197, 94, 1)',
    'rgba(234, 179, 8, 1)',
    'rgba(239, 68, 68, 1)',
];

function getDrillDownUrl(item, level) {
    const params = new URLSearchParams();
    
    // Use chartLevel (the current chart's level) to determine drill-down target
    // chartLevel is the level currently being displayed (e.g., 'district', 'block', 'panchayat', 'village')
    if (level === 'district') {
        // Drill from district to block
        params.set('district_id', item.id);
        return 'home_district.php?' + params.toString();
    } else if (level === 'block') {
        // Drill from block to panchayat
        params.set('block_id', item.id);
        return 'home_block.php?' + params.toString();
    } else if (level === 'panchayat') {
        // Drill from panchayat to village
        params.set('panchayat_id', item.id);
        return 'home_village.php?' + params.toString();
    } else if (level === 'village') {
        // Village is the lowest level - no further drill-down
        return null;
    }
    return null;
}

function createChart(type) {
    const ctx = document.getElementById('dashboardChart').getContext('2d');
    
    if (currentChart) {
        currentChart.destroy();
    }
    
    let datasets;
    let options;
    
    if (type === 'pie' || type === 'doughnut') {
        datasets = [{
            data: chartValues,
            backgroundColor: chartLabels.map((_, i) => chartColors[i % chartColors.length]),
            borderColor: chartLabels.map((_, i) => chartBorderColors[i % chartBorderColors.length]),
            borderWidth: 2,
        }];
        
        options = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { usePointStyle: true, padding: 15, font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const index = context.dataIndex;
                            const item = chartItems[index];
                            let label = item.name + ': ' + item.total + ' records';
                            if (item.by_status) {
                                label += ' (Submitted: ' + item.by_status.submitted + ', Verified: ' + item.by_status.verified + ', Approved: ' + item.by_status.approved + ', Rejected: ' + item.by_status.rejected + ')';
                            }
                            return label;
                        }
                    }
                },
                datalabels: {
                    color: '#333',
                    font: { weight: 'bold', size: 12 },
                    formatter: (value, ctx) => {
                        if (value === 0) return '';
                        return value;
                    }
                }
            },
            onClick: (event, elements) => {
                if (elements.length > 0) {
                    const index = elements[0].index;
                    const item = chartItems[index];
                    const url = getDrillDownUrl(item, chartLevel);
                    if (url) {
                        window.location.href = url;
                    }
                }
            }
        };
    } else {
        const statusColors = {
            submitted: 'rgba(59, 130, 246, 0.8)',
            verified: 'rgba(249, 115, 22, 0.8)',
            approved: 'rgba(34, 197, 94, 0.8)',
            rejected: 'rgba(239, 68, 68, 0.8)',
        };
        
        const statusBorderColors = {
            submitted: 'rgba(59, 130, 246, 1)',
            verified: 'rgba(249, 115, 22, 1)',
            approved: 'rgba(34, 197, 94, 1)',
            rejected: 'rgba(239, 68, 68, 1)',
        };
        
        const statusLabels = {
            submitted: 'Submitted',
            verified: 'Verified',
            approved: 'Approved',
            rejected: 'Rejected',
        };
        
        datasets = [];
        const statusKeys = ['submitted', 'verified', 'approved', 'rejected'];
        
        statusKeys.forEach((status, idx) => {
            if (chartByStatus[status] && chartByStatus[status].some(v => v > 0)) {
                datasets.push({
                    label: statusLabels[status],
                    data: chartByStatus[status],
                    backgroundColor: statusColors[status],
                    borderColor: statusBorderColors[status],
                    borderWidth: 1,
                    borderRadius: 4,
                });
            }
        });
        
        options = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: { usePointStyle: true, padding: 15, font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const index = context.dataIndex;
                            const item = chartItems[index];
                            let label = item.name + ': ' + context.raw + ' ' + context.dataset.label;
                            return label;
                        },
                        afterLabel: function(context) {
                            const index = context.dataIndex;
                            const item = chartItems[index];
                            if (item.by_status) {
                                return [
                                    'Total: ' + item.total,
                                    'Submitted: ' + item.by_status.submitted,
                                    'Verified: ' + item.by_status.verified,
                                    'Approved: ' + item.by_status.approved,
                                    'Rejected: ' + item.by_status.rejected
                                ];
                            }
                            return [];
                        }
                    }
                },
                datalabels: {
                    color: '#333',
                    font: { weight: 'bold', size: 10 },
                    anchor: 'end',
                    align: 'top',
                    formatter: (value, ctx) => {
                        if (value === 0) return '';
                        return value;
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    ticks: { maxRotation: 45, minRotation: 45, font: { size: 10 } }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: { stepSize: 1, font: { size: 10 } }
                }
            },
            onClick: (event, elements) => {
                if (elements.length > 0) {
                    const index = elements[0].index;
                    const item = chartItems[index];
                    const url = getDrillDownUrl(item, chartLevel);
                    if (url) {
                        window.location.href = url;
                    }
                }
            }
        };
    }
    
    currentChart = new Chart(ctx, {
        type: type,
        data: {
            labels: chartLabels,
            datasets: datasets
        },
        options: options,
        plugins: [ChartDataLabels]
    });
    
    currentChartType = type;
    
    document.querySelectorAll('[data-chart-type]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.chartType === type);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    createChart('bar');
    
    document.querySelectorAll('[data-chart-type]').forEach(btn => {
        btn.addEventListener('click', () => {
            createChart(btn.dataset.chartType);
        });
    });
    
    const formSelect = document.getElementById('formFilter');
    const forms = <?= json_encode($stats['forms_list'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const currentFormId = <?= json_encode($stats['chart']['form_id'] ?? null, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    forms.forEach(f => {
        const opt = document.createElement('option');
        opt.value = f.id;
        opt.textContent = f.title;
        formSelect.appendChild(opt);
    });
    if (currentFormId !== null) {
        formSelect.value = currentFormId;
    }
    
    formSelect.addEventListener('change', () => {
        const url = new URL(window.location.href);
        if (formSelect.value === '0' || formSelect.value === '') {
            url.searchParams.delete('form_id');
        } else {
            url.searchParams.set('form_id', formSelect.value);
        }
        window.location.href = url.toString();
    });
});
</script>
<?php endif; ?>