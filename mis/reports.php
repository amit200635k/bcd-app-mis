<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Services\ReportService;

SessionAuth::requireAuth();
SessionAuth::requirePermission('reports.view');

$user = SessionAuth::user();
$service = new ReportService();
$formId = (int) ($_GET['form_id'] ?? 0);
$report = (string) ($_GET['report'] ?? 'survey_wise');

// CSV export?
if (($_GET['export'] ?? '') === 'csv') {
    SessionAuth::requirePermission('reports.export');
    $map = [
        'survey_wise' => ['Survey-wise Records', ['Form', 'Code', 'Total', 'Submitted', 'Block Verified', 'District Verified', 'Approved', 'Published', 'Rejected']],
        'user_wise'   => ['User-wise Records', ['Surveyor', 'Username', 'Total', 'Submitted', 'Published']],
        'district_wise' => ['District-wise Records', ['District', 'Total']],
        'gps_missing' => ['Records Missing GPS', ['Record', 'UUID', 'Form', 'Status', 'Created']],
        'duplicates'  => ['Duplicate Records', ['UUID', 'Count', 'IDs']],
    ];
    [$name, $headers] = $map[$report] ?? $map['survey_wise'];
    $rows = match ($report) {
        'user_wise' => $service->userWise($formId ?: null),
        'district_wise' => $service->districtWise($formId ?: null),
        'gps_missing' => $service->gpsMissing($formId ?: null),
        'duplicates' => $service->duplicates(),
        default => $service->surveyWise(),
    };
    $csv = $service->toCsv($rows, $headers);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF" . $csv;
    exit;
}

$data = match ($report) {
    'user_wise' => $service->userWise($formId ?: null),
    'district_wise' => $service->districtWise($formId ?: null),
    'daily' => $service->dailyProgress($formId ?: null),
    'gps_missing' => $service->gpsMissing($formId ?: null),
    'duplicates' => $service->duplicates(),
    default => $service->surveyWise(),
};
$summary = $service->statusSummary();
$forms = \App\Database\Connection::instance()->query('SELECT id, title FROM survey_forms ORDER BY title')->fetchAll();

$columns = match ($report) {
    'user_wise' => ['Surveyor', 'Username', 'Total', 'Submitted', 'Published'],
    'district_wise' => ['District', 'Total'],
    'daily' => ['Date', 'Records'],
    'gps_missing' => ['Record', 'UUID', 'Form', 'Status', 'Created'],
    'duplicates' => ['UUID', 'Count', 'IDs'],
    default => ['Form', 'Code', 'Total', 'Submitted', 'Block Verified', 'District Verified', 'Approved', 'Published', 'Rejected'],
};

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-file-earmark-bar-graph me-2"></i>Reports</h4>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Report</label>
                <select name="report" class="form-select form-select-sm">
                    <option value="survey_wise" <?= $report === 'survey_wise' ? 'selected' : '' ?>>Survey-wise</option>
                    <option value="user_wise" <?= $report === 'user_wise' ? 'selected' : '' ?>>User-wise</option>
                    <option value="district_wise" <?= $report === 'district_wise' ? 'selected' : '' ?>>District-wise</option>
                    <option value="daily" <?= $report === 'daily' ? 'selected' : '' ?>>Daily Progress</option>
                    <option value="gps_missing" <?= $report === 'gps_missing' ? 'selected' : '' ?>>GPS Missing</option>
                    <option value="duplicates" <?= $report === 'duplicates' ? 'selected' : '' ?>>Duplicate Records</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Form</label>
                <select name="form_id" class="form-select form-select-sm">
                    <option value="0">All Forms</option>
                    <?php foreach ($forms as $f): ?>
                    <option value="<?= (int) $f['id'] ?>" <?= $formId === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Run</button></div>
            <div class="col-md-3 text-end">
                <a href="reports.php?report=<?= e($report) ?>&form_id=<?= $formId ?>&export=csv" class="btn btn-sm btn-success w-100"><i class="bi bi-download me-1"></i>Export CSV</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($summary as $s): ?>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center justify-content-between">
                <span class="text-muted small text-capitalize"><?= e(str_replace('_', ' ', (string) $s['status'])) ?></span>
                <span class="badge bg-primary fs-6"><?= number_format((int) $s['c']) ?></span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><?= e(ucwords(str_replace('_', ' ', $report))) ?></div>
    <div class="card-body table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><?php foreach ($columns as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php if ($data === []): ?>
                <tr><td colspan="<?= count($columns) ?>" class="text-center text-muted py-4">No data.</td></tr>
            <?php else: foreach ($data as $row): ?>
                <tr><?php foreach (array_values($row) as $i => $v): ?><td class="<?= $i === 0 ? 'fw-semibold' : '' ?>"><?= e($v === null ? '—' : (string) $v) ?></td><?php endforeach; ?></tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('layout', [
    'title'   => 'Reports',
    'content' => $content,
    'user'    => $user,
    'page'    => 'reports',
]);
