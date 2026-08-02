<?php

declare(strict_types=1);

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Audit\AuditLog;
use App\Database\Connection;

SessionAuth::requireAuth();

$user = SessionAuth::user();
if (!$user->isStateAdmin()) {
    exit('403');
}

$pdo = Connection::instance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string) $_POST['title']);
    $body = (string) $_POST['body'];
    $targetUser = (int) ($_POST['target_user'] ?? 0);
    if ($title !== '') {
        $roleId = (int) ($_POST['target_role'] ?? 0);
        $svc = new \App\Services\NotificationService();
        $svc->send($title, $body, $roleId ?: null, $targetUser ?: null, $user->id());
        AuditLog::record('notification.send', 'notifications', null, null, [], ['title' => $title], $user->id());
        flash('success', 'Notification sent.');
    }
    redirect('admin/notifications.php');
}

$roles = $pdo->query('SELECT id, name FROM roles ORDER BY id')->fetchAll();
$users = $pdo->query('SELECT id, full_name, username FROM users WHERE deleted_at IS NULL AND status = "active" ORDER BY full_name LIMIT 100')->fetchAll();
$sent = $pdo->query(
    'SELECT n.*, u.full_name AS sender,
            (SELECT COUNT(*) FROM notification_recipients nr WHERE nr.notification_id = n.id) AS recipients
     FROM notifications n LEFT JOIN users u ON u.id = n.created_by
     ORDER BY n.id DESC LIMIT 10'
)->fetchAll();

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-bell me-2"></i>Broadcast Notifications</h4>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Send Notification</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Message</label>
                        <textarea name="body" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Target Role</label>
                        <select name="target_role" class="form-select">
                            <option value="0">All users</option>
                            <?php foreach ($roles as $r): ?>
                            <option value="<?= (int) $r['id'] ?>"><?= e($r['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Or Specific User</label>
                        <select name="target_user" class="form-select">
                            <option value="0">— Everyone in role —</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>"><?= e($u['full_name']) ?> (@<?= e($u['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-danger w-100"><i class="bi bi-send me-1"></i>Broadcast</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Recently Sent</div>
            <div class="card-body table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Title</th><th>Recipients</th><th>Sent By</th><th>Time</th></tr></thead>
                    <tbody>
                    <?php if ($sent === []): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No notifications sent.</td></tr>
                    <?php else: foreach ($sent as $n): ?>
                        <tr>
                            <td><?= e($n['title']) ?><br><small class="text-muted"><?= e((string) ($n['body'] ?? '')) ?></small></td>
                            <td><?= (int) $n['recipients'] ?></td>
                            <td><?= e((string) ($n['sender'] ?? 'system')) ?></td>
                            <td class="text-muted small"><?= date('d M H:i', strtotime((string) $n['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('admin_layout', [
    'title'   => 'Notifications',
    'content' => $content,
    'user'    => $user,
    'page'    => 'notifications',
]);
