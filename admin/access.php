<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Audit\AuditLog;
use App\Auth\SessionAuth;
use App\Services\UserService;

SessionAuth::requireAuth();

$user = SessionAuth::user();
if (!$user->isStateAdmin()) {
    http_response_code(403);
    exit('403 — Admin panel requires the state_admin role.');
}

$service = new UserService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $service->updateAccess($id, (array) ($_POST['portals'] ?? []), (array) ($_POST['forms'] ?? []), $user->id());
            AuditLog::record('access.assign', 'access', 'user', (string) $id, [], [
                'portals' => array_values((array) ($_POST['portals'] ?? [])),
                'forms'   => array_values(array_filter((array) ($_POST['forms'] ?? []))),
            ], $user->id());
            flash('success', 'Access updated.');
        } catch (Throwable $e) {
            flash('error', exception_message($e));
        }
    }
    redirect('admin/access.php');
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$search = trim((string) ($_GET['q'] ?? ''));
$result = $service->list($search, $page, 25, $user);
$forms = $service->assignableForms($user);

ob_start(); ?>
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h1 class="page-title mb-1"><i class="bi bi-shield-check me-2"></i>Roles &amp; Access</h1>
        <div class="page-subtitle">Manage user roles, portal access, and form permissions</div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-5">
                <input type="text" name="q" class="form-control" value="<?= e($search) ?>" placeholder="Search by name, username or mobile…">
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100">Search</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body table-responsive p-0">
        <table class="table table-hover align-middle mb-0 data-table">
            <thead>
                <tr><th>Name</th><th>Username</th><th>Roles</th><th>Portals</th><th>Forms</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($result['users'] as $u): ?>
                <?php $portals = $service->portalsOf((int) $u['id']); $formIds = $service->formsOf((int) $u['id']); ?>
                <tr>
                    <td class="fw-semibold"><?= e($u['full_name']) ?></td>
                    <td><code><?= e($u['username']) ?></code></td>
                    <td>
                        <?php foreach (array_filter(explode(',', (string) $u['roles'])) as $r): ?>
                        <span class="badge bg-secondary me-1"><?= e($r) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php foreach ($portals as $p): ?>
                        <span class="badge bg-<?= $p === 'admin' ? 'danger' : 'success' ?> me-1"><?= e($p) ?></span>
                        <?php endforeach; ?>
                        <?php if ($portals === []): ?><span class="text-muted small">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($formIds === []): ?>
                            <span class="text-muted small">—</span>
                        <?php else: ?>
                            <span class="text-muted small"><?= count($formIds) ?> form(s)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $badge = ['active' => 'success', 'inactive' => 'secondary', 'locked' => 'danger'][$u['status']] ?? 'secondary'; ?>
                        <span class="badge bg-<?= $badge ?>"><?= e($u['status']) ?></span>
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary" onclick="openAccess(<?= (int) $u['id'] ?>, <?= (int) $u['id'] == $user->id() ? 'true' : 'false' ?>)"><i class="bi bi-shield-check me-1"></i>Manage</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="accessModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content">
            <input type="hidden" name="id" id="accessUserId">
            <div class="modal-header">
                <h5 class="modal-title">Manage Access — <span id="accessUserName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Portal Access</label>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="portals[]" value="mis" id="ac_portal_mis">
                            <label class="form-check-label" for="ac_portal_mis">MIS Portal</label>
                            <div class="form-text">Login to the MIS portal (dashboard, monitoring, etc.).</div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="portals[]" value="admin" id="ac_portal_admin">
                            <label class="form-check-label" for="ac_portal_admin">Admin Panel</label>
                            <div class="form-text">Login to the state administration panel.</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Form Access</label>
                        <select name="forms[]" id="ac_forms" class="form-select" multiple size="10">
                            <?php foreach ($forms as $f): ?>
                            <option value="<?= (int) $f['id'] ?>"><?= e($f['title']) ?> <span class="text-muted">(<?= e($f['code']) ?>)</span></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Surveys the user can fill / view via mobile or MIS.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Access</button>
            </div>
        </form>
    </div>
</div>

<script>
const accessCache = {};

async function openAccess(id, isSelf) {
    if (isSelf) { alert('You already have full access as State Admin.'); return; }
    document.getElementById('accessUserId').value = id;
    document.getElementById('accessUserName').textContent = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('accessModal')).show();
    if (!accessCache[id]) {
        const res = await fetch('../api/user_data.php?id=' + id);
        accessCache[id] = await res.json();
    }
    const u = accessCache[id];
    document.getElementById('accessUserName').textContent = u.full_name;
    document.getElementById('ac_portal_mis').checked = (u.portals || []).includes('mis');
    document.getElementById('ac_portal_admin').checked = (u.portals || []).includes('admin');
    const formIds = (u.form_ids || []).map(Number);
    Array.from(document.getElementById('ac_forms').options).forEach(o => o.selected = formIds.includes(Number(o.value)));
}
</script>
<?php $content = ob_get_clean();

echo view('admin_layout', [
    'title'      => 'Roles & Access',
    'content'    => $content,
    'user'       => $user,
    'page'       => 'access',
    'breadcrumb' => [['Admin', 'dashboard.php'], ['Roles & Access', '']],
]);