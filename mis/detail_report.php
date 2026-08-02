<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Services\RecordService;
use App\Services\ReportService;

SessionAuth::requireAuth();
SessionAuth::requirePermission('reports.view');

$user = SessionAuth::user();
$pdo = \App\Database\Connection::instance();
$service = new ReportService();

// Default to the Government Building Survey (form 40) when no form is chosen.
$formId = (int) ($_GET['form_id'] ?? 0);
if ($formId === 0) {
    $formId = (int) $pdo->query("SELECT id FROM survey_forms WHERE code = 'GOVT_BUILDING_SURVEY' AND status = 'published' LIMIT 1")->fetchColumn() ?: 0;
}
if ($formId > 0 && !$user->canAccessForm($formId)) {
    $formId = 0;
}

$filters = [
    'form_id'           => $formId,
    'status'            => (string) ($_GET['status'] ?? ''),
    'district'          => (string) ($_GET['district'] ?? ''),
    'block'             => (string) ($_GET['block'] ?? ''),
    'date_from'         => (string) ($_GET['date_from'] ?? ''),
    'date_to'           => (string) ($_GET['date_to'] ?? ''),
    'surveyor_id'       => (int) ($_GET['surveyor_id'] ?? 0),
    'keyword'           => (string) ($_GET['keyword'] ?? ''),
    'viewer'            => $user,
];
foreach (ReportService::DETAIL_FILTERS as $key => $def) {
    if ($def['type'] === 'range') {
        $filters[$key . '_min'] = (string) ($_GET[$key . '_min'] ?? '');
        $filters[$key . '_max'] = (string) ($_GET[$key . '_max'] ?? '');
    } else {
        $filters[$key] = (string) ($_GET[$key] ?? '');
    }
}

// Only the columns present in this form are filterable.
$cols = $service->detailColumns($formId);
$filterable = [];
foreach (ReportService::DETAIL_FILTERS as $k => $def) {
    if (isset($cols[$k])) {
        $filterable[$k] = $def;
    }
}
$filterOptions = [];
foreach ($filterable as $k => $def) {
    if ($def['type'] === 'exact') {
        $filterOptions[$k] = $service->detailFieldDistinct($k, $formId, $user);
    }
}

// CSV export of the same filtered dataset.
if (($_GET['export'] ?? '') === 'csv') {
    SessionAuth::requirePermission('reports.export');
    $headers = array_merge(['#', 'Record UUID', 'Surveyor', 'Status', 'Created'], array_values($cols));
    $outRows = [];
    foreach ($service->detailReport($filters, 5000, 0) as $r) {
        $row = [$r['id'], $r['record_uuid'], $r['surveyor'], $r['status'], $r['created_at']];
        foreach ($cols as $k => $label) {
            $row[] = $r[$k] ?? '';
        }
        $outRows[] = $row;
    }
    $csv = $service->toCsv($outRows, $headers);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="detail-report-' . date('Ymd-His') . '.csv"');
    echo "\xEF\xBB\xBF" . $csv;
    exit;
}

$kpis = $formId > 0 ? $service->detailKpis($filters) : ['total' => 0, 'submitted' => 0, 'verified' => 0, 'approved' => 0, 'rejected' => 0, 'built_up_total' => 0, 'rooms_total' => 0, 'avg_built_up' => 0, 'districts' => 0, 'departments' => 0, 'categories' => 0];
$rows = $formId > 0 ? $service->detailReport($filters, 500, 0) : [];
$latest = $rows[0] ?? null;

$statuses = RecordService::STATUSES;
$districts = $formId > 0 ? $service->detailLocationDistinct('district', $formId, $user) : [];
$blocks = $formId > 0 ? $service->detailLocationDistinct('block', $formId, $user) : [];
$surveyors = $formId > 0 ? $service->detailSurveyors($formId, $user) : [];

$forms = array_values(array_filter(
    $pdo->query("SELECT id, title, code FROM survey_forms WHERE status = 'published' ORDER BY title")->fetchAll(),
    static fn (array $f) => $user->canAccessForm((int) $f['id'])
));

$badges = [
    'draft' => 'secondary', 'submitted' => 'info', 'block_verified' => 'primary',
    'district_verified' => 'warning', 'approved' => 'success',
    'published' => 'success', 'rejected' => 'danger',
];

