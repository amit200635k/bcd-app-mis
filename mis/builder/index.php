<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Services\SurveyService;

SessionAuth::requireAuth();
SessionAuth::requirePermission('survey_builder.view');

$user = SessionAuth::user();
$service = new SurveyService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    SessionAuth::requirePermission('survey_builder.manage');
    $code = trim((string) ($_POST['code'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));

    if ($code === '' || $title === '') {
        flash('error', 'Form code and title are required.');
    } else {
        try {
            $formId = $service->createForm($user->id(), [
                'code'  => strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $code)),
                'title' => $title,
                'description' => $_POST['description'] ?? null,
                'category_id' => $_POST['category_id'] ?: null,
            ]);
            flash('success', 'Survey form created. Now design its sections and fields.');
            redirect('mis/builder/edit.php?id=' . $formId);
        } catch (Throwable $e) {
            flash('error', exception_message($e));
        }
    }
}

$categories = \App\Database\Connection::instance()->query(
    'SELECT id, name FROM survey_categories WHERE is_active = 1 ORDER BY name'
)->fetchAll();

$forms = $service->listForms();
$versionInfos = [];
foreach ($forms as $f) {
    $versionInfos[(int) $f['id']] = $service->versionInfo((int) $f['id']);
}

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-ui-checks me-2"></i>Survey Builder</h4>
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#newFormModal"><i class="bi bi-plus-lg me-1"></i>New Form</button>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Title</th>
                    <th>Version</th>
                    <th>Status</th>
                    <th>Records</th>
                    <th>Updated</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($forms === []): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No survey forms yet. Click "New Form" to start.</td></tr>
            <?php else: foreach ($forms as $f): ?>
                <?php $vinfo = $versionInfos[(int) $f['id']] ?? ['published_version' => 0, 'draft_version' => 0, 'pending_changes' => false]; ?>
                <tr>
                    <td><code><?= e($f['code']) ?></code></td>
                    <td><?= e($f['title']) ?><br><small class="text-muted"><?= e((string)($f['category_name'] ?? '')) ?></small></td>
                    <td>
                        v<?= (int) $f['current_version'] ?>
                        <?php if ($f['status'] === 'published' && ($vinfo['pending_changes'] ?? false)): ?>
                        <span class="badge bg-warning text-dark" title="New draft ready to sync">v<?= (int) $vinfo['draft_version'] ?> draft</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $badge = ['draft' => 'secondary', 'published' => 'success', 'archived' => 'dark'][$f['status']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?= $badge ?>"><?= e($f['status']) ?></span>
                    </td>
                    <td><?= number_format((int) $f['record_count']) ?></td>
                    <td class="text-muted small"><?= date('d M Y', strtotime((string) $f['updated_at'])) ?></td>
                    <td class="text-end">
                        <a href="edit.php?id=<?= (int) $f['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit draft version"><i class="bi bi-pencil"></i></a>
                        <a href="preview.php?id=<?= (int) $f['id'] ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a>
                        <?php if ($f['status'] === 'published' && ($vinfo['pending_changes'] ?? false) && $user->hasPermission('survey_builder.publish')): ?>
                        <a href="sync.php?id=<?= (int) $f['id'] ?>" class="btn btn-sm btn-primary" onclick="return confirm('Publish draft v<?= (int) $vinfo['draft_version'] ?> and sync it to all web/mobile users?')"><i class="bi bi-arrow-repeat"></i></a>
                        <?php elseif ($f['status'] !== 'published'): ?>
                        <a href="publish.php?id=<?= (int) $f['id'] ?>" class="btn btn-sm btn-success" onclick="return confirm('Publish this form? It becomes downloadable by surveyors.')"><i class="bi bi-rocket-takeoff"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- New Form Modal -->
<div class="modal fade" id="newFormModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">New Survey Form</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Form Code</label>
                    <input type="text" name="code" class="form-control" placeholder="e.g. SCHOOL_SURVEY" required>
                    <div class="form-text">Unique machine code used by the mobile app.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">Create & Design</button>
            </div>
        </form>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('layout', [
    'title'   => 'Survey Builder',
    'content' => $content,
    'user'    => $user,
    'page'    => 'builder',
]);
