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

// Location hierarchy CRUD.
if (($_POST['action'] ?? '') === 'create_block') {
    SessionAuth::requirePermission('masters.manage');
    try {
        $service->createBlock((int) $_POST['district_id'], (string) $_POST['name']);
        AuditLog::record('location.block.create', 'masters', 'block', (string) $_POST['district_id'], [], ['name' => $_POST['name']], $user->id());
        flash('success', 'Block created.');
    } catch (Throwable $e) {
        flash('error', exception_message($e));
    }
    redirect('mis/masters/index.php');
}

if (($_POST['action'] ?? '') === 'create_panchayat') {
    SessionAuth::requirePermission('masters.manage');
    try {
        $service->createPanchayat((int) $_POST['block_id'], (string) $_POST['name']);
        AuditLog::record('location.panchayat.create', 'masters', 'panchayat', (string) $_POST['block_id'], [], ['name' => $_POST['name']], $user->id());
        flash('success', 'Panchayat created.');
    } catch (Throwable $e) {
        flash('error', exception_message($e));
    }
    redirect('mis/masters/index.php');
}

if (($_POST['action'] ?? '') === 'create_village') {
    SessionAuth::requirePermission('masters.manage');
    try {
        $service->createVillage((int) $_POST['panchayat_id'], (string) $_POST['name']);
        AuditLog::record('location.village.create', 'masters', 'village', (string) $_POST['panchayat_id'], [], ['name' => $_POST['name']], $user->id());
        flash('success', 'Village created.');
    } catch (Throwable $e) {
        flash('error', exception_message($e));
    }
    redirect('mis/masters/index.php');
}

