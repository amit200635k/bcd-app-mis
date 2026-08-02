<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Database\Connection;
use App\Services\RecordService;

SessionAuth::requireAuth();
SessionAuth::requirePermission('monitoring.view');

$user = SessionAuth::user();
$pdo = Connection::instance();
$recordService = new RecordService();

// Handle approval/reject actions.
$action = $_POST['action'] ?? null;
if (in_array($action, ['verify', 'approve', 'publish', 'reject'], true)) {
    SessionAuth::requirePermission('approval.verify');
    $recordId = (int) ($_POST['record_id'] ?? 0);
    $remark = $_POST['remark'] ?? null;
    $toStatus = match ($action) {
        'verify'  => 'block_verified',
        'approve' => 'district_verified',
        'publish' => 'published',
        'reject'  => 'rejected',
    };
    try {
        $recordService->transition($recordId, $user->id(), $toStatus, $remark);
        flash('success', "Record marked as {$toStatus}.");
    } catch (Throwable $e) {
        flash('error', exception_message($e));
    }
    redirect('mis/monitoring.php');
}

$formId = (int) ($_GET['form_id'] ?? 0);
$status = (string) ($_GET['status'] ?? 'submitted');
$page = max(1, (int) ($_GET['page'] ?? 1));

$result = $recordService->listRecords($formId ?: null, $status, $page, 25);
$forms = $pdo->query('SELECT id, title FROM survey_forms ORDER BY title')->fetchAll();

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-eye me-2"></i>Survey Monitoring</h4>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Form</label>
                <select name="form_id" class="form-select form-select-sm">
                    <option value="0">All Forms</option>
                    <?php foreach ($forms as $f): ?>
                    <option value="<?= (int) $f['id'] ?>" <?= $formId === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <?php foreach (RecordService::STATUSES as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Record</th>
                    <th>Form</th>
                    <th>Surveyor</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($result['records'] === []): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No records found.</td></tr>
            <?php else: foreach ($result['records'] as $r): ?>
                <tr>
                    <td><code class="small"><?= e(substr((string) $r['record_uuid'], 0, 8)) ?></code> #<?= (int) $r['id'] ?></td>
                    <td><?= e($r['form_title']) ?></td>
                    <td><?= e((string) ($r['submitted_by_name'] ?? '—')) ?></td>
                    <td>
                        <?php
                        $badge = [
                            'draft' => 'secondary', 'submitted' => 'info', 'block_verified' => 'primary',
                            'district_verified' => 'warning', 'approved' => 'success',
                            'published' => 'success', 'rejected' => 'danger',
                        ][$r['status']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $badge ?>"><?= e(ucwords(str_replace('_', ' ', (string) $r['status']))) ?></span>
                    </td>
                    <td class="text-muted small"><?= date('d M H:i', strtotime((string) $r['updated_at'])) ?></td>
                    <td class="text-end">
                        <?php if ($user->hasPermission('approval.verify') && $r['status'] === 'submitted'): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Verify this record?')">
                            <input type="hidden" name="action" value="verify">
                            <input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-check2-circle"></i> Verify</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($user->hasPermission('approval.approve') && in_array($r['status'], ['block_verified'], true)): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Approve this record?')">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-success"><i class="bi bi-check-lg"></i> Approve</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($user->hasPermission('approval.publish') && in_array($r['status'], ['district_verified', 'approved'], true)): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Publish this record?')">
                            <input type="hidden" name="action" value="publish">
                            <input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-success"><i class="bi bi-rocket-takeoff"></i></button>
                        </form>
                        <?php endif; ?>
                        <?php if ($user->hasPermission('approval.verify') && !in_array($r['status'], ['published', 'rejected'], true)): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Reject and send back for re-survey?')">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="remark" value="Sent back for re-survey">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($result['total'] > $result['per_page']): ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm mb-0">
                <?php $totalPages = (int) ceil($result['total'] / $result['per_page']); ?>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="monitoring.php?form_id=<?= $formId ?>&status=<?= e($status) ?>&page=<?= $i ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('layout', [
    'title'   => 'Survey Monitoring',
    'content' => $content,
    'user'    => $user,
    'page'    => 'monitoring',
]);
