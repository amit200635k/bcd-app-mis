<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Services\LocationService;

SessionAuth::requireAuth();
SessionAuth::requirePermission('masters.view');

$user = SessionAuth::user();
$service = new LocationService();

if (($_POST['action'] ?? '') === 'delete') {
    SessionAuth::requirePermission('masters.manage');
    try {
        $service->destroy((string) $_POST['type'], (int) $_POST['id']);
        flash('success', 'Deleted successfully.');
    } catch (Throwable $e) {
        flash('error', exception_message($e));
    }
    redirect('mis/masters/index.php');
}

if (($_POST['action'] ?? '') === 'import' && ($_FILES['csv']['error'] ?? 1) === UPLOAD_ERR_OK) {
    SessionAuth::requirePermission('masters.manage');
    $tmp = $_FILES['csv']['tmp_name'];
    try {
        $stats = $service->importCsv($tmp, $user->id());
        flash('success', "Import done: {$stats['imported']} rows, {$stats['errors']} errors.");
    } catch (Throwable $e) {
        flash('error', exception_message($e));
    }
    redirect('mis/masters/index.php');
}

$tree = $service->tree();

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-list-ul me-2"></i>Location Masters</h4>
    <div>
        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal"><i class="bi bi-upload me-1"></i>Import CSV</button>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Administrative Hierarchy</div>
    <div class="card-body table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr><th>District</th><th>Blocks</th><th>Panchayats</th><th>Villages</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php if ($tree === []): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No location data. Use "Import CSV" to load the hierarchy.</td></tr>
            <?php else: foreach ($tree as $d): ?>
                <tr>
                    <td class="fw-semibold"><?= e($d['name']) ?></td>
                    <td><?= count($d['blocks']) ?></td>
                    <td><?= array_sum(array_map(fn($b) => count($b['panchayats']), $d['blocks'])) ?></td>
                    <td><?= array_sum(array_map(fn($b) => array_sum(array_map(fn($p) => count($p['villages']), $b['panchayats'])), $d['blocks'])) ?></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-danger" onclick="del('district', <?= (int) $d['id'] ?>)"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3 text-muted small">
    <i class="bi bi-info-circle me-1"></i>
    Blocks, panchayats and villages are managed per district. Full drill-down management is available via the API/mobile admin.
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="action" value="import">
            <div class="modal-header">
                <h5 class="modal-title">Import Location CSV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small">CSV columns: <code>district, block, panchayat, village</code>. Hierarchy is created automatically.</div>
                <input type="file" name="csv" class="form-control" accept=".csv" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">Import</button>
            </div>
        </form>
    </div>
</div>

<form id="deleteForm" method="post" class="d-none">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="type" id="delType">
    <input type="hidden" name="id" id="delId">
</form>

<script>
function del(type, id) {
    if (confirm('Delete this record and all children?')) {
        document.getElementById('delType').value = type;
        document.getElementById('delId').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>
<?php $content = ob_get_clean();

echo view('layout', [
    'title'   => 'Location Masters',
    'content' => $content,
    'user'    => $user,
    'page'    => 'masters',
]);