if (($_POST['action'] ?? '') === 'update_location') {
    SessionAuth::requirePermission('masters.manage');
    try {
        $service->update((string) $_POST['type'], (int) $_POST['id'], (string) $_POST['name']);
        AuditLog::record('location.' . $_POST['type'] . '.update', 'masters', $_POST['type'], (string) $_POST['id'], [], ['name' => $_POST['name']], $user->id());
        flash('success', ucfirst($_POST['type']) . ' updated.');
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
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h1 class="page-title mb-1"><i class="bi bi-list-ul me-2"></i>Masters</h1>
        <div class="page-subtitle">Manage master data and location hierarchy</div>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canManage): ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newGroupModal"><i class="bi bi-plus-lg me-1"></i>New Master Group</button>
        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal"><i class="bi bi-upload me-1"></i>Import CSV</button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
<div class="card mb-3">
            <div class="card-header">Master Groups</div>
            <div class="card-body table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 data-table">
                    <thead><tr><th>Name</th><th>Code</th><th class="text-end">Items</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($groups as $g): ?>
                        <tr class="<?= $activeGroupId === (int) $g['id'] ? 'table-active' : '' ?>">
                            <td><a href="index.php?group_id=<?= (int) $g['id'] ?>" class="fw-semibold text-decoration-none"><?= e($g['name']) ?></a></td>
                            <td><code><?= e($g['code']) ?></code></td>
                            <td class="text-end"><?= (int) $g['item_count'] ?></td>
                            <td class="text-end">
                                <?php if (!(int) $g['is_system']): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this master group and all its items?')">
                                    <input type="hidden" name="action" value="delete_group">
                                    <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                 </form>
                                 <?php endif; ?>
                             </td>
                         </tr>
                     <?php endforeach; ?>
                     </tbody>
                 </table>
             </div>
        </div>
        </div>

        <?php if ($activeGroup !== null): ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
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
                        <button class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Add Item</button>
                    </div>
                </form>
                <?php endif; ?>
                <div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0 data-table">
                    <thead><tr><th>Name</th><th>Code</th><th class="text-end">Actions</th></tr></thead>
                     <tbody>
                     <?php foreach ($items as $item): ?>
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
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Location Hierarchy</span>
                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal"><i class="bi bi-upload me-1"></i>Import CSV</button>
            </div>
            <div class="card-body">
                <?php if (empty($tree)): ?>
                <div class="text-muted text-center py-3">No locations found. Import a CSV or add blocks manually.</div>
                <?php else: ?>
                <div class="accordion" id="locationTree">
                <?php foreach ($tree as $dIdx => $d): ?>
                    <?php
                        $dAccId = 'dist_' . (int) $d['id'];
                        $totalBlocks = count($d['blocks']);
                        $totalPanch = array_sum(array_map(fn($b) => count($b['panchayats']), $d['blocks']));
                        $totalVill = array_sum(array_map(fn($b) => array_sum(array_map(fn($p) => count($p['villages']), $b['panchayats'])), $d['blocks']));
                    ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button <?= $dIdx > 0 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $dAccId ?>">
                                <span class="fw-semibold"><?= e($d['name']) ?></span>
                                <span class="badge bg-secondary ms-2"><?= $totalBlocks ?> blocks</span>
                                <span class="badge bg-info ms-1"><?= $totalPanch ?> panchayats</span>
                                <span class="badge bg-success ms-1"><?= $totalVill ?> villages</span>
                            </button>
                        </h2>
                        <div id="<?= $dAccId ?>" class="accordion-collapse collapse <?= $dIdx === 0 ? 'show' : '' ?>" data-bs-parent="#locationTree">
                            <div class="accordion-body p-0">
                                <?php if (empty($d['blocks'])): ?>
                                <div class="text-muted small py-2 px-3">No blocks yet.</div>
                                <?php else: ?>
                                <?php foreach ($d['blocks'] as $b): ?>
                                <div class="border-bottom py-2 px-3">
                                    <div class="d-flex align-items-center">
                                        <button class="btn btn-sm btn-link text-decoration-none fw-semibold p-0 me-2" type="button" data-bs-toggle="collapse" data-bs-target="#blk_<?= (int) $b['id'] ?>">
                                            <i class="bi bi-chevron-right small"></i> <?= e($b['name']) ?>
                                        </button>
                                        <span class="badge bg-secondary small"><?= count($b['panchayats']) ?> panchayats</span>
                                        <?php if ($canManage): ?>
                                        <div class="ms-auto">
                                            <button class="btn btn-sm btn-outline-primary py-0 px-1" onclick="openEditModal('block', <?= (int) $b['id'] ?>, <?= e(json_encode((string) $b['name'])) ?>)" title="Edit"><i class="bi bi-pencil small"></i></button>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this block? It must have no panchayats or villages.')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="type" value="block">
                                                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Delete"><i class="bi bi-trash small"></i></button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="collapse <?= count($d['blocks']) === 1 ? 'show' : '' ?>" id="blk_<?= (int) $b['id'] ?>">
                                        <?php if (empty($b['panchayats'])): ?>
                                        <div class="text-muted small py-1 ps-4">No panchayats yet.</div>
                                        <?php else: ?>
                                        <?php foreach ($b['panchayats'] as $p): ?>
                                        <div class="ps-4 border-bottom py-1">
                                            <div class="d-flex align-items-center">
                                                <button class="btn btn-sm btn-link text-decoration-none py-0 px-1 small" type="button" data-bs-toggle="collapse" data-bs-target="#pan_<?= (int) $p['id'] ?>">
                                                    <i class="bi bi-chevron-right small"></i> <?= e($p['name']) ?>
                                                </button>
                                                <span class="badge bg-secondary small"><?= count($p['villages']) ?> villages</span>
                                                <?php if ($canManage): ?>
                                                <div class="ms-auto">
                                                    <button class="btn btn-sm btn-outline-primary py-0 px-1" onclick="openEditModal('panchayat', <?= (int) $p['id'] ?>, <?= e(json_encode((string) $p['name'])) ?>)" title="Edit"><i class="bi bi-pencil small"></i></button>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this panchayat? It must have no villages.')">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="type" value="panchayat">
                                                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                                        <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Delete"><i class="bi bi-trash small"></i></button>
                                                    </form>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="collapse ps-4" id="pan_<?= (int) $p['id'] ?>">
                                                <?php if (empty($p['villages'])): ?>
                                                <div class="text-muted small py-1">No villages yet.</div>
                                                <?php else: ?>
                                                <?php foreach ($p['villages'] as $v): ?>
                                                <div class="d-flex align-items-center border-bottom py-1">
                                                    <span class="small"><?= e($v['name']) ?></span>
                                                    <?php if ($canManage): ?>
                                                    <div class="ms-auto">
                                                        <button class="btn btn-sm btn-outline-primary py-0 px-1" onclick="openEditModal('village', <?= (int) $v['id'] ?>, <?= e(json_encode((string) $v['name'])) ?>)" title="Edit"><i class="bi bi-pencil small"></i></button>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this village?')">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="type" value="village">
                                                            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                                            <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Delete"><i class="bi bi-trash small"></i></button>
                                                        </form>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endforeach; ?>
                                                <?php if ($canManage): ?>
                                                <div class="py-1">
                                                    <button class="btn btn-sm btn-link text-decoration-none p-0 small" data-bs-toggle="modal" data-bs-target="#addVillageModal" onclick="setVillageParent(<?= (int) $p['id'] ?>, <?= e(json_encode((string) $p['name'])) ?>)"><i class="bi bi-plus-circle me-1"></i>Add Village</button>
                                                </div>
                                                <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php if ($canManage): ?>
                                        <div class="ps-4 py-1">
                                            <button class="btn btn-sm btn-link text-decoration-none p-0 small" data-bs-toggle="modal" data-bs-target="#addPanchayatModal" onclick="setPanchayatParent(<?= (int) $b['id'] ?>, <?= e(json_encode((string) $b['name'])) ?>)"><i class="bi bi-plus-circle me-1"></i>Add Panchayat</button>
                                        </div>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php if ($canManage): ?>
                                <div class="px-3 py-2">
                                    <button class="btn btn-sm btn-link text-decoration-none p-0 small" data-bs-toggle="modal" data-bs-target="#addBlockModal" onclick="setBlockParent(<?= (int) $d['id'] ?>, <?= e(json_encode((string) $d['name'])) ?>)"><i class="bi bi-plus-circle me-1"></i>Add Block</button>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Block Modal -->
<div class="modal fade" id="addBlockModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="create_block">
            <input type="hidden" name="district_id" id="blk_district_id">
            <div class="modal-header">
                <h5 class="modal-title">Add Block</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">District</label>
                    <input type="text" id="blk_district_name" class="form-control" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Block Name *</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Block Name" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Block</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Panchayat Modal -->
<div class="modal fade" id="addPanchayatModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="create_panchayat">
            <input type="hidden" name="block_id" id="pan_block_id">
            <div class="modal-header">
                <h5 class="modal-title">Add Panchayat</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Block</label>
                    <input type="text" id="pan_block_name" class="form-control" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Panchayat Name *</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Panchayat Name" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Panchayat</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Village Modal -->
<div class="modal fade" id="addVillageModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="create_village">
            <input type="hidden" name="panchayat_id" id="vil_panchayat_id">
            <div class="modal-header">
                <h5 class="modal-title">Add Village</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Panchayat</label>
                    <input type="text" id="vil_panchayat_name" class="form-control" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Village Name *</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Village Name" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Village</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Location Modal -->
<div class="modal fade" id="editLocationModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="update_location">
            <input type="hidden" name="type" id="edit_type">
            <input type="hidden" name="id" id="edit_id">
            <div class="modal-header">
                <h5 class="modal-title" id="editLocationTitle">Edit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Name *</label>
                    <input type="text" name="name" id="edit_name" class="form-control" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function setBlockParent(districtId, districtName) {
    document.getElementById('blk_district_id').value = districtId;
    document.getElementById('blk_district_name').value = districtName;
}
function setPanchayatParent(blockId, blockName) {
    document.getElementById('pan_block_id').value = blockId;
    document.getElementById('pan_block_name').value = blockName;
}
function setVillageParent(panchayatId, panchayatName) {
    document.getElementById('vil_panchayat_id').value = panchayatId;
    document.getElementById('vil_panchayat_name').value = panchayatName;
}
function openEditModal(type, id, name) {
    document.getElementById('edit_type').value = type;
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('editLocationTitle').textContent = 'Edit ' + type.charAt(0).toUpperCase() + type.slice(1);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editLocationModal')).show();
}
</script>

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
                <button type="submit" class="btn btn-primary">Create Group</button>
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
                <div class="mb-3">
                    <a href="<?= url('database/sample_locations.csv') ?>" class="btn btn-outline-secondary btn-sm" download><i class="bi bi-download me-1"></i>Download Sample CSV</a>
                </div>
                <input type="file" name="csv" class="form-control" accept=".csv" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Import</button>
            </div>
        </form>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('layout', [
    'title'      => 'Masters',
    'content'    => $content,
    'user'       => $user,
    'page'       => 'masters',
    'breadcrumb' => [['MIS', $user->homeUrl()], ['Masters', '']],
]);