$exportQuery = $filters;
unset($exportQuery['viewer']);
$exportUrl = 'detail_report.php?' . http_build_query(array_merge($exportQuery, ['export' => 'csv']));

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-table me-2"></i>Detailed Report</h4>
    <span class="text-muted small"><i class="bi bi-shield-check me-1"></i>Showing data within your scope (your own + sub-users' records)</span>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Form</label>
                <select name="form_id" class="form-select form-select-sm">
                    <option value="0">— Select form —</option>
                    <?php foreach ($forms as $f): ?>
                    <option value="<?= (int) $f['id'] ?>" <?= $formId === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    <?php foreach ($statuses as $s): ?>
                    <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">District</label>
                <select name="district" class="form-select form-select-sm">
                    <option value="">All districts</option>
                    <?php foreach ($districts as $d): ?>
                    <option value="<?= e($d) ?>" <?= $filters['district'] === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Block</label>
                <select name="block" class="form-select form-select-sm">
                    <option value="">All blocks</option>
                    <?php foreach ($blocks as $b): ?>
                    <option value="<?= e($b) ?>" <?= $filters['block'] === $b ? 'selected' : '' ?>><?= e($b) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Surveyor</label>
                <select name="surveyor_id" class="form-select form-select-sm">
                    <option value="0">All surveyors</option>
                    <?php foreach ($surveyors as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $filters['surveyor_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="date_from" value="<?= e($filters['date_from']) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="date_to" value="<?= e($filters['date_to']) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Search</label>
                <input type="text" name="keyword" value="<?= e($filters['keyword']) ?>" class="form-control form-control-sm" placeholder="Building name / code / UUID">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-fill"><i class="bi bi-funnel me-1"></i>Run</button>
                <a href="detail_report.php?form_id=<?= $formId ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Reset</a>
                <a href="<?= e($exportUrl) ?>" class="btn btn-sm btn-success flex-fill"><i class="bi bi-download me-1"></i>Export CSV</a>
            </div>
            <?php if ($filterable !== []): ?>
            <div class="col-12">
                <hr class="my-2">
                <div class="text-muted small mb-2"><i class="bi bi-sliders me-1"></i>Filter by column values (KPIs below update to the filtered set)</div>
            </div>
            <?php foreach ($filterable as $k => $def): ?>
                <?php if ($def['type'] === 'exact'): ?>
                    <div class="col-md-3">
                        <label class="form-label small mb-1"><?= e($def['label']) ?></label>
                        <select name="<?= e($k) ?>" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach ($filterOptions[$k] ?? [] as $opt): ?>
                            <option value="<?= e($opt) ?>" <?= $filters[$k] === $opt ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', (string) $opt))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php elseif ($def['type'] === 'range'): ?>
                    <div class="col-md-2">
                        <label class="form-label small mb-1"><?= e($def['label']) ?> (min)</label>
                        <input type="number" step="any" name="<?= e($k) ?>_min" value="<?= e($filters[$k . '_min']) ?>" class="form-control form-control-sm" placeholder="Min">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1"><?= e($def['label']) ?> (max)</label>
                        <input type="number" step="any" name="<?= e($k) ?>_max" value="<?= e($filters[$k . '_max']) ?>" class="form-control form-control-sm" placeholder="Max">
                    </div>
                <?php else: ?>
                    <div class="col-md-3">
                        <label class="form-label small mb-1"><?= e($def['label']) ?></label>
                        <input type="text" name="<?= e($k) ?>" value="<?= e($filters[$k]) ?>" class="form-control form-control-sm" placeholder="Contains…">
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($formId === 0): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center text-muted py-5">Select a survey form to view its detailed records.</div>
</div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Total Buildings</div>
            <div class="fs-3 fw-bold"><?= number_format($kpis['total']) ?></div>
            <div class="small text-muted"><?= count($districts) ?> district<?= count($districts) === 1 ? '' : 's' ?> covered</div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Submitted</div>
            <div class="fs-3 fw-bold text-info"><?= number_format($kpis['submitted']) ?></div>
            <div class="small text-muted">awaiting verification</div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Verified</div>
            <div class="fs-3 fw-bold text-primary"><?= number_format($kpis['verified']) ?></div>
            <div class="small text-muted">block / district verified</div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Approved / Published</div>
            <div class="fs-3 fw-bold text-success"><?= number_format($kpis['approved']) ?></div>
            <div class="small text-muted">final status</div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Rejected</div>
            <div class="fs-3 fw-bold text-danger"><?= number_format($kpis['rejected']) ?></div>
            <div class="small text-muted">sent back for re-survey</div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Total Built-up Area</div>
            <div class="fs-3 fw-bold"><?= number_format($kpis['built_up_total']) ?> <span class="fs-6 text-muted">sqm</span></div>
            <div class="small text-muted">avg <?= number_format($kpis['avg_built_up'], 1) ?> sqm / building</div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Total Rooms</div>
            <div class="fs-3 fw-bold"><?= number_format($kpis['rooms_total']) ?></div>
            <div class="small text-muted">rooms across all buildings</div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Departments</div>
            <div class="fs-3 fw-bold text-primary"><?= number_format($kpis['departments']) ?></div>
            <div class="small text-muted">in filtered set</div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Categories</div>
            <div class="fs-3 fw-bold text-info"><?= number_format($kpis['categories']) ?></div>
            <div class="small text-muted">building categories</div>
        </div></div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small text-uppercase">Latest Submission</div>
            <?php if ($latest): ?>
            <div class="fw-semibold text-truncate" title="<?= e($latest['building_name'] ?? $latest['record_uuid']) ?>">
                <a class="text-decoration-none" href="records.php?id=<?= (int) $latest['id'] ?>"><?= e($latest['building_name'] ?? $latest['record_uuid']) ?></a>
            </div>
            <div class="small text-muted"><?= date('d M Y H:i', strtotime((string) $latest['created_at'])) ?> · #<?= (int) $latest['id'] ?></div>
            <?php else: ?>
            <div class="text-muted small">No records yet</div>
            <?php endif; ?>
        </div></div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-buildings me-2"></i>Records (<?= number_format($kpis['total']) ?>)</span>
        <span class="text-muted small">Showing latest <?= count($rows) ?> rows</span>
    </div>
    <div class="card-body table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Building Name</th>
                    <th>Code</th>
                    <?php foreach ($cols as $k => $label): if ($k === 'building_name' || $k === 'building_code') { continue; } ?>
                    <th><?= e($label) ?></th>
                    <?php endforeach; ?>
                    <th>Surveyor</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="<?= 6 + count($cols) ?>" class="text-center text-muted py-4">No records match the selected filters.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td class="text-muted small">#<?= (int) $r['id'] ?></td>
                    <td class="fw-semibold"><a class="text-decoration-none" href="records.php?id=<?= (int) $r['id'] ?>"><?= e((string) ($r['building_name'] ?? '—')) ?></a></td>
                    <td class="small"><?= e((string) ($r['building_code'] ?? '—')) ?></td>
                    <?php foreach ($cols as $k => $label): if ($k === 'building_name' || $k === 'building_code') { continue; } ?>
                        <?php
                        $v = $r[$k] ?? '';
                        if ($v !== '' && $v !== null && is_numeric($v)) {
                            $f = (float) $v;
                            $v = ($f == floor($f)) ? (string) (int) $f : number_format($f, 1);
                        }
                        ?>
                        <td class="small"><?= e($v === '' || $v === null ? '—' : (string) $v) ?></td>
                    <?php endforeach; ?>
                    <td class="small"><?= e((string) ($r['surveyor'] ?? '—')) ?></td>
                    <td><span class="badge bg-<?= $badges[$r['status']] ?? 'secondary' ?>"><?= e(ucwords(str_replace('_', ' ', (string) $r['status']))) ?></span></td>
                    <td class="small text-muted"><?= date('d M Y', strtotime((string) $r['created_at'])) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="records.php?id=<?= (int) $r['id'] ?>" title="View"><i class="bi bi-eye"></i></a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php $content = ob_get_clean();

echo view('layout', [
    'title'   => 'Detailed Report',
    'content' => $content,
    'user'    => $user,
    'page'    => 'detail_report',
]);
