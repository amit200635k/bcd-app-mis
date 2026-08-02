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
                            case 'dropdown':
                            case 'master': echo '<select class="form-select" ' . $required . '><option value="">— Select —</option>' . implode('', array_map(fn($o) => '<option value="' . e($o['option_value']) . '">' . e($o['option_label']) . '</option>', $field['options'])) . '</select>'; break;
                            case 'location_cascade':
                                $levels = array_values(array_intersect(['district','block','panchayat','village'], $field['settings']['levels'] ?? ['district','block','panchayat','village']));
                                $labels = ['district' => 'District', 'block' => 'Block', 'panchayat' => 'Panchayat', 'village' => 'Village'];
                                echo '<div data-cascade data-field-id="' . (int) $field['id'] . '" data-levels="' . e(implode(',', $levels)) . '">';
                                foreach ($levels as $li => $lv) {
                                    $last = $li === count($levels) - 1;
                                    echo '<div class="mb-2"><label class="form-label small fw-semibold text-muted mb-1">' . $labels[$lv] . '</label>';
                                    echo '<select class="form-select" data-level="' . $lv . '" data-parent="' . ($li > 0 ? 'prev' : '') . '" ' . ($required && $last ? 'required' : '') . '><option value="">— Select ' . $labels[$lv] . ' —</option></select></div>';
                                }
                                echo '</div>';
                                break;
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
<script>
(function () {
    const BASE = '<?= e(rtrim((string) config('app.url'), '/')) ?>';
    const scopes = { district: 'district_id', block: 'block_id', panchayat: 'panchayat_id', village: 'village_id' };
    const childOf = { block: 'district', panchayat: 'block', village: 'panchayat' };
    const qp = { block: 'district_id', panchayat: 'block_id', village: 'panchayat_id' };

    async function load(select, url, selectedId) {
        const res = await fetch(url);
        const data = await res.json();
        const items = data.items || [];
        select.innerHTML = '<option value="">— Select —</option>' +
            items.map(i => `<option value="${i.id}" ${selectedId && Number(i.id) === Number(selectedId) ? 'selected' : ''}>${i.name}</option>`).join('');
        return items;
    }

    document.querySelectorAll('[data-cascade]').forEach(async (cascade) => {
        const levels = (cascade.dataset.levels || 'district,block,panchayat,village').split(',').filter(Boolean);
        const selects = levels.map(l => cascade.querySelector(`select[data-level="${l}"]`));

        // Resolve the current user's fixed scope so district-level admins see
        // their district pre-selected and blocks auto-populate.
        let scope = {};
        try {
            const r = await fetch(BASE + '/api/dropdowns.php?type=scope');
            scope = (await r.json()).items || {};
        } catch (e) { /* scope unknown — state admin style */ }

        for (let i = 0; i < levels.length; i++) {
            const level = levels[i];
            const sel = selects[i];
            const fixed = scopes[level];
            const scopedId = scope[fixed];
            const parentSel = i > 0 ? selects[i - 1] : null;

            const populateFromParent = async (parentValue) => {
                if (level === 'district') {
                    await load(sel, BASE + '/api/dropdowns.php?type=district', scopedId);
                    return;
                }
                if (!parentValue) {
                    sel.innerHTML = '<option value="">— Select —</option>';
                    return;
                }
                await load(sel, BASE + `/api/dropdowns.php?type=${level}&${qp[level]}=${parentValue}`, scopedId);
            };

            sel.addEventListener('change', async () => {
                for (let j = i + 1; j < levels.length; j++) {
                    const next = selects[j];
                    next.innerHTML = '<option value="">— Select —</option>';
                }
                if (i + 1 < levels.length) {
                    await populateFromParent(sel.value);
                }
            });

            await populateFromParent(parentSel ? parentSel.value : null);
        }

        // If this user is scoped to a district/block, lock the topmost scoped select.
        for (let i = 0; i < levels.length; i++) {
            const fixed = scopes[levels[i]];
            if (scope[fixed]) {
                const sel = selects[i];
                sel.value = String(scope[fixed]);
                sel.disabled = true;
                sel.classList.add('bg-light');
                // populate the next level from the locked value
                if (i + 1 < levels.length) {
                    const next = levels[i + 1];
                    const nextSel = selects[i + 1];
                    await load(nextSel, BASE + `/api/dropdowns.php?type=${next}&${qp[next]}=${scope[fixed]}`);
                    if (scope[scopes[next]] && i + 2 < levels.length) {
                        const nnext = levels[i + 2];
                        await load(selects[i + 2], BASE + `/api/dropdowns.php?type=${nnext}&${qp[nnext]}=${scope[scopes[next]]}`);
                    }
                }
                break;
            }
        }
    });
})();
</script>
<?php $content = ob_get_clean();

echo view('layout', [
    'title'   => 'Preview: ' . $form['title'],
    'content' => $content,
    'user'    => $user,
    'page'    => 'builder',
]);
