<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Audit\AuditLog;
use App\Auth\SessionAuth;
use App\Database\Connection;

SessionAuth::requireAuth();

$user = SessionAuth::user();
if (!$user->isStateAdmin()) {
    http_response_code(403);
    exit('403 — Admin panel requires the state_admin role.');
}

$pdo = Connection::instance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
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
    redirect('admin/masters.php' . $redirect);
}

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

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-database me-2"></i>Master Data</h4>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Create Master Group</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="create_group">
                    <div class="mb-3">
                        <label class="form-label">Code</label>
                        <input type="text" name="code" class="form-control" placeholder="e.g. CROP_TYPE" required>
                        <div class="form-text">Unique machine code (used by the mobile app).</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Crop Types" required>
                    </div>
                    <button class="btn btn-danger w-100"><i class="bi bi-plus-lg me-1"></i>Create Group</button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Master Groups</div>
            <div class="card-body table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Name</th><th>Code</th><th class="text-end">Items</th><th></th></tr></thead>
                    <tbody>
                    <?php if ($groups === []): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No master groups yet.</td></tr>
                    <?php else: foreach ($groups as $g): ?>
                        <tr class="<?= $activeGroupId === (int) $g['id'] ? 'table-active' : '' ?>">
                            <td><a href="masters.php?group_id=<?= (int) $g['id'] ?>" class="fw-semibold text-decoration-none"><?= e($g['name']) ?></a></td>
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
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <?php if ($activeGroup === null): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-database fs-1 d-block mb-2"></i>
                Select a master group on the left to manage its items.
            </div>
        </div>
        <?php else: ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><code><?= e($activeGroup['code']) ?></code> — <?= e($activeGroup['name']) ?></span>
                <span class="badge bg-secondary"><?= count($items) ?> items</span>
            </div>
            <div class="card-body">
                <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="action" value="add_item">
                    <input type="hidden" name="group_id" value="<?= (int) $activeGroup['id'] ?>">
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Name *</label>
                        <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Ranchi" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Code</label>
                        <input type="text" name="code" class="form-control form-control-sm" placeholder="optional">
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-sm btn-danger w-100"><i class="bi bi-plus-lg me-1"></i>Add Item</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Items</div>
            <div class="card-body table-responsive">
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
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this item?')">
                                    <input type="hidden" name="action" value="delete_item">
                                    <input type="hidden" name="group_id" value="<?= (int) $activeGroup['id'] ?>">
                                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('admin_layout', [
    'title'   => 'Master Data',
    'content' => $content,
    'user'    => $user,
    'page'    => 'masters',
]);
