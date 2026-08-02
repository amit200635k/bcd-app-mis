<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Services\RecordService;

SessionAuth::requireAuth();
SessionAuth::requirePermission('monitoring.view');

$user = SessionAuth::user();
$recordService = new RecordService();

$recordId = (int) ($_GET['id'] ?? 0);
$record = $recordService->find($recordId);

if ($record === null) {
    flash('error', 'Record not found.');
    redirect('mis/monitoring.php');
}
if (!$recordService->canView($user, $record)) {
    http_response_code(403);
    exit('403 — You do not have access to this record.');
}

$statusBadge = [
    'draft' => 'secondary', 'submitted' => 'info', 'block_verified' => 'primary',
    'district_verified' => 'warning', 'approved' => 'success',
    'published' => 'success', 'rejected' => 'danger',
][$record['status']] ?? 'secondary';

/** Human-readable answer value. */
$answerValue = static function (array $a): string {
    $json = json_decode((string) ($a['value_json'] ?? ''), true);
    if (is_array($json)) {
        if (isset($json['master_id']) || isset($json['name'])) {
            return (string) ($json['name'] ?? ($json['master_id'] ?? ''));
        }
        if (isset($json['district_id']) || isset($json['district_name'])) {
            $parts = [];
            foreach (['district_name', 'block_name', 'panchayat_name', 'village_name'] as $k) {
                if (!empty($json[$k])) {
                    $parts[] = $json[$k];
                }
            }
            return $parts !== [] ? implode(' / ', $parts) : '';
        }
        if (isset($json['lat'], $json['lng'])) {
            return 'GPS: ' . number_format((float) $json['lat'], 6) . ', ' . number_format((float) $json['lng'], 6);
        }
        return json_encode($json);
    }
    if ($a['value_text'] !== null) {
        return (string) $a['value_text'];
    }
    if ($a['value_number'] !== null) {
        return (string) $a['value_number'];
    }
    if ($a['value_date'] !== null) {
        return (string) $a['value_date'];
    }
    return '—';
};

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Record Detail</h4>
        <span class="text-muted small"><code><?= e($record['record_uuid']) ?></code> — #<?= (int) $record['id'] ?></span>
    </div>
    <a href="monitoring.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Monitoring</a>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Submitted Answers</div>
            <div class="card-body table-responsive">
                <?php if ($record['answers'] === []): ?>
                <p class="text-muted text-center py-4 mb-0">No answers recorded for this record.</p>
                <?php else: ?>
                <table class="table table-sm align-middle mb-0">
                    <tbody>
                    <?php foreach ($record['answers'] as $a): ?>
                        <tr>
                            <th class="text-muted fw-normal small" style="width:35%"><?= e((string) ($a['field_label'] ?: $a['field_key'])) ?>
                                <div class="fw-light"><code><?= e($a['field_key']) ?></code></div>
                            </th>
                            <td><?= e($answerValue($a)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($record['images'] !== []): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Attached Files (<?= count($record['images']) ?>)</div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-3">
                <?php foreach ($record['images'] as $img): ?>
                    <?php $isImage = str_starts_with((string) $img['mime_type'], 'image/'); ?>
                    <div class="text-center" style="width:130px">
                        <?php if ($isImage): ?>
                        <a href="<?= e(url($img['file_path'])) ?>" target="_blank">
                            <img src="<?= e(url($img['file_path'])) ?>" class="img-thumbnail" style="width:120px;height:90px;object-fit:cover"
                                 alt="<?= e($img['original_name'] ?? '') ?>">
                        </a>
                        <?php else: ?>
                        <a href="<?= e(url($img['file_path'])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary w-100">
                            <i class="bi bi-file-earmark me-1"></i><?= e(strtoupper(pathinfo((string) $img['file_path'], PATHINFO_EXTENSION))) ?>
                        </a>
                        <?php endif; ?>
                        <div class="small text-muted text-truncate mt-1"><?= e((string) ($img['original_name'] ?: basename((string) $img['file_path']))) ?></div>
                        <div class="small text-muted"><span class="badge bg-light text-dark"><?= e($img['category']) ?></span></div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Details</div>
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted">Form</dt>
                    <dd class="col-7"><?= e($record['form_title']) ?> <code class="small"><?= e($record['form_code']) ?></code></dd>
                    <dt class="col-5 text-muted">Submitted by</dt>
                    <dd class="col-7"><?= e((string) ($record['submitted_by_name'] ?: '—')) ?></dd>
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7"><span class="badge bg-<?= $statusBadge ?>"><?= e(ucwords(str_replace('_', ' ', (string) $record['status']))) ?></span></dd>
                    <dt class="col-5 text-muted">Created</dt>
                    <dd class="col-7"><?= date('d M Y H:i', strtotime((string) $record['created_at'])) ?></dd>
                    <dt class="col-5 text-muted">Updated</dt>
                    <dd class="col-7"><?= date('d M Y H:i', strtotime((string) $record['updated_at'])) ?></dd>
                </dl>
            </div>
        </div>

        <?php if ($record['gps'] !== []): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">GPS Logs</div>
            <div class="card-body">
                <table class="table table-sm small mb-0">
                    <thead><tr><th>Lat</th><th>Lng</th><th>Captured</th></tr></thead>
                    <tbody>
                    <?php foreach ($record['gps'] as $g): ?>
                        <tr>
                            <td class="text-muted small"><?= (float) $g['latitude'] ?></td>
                            <td class="text-muted small"><?= (float) $g['longitude'] ?></td>
                            <td class="text-muted small"><?= date('d M H:i', strtotime((string) $g['captured_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($record['workflow'] !== []): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Workflow History</div>
            <div class="card-body">
                <ul class="list-unstyled small mb-0">
                <?php foreach ($record['workflow'] as $w): ?>
                    <li class="mb-2 pb-2 border-bottom">
                        <div class="fw-semibold text-capitalize"><?= e((string) ($w['from_stage'] ?: '—')) ?> → <?= e((string) ($w['to_stage'] ?: '—')) ?></div>
                        <div class="text-muted">by <?= e((string) ($w['actor_name'] ?: '—')) ?> · <?= date('d M Y H:i', strtotime((string) $w['created_at'])) ?></div>
                        <?php if ($w['remark']): ?><div class="text-muted">“<?= e($w['remark']) ?>”</div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('layout', [
    'title'   => 'Record Detail',
    'content' => $content,
    'user'    => $user,
    'page'    => 'monitoring',
]);
