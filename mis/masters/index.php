<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/common/bootstrap.php';

use App\Audit\AuditLog;
use App\Auth\SessionAuth;
use App\Database\Connection;
use App\Services\LocationService;

SessionAuth::requireAuth();
SessionAuth::requirePermission('masters.view');

$user = SessionAuth::user();
$service = new LocationService();
$pdo = Connection::instance();

// Location hierarchy delete / CSV import.
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

// Master group/item management (mirrors admin/masters.php).
if (in_array($_POST['action'] ?? '', ['create_group', 'delete_group', 'add_item', 'delete_item'], true)) {
    SessionAuth::requirePermission('masters.manage');
    $action = (string) $_POST['action'];
    try {
        switch ($action) {
            case 'create_group':
                $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
                $name = trim((string) ($_POST['name'] ?? ''));
                if ($code === '' || $name === '') {
                    flash('error', 'Group code and name are required.');
                } else {
                    $pdo->prepare('INSERT INTO master_groups (code, name) VALUES (:c, :n)')
                        ->execute(['c' => $code, 'n' => $name]);
                    AuditLog::record('master.group.create', 'masters', 'master_group', $code, [], ['code' => $code, 'name' => $name], $user->id());
                    flash('success', 'Master group created.');
                }
                break;
            case 'delete_group':
                $id = (int) ($_POST['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT is_system FROM master_groups WHERE id = :id');
                $stmt->execute(['id' => $id]);
                $isSystem = (int) ($stmt->fetchColumn() ?: 0);
                if ($id > 0 && !$isSystem) {
                    $pdo->prepare('DELETE FROM master_groups WHERE id = :id')->execute(['id' => $id]);
                    AuditLog::record('master.group.delete', 'masters', 'master_group', (string) $id, [], [], $user->id());
                    flash('success', 'Master group deleted.');
                } elseif ($isSystem) {
                    flash('error', 'System master groups cannot be deleted.');
                }
                break;
            case 'add_item':
                $groupId = (int) ($_POST['group_id'] ?? 0);
                $name = trim((string) ($_POST['name'] ?? ''));
                $code = trim((string) ($_POST['code'] ?? ''));
                if ($groupId > 0 && $name !== '') {
                    $code = $code !== '' ? $code : strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name));
                    $pdo->prepare('INSERT INTO master_items (group_id, code, name) VALUES (:g, :c, :n)')
                        ->execute(['g' => $groupId, 'c' => $code, 'n' => $name]);
                    AuditLog::record('master.item.create', 'masters', 'master_item', (string) $groupId, [], ['name' => $name], $user->id());
                    flash('success', 'Master item added.');
                }
                break;
            case 'delete_item':
                $id = (int) ($_POST['id'] ?? 0);
                if ($id > 0) {
                    $groupId = (int) ($_POST['group_id'] ?? 0);
                    $pdo->prepare('DELETE FROM master_items WHERE id = :id')->execute(['id' => $id]);
                    AuditLog::record('master.item.delete', 'masters', 'master_item', (string) $groupId, [], [], $user->id());
                    flash('success', 'Master item deleted.');
                }
                break;
        }
    } catch (Throwable $e) {
        flash('error', exception_message($e));
    }
    $redirect = (int) ($_POST['group_id'] ?? 0) > 0 ? '?group_id=' . (int) $_POST['group_id'] : '';
    redirect('mis/masters/index.php' . $redirect);
}

$tree = $service->tree();

$groups = $pdo->query(
    'SELECT g.*, (SELECT COUNT(*) FROM master_items i WHERE i.group_id = g.id) AS item_count
     FROM master_groups g ORDER BY g.name'
)->fetchAll();

$activeGroupId = (int) ($_GET['group_id'] ?? 0);
$activeGroup = null;
$items = [];
if ($activeGroupId > 0) {
    foreach ($groups as $g) {
        if ((int) $g['id'] === $activeGroupId) {
            $activeGroup = $g;
            break;
        }
    }
    if ($activeGroup !== null) {
        $stmt = $pdo->prepare('SELECT * FROM master_items WHERE group_id = :g ORDER BY sort_order, name');
        $stmt->execute(['g' => $activeGroupId]);
        $items = $stmt->fetchAll();
    }
}

$canManage = $user->hasPermission('masters.manage');

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-list-ul me-2"></i>Masters</h4>
    <div>
        <?php if ($canManage): ?>
        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newGroupModal"><i class="bi bi-plus-lg me-1"></i>New Master Group</button>
        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal"><i class="bi bi-upload me-1"></i>Import CSV</button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Master Groups</div>
            <div class="card-body table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Name</th><th>Code</th><th class="text-end">Items</th><th></th></tr></thead>
                    <tbody>
                    <?php if ($groups === []): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No master groups yet.</td></tr>
                    <?php else: foreach ($groups as $g): ?>
                        <tr class="<?= $activeGroupId === (int) $g['id'] ? 'table-active' : '' ?>">
                            <td><a href="index.php?group_id=<?= (int) $g['id'] ?>" class="fw-semibold text-decoration-none"><?= e($g['name']) ?></a></td>
                            <td><code><?= e($g['code']) ?></code></td>
                            <td class="text-end"><?= (int) $g['item_count'] ?></td>
                            <td class="text-end">
                                <?php if ($canManage && !(int) $g['is_system']): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this master group and all its items?')">
                                    <input type="hidden" name="action" value="delete_group">
                                    <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($activeGroup !== null): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><code><?= e($activeGroup['code']) ?></code> — <?= e($activeGroup['name']) ?></span>
                <span class="badge bg-secondary"><?= count($items) ?> items</span>
            </div>
            <div class="card-body">
                <?php if ($canManage): ?>
                <form method="post" class="row g-2 align-items-end mb-3">
                    <input type="hidden" name="action" value="add_item">
                    <input type="hidden" name="group_id" value="<?= (int) $activeGroup['id'] ?>">
                    <div class="col-md-5">
                        <label class="form-label small mb-1">Name *</label>
                        <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Ranchi" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Code</label>
                        <input type="text" name="code" class="form-control form-control-sm" placeholder="optional">
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Add Item</button>
                    </div>
                </form>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>Name</th><th>Code</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php if ($items === []): ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">No items in this group yet.</td></tr>
                        <?php else: foreach ($items as $item): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($item['name']) ?></td>
                                <td><code><?= e($item['code']) ?></code></td>
                                <td class="text-end">
                                    <?php if ($canManage): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this item?')">
                                        <input type="hidden" name="action" value="delete_item">
                                        <input type="hidden" name="group_id" value="<?= (int) $activeGroup['id'] ?>">
                                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Location Hierarchy</div>
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
                                <?php if ($canManage): ?>
                                <button class="btn btn-sm btn-outline-danger" onclick="del('district', <?= (int) $d['id'] ?>)"><i class="bi bi-trash"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-2 text-muted small">
            <i class="bi bi-info-circle me-1"></i>
            Location levels are managed via CSV import; other master types are editable inline above.
        </div>
    </div>
</div>

<!-- New Master Group Modal -->
<div class="modal fade" id="newGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="create_group">
            <div class="modal-header">
                <h5 class="modal-title">New Master Group</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Code</label>
                    <input type="text" name="code" class="form-control" placeholder="e.g. CROP_TYPE" required>
                    <div class="form-text">Unique machine code (used by the mobile app).</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Crop Types" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">Create Group</button>
            </div>
        </form>
    </div>
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
    'title'   => 'Masters',
    'content' => $content,
    'user'    => $user,
    'page'    => 'masters',
]);
