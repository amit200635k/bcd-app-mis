<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Services\RoleDashboardService;

SessionAuth::requireAuth();

$user = SessionAuth::user();
if (!$user->isStateAdmin()) {
    redirect($user->homeUrl());
}

$svc = new RoleDashboardService();
$formId = isset($_GET['form_id']) && $_GET['form_id'] !== '' ? (int) $_GET['form_id'] : null;

$stats = $svc->stats($user);
$chartData = $svc->getChartData($user, null, null, $formId);
$stats['chart'] = $chartData;
$stats['unit_id'] = 0;

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-speedometer2 me-2"></i>State Dashboard</h4>
    <span class="text-muted small"><?= date('d M Y, h:i A') ?></span>
</div>

<?php if ($chartData !== null && ($chartData['labels'] ?? []) !== []): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-bar-chart me-2"></i>District-wise Records</span>
        <div class="d-flex gap-2 align-items-center">
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
            <small class="text-muted">Click on a bar/segment to drill down to district dashboard</small>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-people fs-1"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold"><?= number_format((int) ($stats['users']['total'] ?? 0)) ?></div>
                    <div class="text-muted small">Active Users</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-success bg-opacity-10 text-success">
                    <i class="bi bi-ui-checks fs-1"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold"><?= number_format((int) ($stats['forms'] ?? 0)) ?></div>
                    <div class="text-muted small">Published Forms</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-info bg-opacity-10 text-info">
                    <i class="bi bi-clipboard-check fs-1"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold"><?= number_format((int) ($stats['records']['total'] ?? 0)) ?></div>
                    <div class="text-muted small">Total Records</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                    <i class="bi bi-cloud-arrow-up fs-1"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold">0</div>
                    <div class="text-muted small">Pending Sync</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Records by Status</div>
            <div class="card-body">
                <?php
                $byStatus = [];
                foreach (($stats['records']['by_status'] ?? []) as $r) {
                    $byStatus[(string) $r['status']] = (int) $r['c'];
                }
                $badges = [
                    'draft' => 'secondary', 'submitted' => 'info', 'block_verified' => 'primary',
                    'district_verified' => 'warning', 'approved' => 'success',
                    'published' => 'success', 'rejected' => 'danger',
                ];
                $statusLabel = static fn (string $s) => ucwords(str_replace('_', ' ', $s));
                ?>
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead>
                    <tbody>
                    <?php if ($byStatus === []): ?>
                        <tr><td colspan="2" class="text-muted text-center">No records yet.</td></tr>
                    <?php else: foreach ($byStatus as $row => $count): ?>
                        <tr>
                            <td><span class="badge bg-<?= $badges[$row] ?? 'secondary' ?>"><?= e($statusLabel((string) $row)) ?></span></td>
                            <td class="text-end"><a class="link-underline-light" href="monitoring.php?status=<?= $row ?>" target="_blank"><?= number_format((int) $count) ?></a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Top Forms by Records</div>
            <div class="card-body">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Form</th><th class="text-end">Records</th></tr></thead>
                    <tbody>
                    <?php if (($stats['records']['per_form'] ?? []) === []): ?>
                        <tr><td colspan="2" class="text-muted text-center">No forms yet.</td></tr>
                    <?php else: foreach ($stats['records']['per_form'] as $row): ?>
                        <tr>
                            <td><?= e($row['form_title'] ?? $row['title'] ?? '') ?></td>
                            <td class="text-end"><a class="link-underline-light" href="monitoring.php?form_id=<?= $row['id'] ?? '' ?>" target="_blank"><?= number_format((int) ($row['total'] ?? 0)) ?></a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
// Load forms for filter dropdown
$pdo = \App\Database\Connection::instance();
$forms = $pdo->query("SELECT id, title FROM survey_forms WHERE status = 'published' AND is_active = 1 ORDER BY title")->fetchAll();
?>
<script>
// Chart.js configuration for state dashboard
const chartLabels = <?= json_encode($chartData['labels'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const chartValues = <?= json_encode($chartData['data'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const chartByStatus = <?= json_encode($chartData['by_status'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const chartItems = <?= json_encode($chartData['items'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const currentChartLevel = 'district';

const chartLevel = 'district';

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
    if (level === 'district') {
        return 'home_district.php?district_id=' + item.id;
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
        data: { labels: chartLabels, datasets: datasets },
        options: options,
        plugins: [ChartDataLabels]
    });
    
    currentChartType = type;
    
    document.querySelectorAll('[data-chart-type]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.chartType === type);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    if (chartLabels.length > 0) {
        createChart('bar');
    }
    
    document.querySelectorAll('[data-chart-type]').forEach(btn => {
        btn.addEventListener('click', () => {
            createChart(btn.dataset.chartType);
        });
    });
    
    const formSelect = document.getElementById('formFilter');
    const forms = <?= json_encode($forms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const currentFormId = <?= json_encode($formId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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
<?php
$content = ob_get_clean();

echo view('layout', [
    'title'   => 'State Dashboard',
    'content' => $content,
    'user'    => $user,
    'page'    => 'dashboard',
]);
