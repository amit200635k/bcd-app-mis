<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Services\SurveyService;

SessionAuth::requireAuth();
SessionAuth::requirePermission('survey_builder.view');

$user = SessionAuth::user();
$service = new SurveyService();
$formId = (int) ($_GET['id'] ?? 0);
$definition = $service->formDefinition($formId);

if ($definition === null) {
    flash('error', 'Form not found.');
    redirect('mis/builder/index.php');
}

$form = $definition['form'];

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0"><i class="bi bi-eye me-2"></i><?= e($form['title']) ?></h4>
        <span class="text-muted small"><code><?= e($form['code']) ?></code> — version <?= (int) $definition['version'] ?> (<?= e($form['status']) ?>)</span>
    </div>
    <a href="edit.php?id=<?= $formId ?>" class="btn btn-outline-primary btn-sm">Edit Structure</a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-7">
        <div class="card border-0 shadow-sm" style="border-top:4px solid #0d9488 !important">
            <div class="card-body p-4">
                <?php if ($form['description']): ?>
                <p class="text-muted"><?= e($form['description']) ?></p>
                <?php endif; ?>

                <?php foreach ($definition['sections'] as $section): ?>
                <div class="mb-4">
                    <h6 class="fw-bold text-success text-uppercase small"><?= e($section['title']) ?></h6>
                    <hr class="mt-1">
                    <?php foreach ($section['fields'] as $field): ?>
                    <div class="mb-3">
                        <label class="form-label">
                            <?= e($field['label']) ?>
                            <?php if ($field['is_mandatory']): ?><span class="text-danger">*</span><?php endif; ?>
                            <?php if ($field['type'] === 'gps'): ?><i class="bi bi-geo-alt-fill text-success ms-1"></i><?php endif; ?>
                            <?php if (in_array($field['type'], ['camera','photo','signature','barcode','qr_code'], true)): ?><i class="bi bi-camera-fill text-secondary ms-1"></i><?php endif; ?>
                        </label>

                        <?php
                        $required = $field['is_mandatory'] ? 'required' : '';
                        switch ($field['type']) {
                            case 'textarea': echo '<textarea class="form-control" rows="3" placeholder="' . e($field['placeholder'] ?? '') . '" ' . $required . '></textarea>'; break;
                            case 'number':
                            case 'decimal': echo '<input type="number" step="' . ($field['type'] === 'decimal' ? '0.01' : '1') . '" class="form-control" placeholder="' . e($field['placeholder'] ?? '') . '" ' . $required . '>'; break;
                            case 'date': echo '<input type="date" class="form-control" ' . $required . '>'; break;
                            case 'time': echo '<input type="time" class="form-control" ' . $required . '>'; break;
                            case 'dropdown': echo '<select class="form-select" ' . $required . '><option value="">— Select —</option>' . implode('', array_map(fn($o) => '<option>' . e($o['option_label']) . '</option>', $field['options'])) . '</select>'; break;
                            case 'radio': foreach ($field['options'] as $o) { echo '<div class="form-check"><input class="form-check-input" type="radio" name="preview_' . e($field['id']) . '" ' . $required . '><label class="form-check-label">' . e($o['option_label']) . '</label></div>'; } break;
                            case 'checkbox':
                            case 'multi_select': foreach ($field['options'] as $o) { echo '<div class="form-check"><input class="form-check-input" type="checkbox" ' . $required . '><label class="form-check-label">' . e($o['option_label']) . '</label></div>'; } break;
                            case 'gps': echo '<button class="btn btn-outline-success btn-sm" type="button" disabled><i class="bi bi-crosshair"></i> Capture GPS</button>'; break;
                            case 'camera': echo '<button class="btn btn-outline-secondary btn-sm" type="button" disabled><i class="bi bi-camera"></i> Capture Photo</button>'; break;
                            case 'signature': echo '<div class="border rounded p-3 text-muted text-center small">Signature pad placeholder</div>'; break;
                            case 'heading': echo '<h6 class="fw-bold">' . e($field['label']) . '</h6>'; break;
                            case 'auto_number': echo '<input type="text" class="form-control" value="AUTO-0001" disabled>'; break;
                            default: echo '<input type="text" class="form-control" placeholder="' . e($field['placeholder'] ?? '') . '" ' . $required . '>';
                        }
                        ?>
                        <?php if ($field['help_text']): ?>
                        <div class="form-text"><?= e($field['help_text']) ?></div>
                        <?php endif; ?>
                        <?php foreach ($field['validations'] as $v): ?>
                        <div class="form-text text-danger small"><i class="bi bi-shield-exclamation me-1"></i><?= e(ucfirst(str_replace('_', ' ', $v['rule']))) ?> <?= e((string)($v['rule_value'] ?? '')) ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="card-footer bg-white text-end">
                <button class="btn btn-success" disabled><i class="bi bi-send me-1"></i>Submit</button>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean();

echo view('layout', [
    'title'   => 'Preview: ' . $form['title'],
    'content' => $content,
    'user'    => $user,
    'page'    => 'builder',
]);
