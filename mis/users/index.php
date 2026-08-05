<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Services\UserService;

SessionAuth::requireAuth();
SessionAuth::requirePermission('users.manage');

$user = SessionAuth::user();
$service = new UserService();
$page = max(1, (int) ($_GET['page'] ?? 1));
$search = trim((string) ($_GET['q'] ?? ''));
$result = $service->list($search, $page, 25, $user);
$roles = $service->assignableRoles($user);
$forms = $service->assignableForms($user);
$canGrantAdmin = $user->isStateAdmin();

ob_start(); ?>
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h1 class="page-title mb-1"><i class="bi bi-people me-2"></i>User Management</h1>
        <div class="page-subtitle">Manage user accounts and permissions</div>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openModal()"><i class="bi bi-person-plus me-1"></i>New User</button>
</div>

<div class="card">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-md-6">
                <input type="text" name="q" class="form-control" value="<?= e($search) ?>" placeholder="Search by name, username or mobile…">
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100">Search</button></div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 data-table">
                <thead>
                    <tr><th>Name</th><th>Username</th><?php if (config('app.env') !== 'production'): ?><th>Password (dev)</th><?php endif; ?><th>Mobile</th><th>Roles</th><th>Status</th><th>Last Login</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($result['users'] as $u): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($u['full_name']) ?></td>
                        <td><code><?= e($u['username']) ?></code></td>
                        <?php if (config('app.env') !== 'production'): ?>
                        <td><code class="text-success small"><?= e((string) ($u['plain_password'] ?? '—')) ?></code></td>
                        <?php endif; ?>
                        <td><?= e((string) ($u['mobile'] ?? '—')) ?></td>
                        <td>
                            <?php foreach (array_filter(explode(',', (string) $u['roles'])) as $r): ?>
                            <span class="badge bg-secondary me-1"><?= e($r) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php
                            $badge = ['active' => 'success', 'inactive' => 'secondary', 'locked' => 'danger'][$u['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $badge ?>"><?= e($u['status']) ?></span>
                        </td>
                        <td class="text-muted small"><?= $u['last_login_at'] ? date('d M H:i', strtotime((string) $u['last_login_at'])) : '—' ?></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" onclick="openModal(<?= (int) $u['id'] ?>)"><i class="bi bi-pencil"></i></button>
                            <?php if ($u['id'] != $user->id()): ?>
                            <form method="post" class="d-inline" action="delete.php" onsubmit="return confirm('Deactivate this user?')">
                                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-person-x"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($result['total'] > 25): ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm mb-0">
                <?php $totalPages = (int) ceil($result['total'] / 25); ?>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="index.php?q=<?= e($search) ?>&page=<?= $i ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- User create/edit modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" action="save.php" class="modal-content">
            <input type="hidden" name="id" id="userId">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalTitle">New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" id="f_full_name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Username *</label>
                        <input type="text" name="username" id="f_username" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" id="f_email" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Mobile</label>
                        <input type="text" name="mobile" id="f_mobile" class="form-control" maxlength="10">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Roles *</label>
                        <select name="roles[]" id="f_roles" class="form-select" multiple required>
                            <?php foreach ($roles as $r): ?>
                            <option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Portal Access</label>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="portals[]" value="mis" id="portal_mis">
                            <label class="form-check-label" for="portal_mis">MIS Portal</label>
                        </div>
                        <?php if ($canGrantAdmin): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="portals[]" value="admin" id="portal_admin">
                            <label class="form-check-label" for="portal_admin">Admin Panel</label>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <select name="status" id="f_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">District</label>
                        <select name="district_id" id="f_district" class="form-select"><option value="">— None —</option></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Block</label>
                        <select name="block_id" id="f_block" class="form-select"><option value="">— None —</option></select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Form Access</label>
                        <select name="forms[]" id="f_forms" class="form-select" multiple size="4">
                            <?php if ($forms === []): ?>
                            <option value="" disabled>No published forms available to assign</option>
                            <?php else: foreach ($forms as $f): ?>
                            <option value="<?= (int) $f['id'] ?>"><?= e($f['title']) ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                        <div class="form-text">Select the surveys this user can fill / view.</div>
                    </div>
                    <div class="col-12 d-none" id="pwField">
                        <label class="form-label">Password (leave blank to keep unchanged)</label>
                        <input type="password" name="password" id="f_password" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
const rolesMap = {};
<?php foreach ($roles as $r): ?>rolesMap[<?= (int) $r['id'] ?>] = '<?= e($r['name']) ?>';<?php endforeach; ?>

const scope = <?= json_encode([
    'district_id' => (int) $user->get('district_id'),
    'block_id' => (int) $user->get('block_id'),
    'canGrantAdmin' => $canGrantAdmin,
]) ?>;

async function loadLocations() {
    const res = await fetch('../../api/dropdowns.php?type=district');
    const data = await res.json();
    const sel = document.getElementById('f_district');
    sel.innerHTML = '<option value="">— None —</option>' + data.items.map(d => `<option value="${d.id}">${d.name}</option>`).join('');
    if (scope.district_id) sel.value = scope.district_id;
    sel.onchange = async () => {
        const bid = document.getElementById('f_block');
        bid.innerHTML = '<option value="">— None —</option>';
        if (!sel.value) return;
        const r = await fetch(`../../api/dropdowns.php?type=block&district_id=${sel.value}`);
        const b = await r.json();
        bid.innerHTML = '<option value="">— None —</option>' + b.items.map(x => `<option value="${x.id}">${x.name}</option>`).join('');
        if (scope.block_id) bid.value = scope.block_id;
    };
}

async function openModal(id) {
    document.getElementById('userModalTitle').textContent = id ? 'Edit User' : 'New User';
    document.getElementById('pwField').classList.toggle('d-none', !!id);
    const portalBoxes = Array.from(document.querySelectorAll('[name="portals[]"]'));
    bootstrap.Modal.getOrCreateInstance(document.getElementById('userModal')).show();
    const fill = async (u) => {
        document.getElementById('userId').value = u.id;
        document.getElementById('f_full_name').value = u.full_name;
        document.getElementById('f_username').value = u.username;
        document.getElementById('f_email').value = u.email || '';
        document.getElementById('f_mobile').value = u.mobile || '';
        document.getElementById('f_status').value = u.status;
        const roles = (u.role_ids || '').split(',').map(Number).filter(Boolean);
        Array.from(document.getElementById('f_roles').options).forEach(o => o.selected = roles.includes(Number(o.value)));
        const portals = u.portals || [];
        portalBoxes.forEach(b => b.checked = portals.includes(b.value));
        const formIds = (u.form_ids || []).map(Number);
        Array.from(document.getElementById('f_forms').options).forEach(o => o.selected = formIds.includes(Number(o.value)));
        await loadLocations();
        if (u.district_id) {
            document.getElementById('f_district').value = u.district_id;
            document.getElementById('f_district').onchange();
            setTimeout(() => { if (u.block_id) document.getElementById('f_block').value = u.block_id; }, 300);
        }
    };
    if (id) {
        const res = await fetch(`../../api/user_data.php?id=${id}`);
        const u = await res.json();
        if (u.error) { alert(u.error); return; }
        await fill(u);
    } else {
        ['userId','f_full_name','f_username','f_email','f_mobile'].forEach(x => document.getElementById(x).value = '');
        document.getElementById('f_status').value = 'active';
        Array.from(document.getElementById('f_roles').options).forEach(o => o.selected = false);
        Array.from(document.getElementById('f_forms').options).forEach(o => o.selected = false);
        portalBoxes.forEach(b => b.checked = false);
        document.getElementById('f_password').value = '';
        await loadLocations();
    }
}
</script>
<?php $content = ob_get_clean();

echo view('layout', [
    'title'      => 'User Management',
    'content'    => $content,
    'user'       => $user,
    'page'       => 'users',
    'breadcrumb' => [['MIS', $user->homeUrl()], ['User Management', '']],
]);
