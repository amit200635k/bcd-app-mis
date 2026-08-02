<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Services\SurveyService;

SessionAuth::requireAuth();
SessionAuth::requirePermission('survey_builder.manage');

$user = SessionAuth::user();
$service = new SurveyService();

$formId = (int) ($_GET['id'] ?? 0);
$form = $service->findForm($formId);
if ($form === null) {
    flash('error', 'Form not found.');
    redirect('mis/builder/index.php');
}

// Use draft version (or create one cloned from the published version) for editing.
$pdo = \App\Database\Connection::instance();
$versionId = $service->draftForEditing($formId, $user->id());
$versionInfo = $service->versionInfo($formId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['save', 'save_sync'], true)) {
    $sections = json_decode((string) ($_POST['structure'] ?? '[]'), true);
    if (!is_array($sections)) {
        flash('error', 'Invalid structure payload.');
        redirect('mis/builder/edit.php?id=' . $formId);
    }
    try {
        $service->saveStructure($formId, $versionId, $sections);
        if (($_POST['action'] ?? '') === 'save_sync') {
            redirect('mis/builder/sync.php?id=' . $formId . '&note=' . rawurlencode('Saved & synced via editor'));
        }
        flash('success', 'Form structure saved.');
    } catch (Throwable $e) {
        flash('error', exception_message($e));
    }
    redirect('mis/builder/edit.php?id=' . $formId);
}

$definition = $service->formDefinition($formId, $versionId);
$fieldTypes = SurveyService::FIELD_TYPES;
$validationRules = ['required', 'regex', 'min', 'max', 'min_length', 'max_length', 'email', 'aadhaar', 'pan', 'mobile', 'pincode', 'date'];
$masterGroups = $pdo->query('SELECT id, code, name FROM master_groups ORDER BY name')->fetchAll();

ob_start(); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0"><i class="bi bi-pencil-square me-2"></i><?= e($form['title']) ?></h4>
        <span class="text-muted small">
            <code><?= e($form['code']) ?></code> — editing draft version <?= (int) $definition['version'] ?>
            <?php if ($versionInfo['published_version'] > 0): ?>
                · <span class="badge bg-success">published v<?= (int) $versionInfo['published_version'] ?></span>
                <?php if ($versionInfo['pending_changes']): ?>
                    <span class="badge bg-warning text-dark">pending changes — v<?= (int) $versionInfo['draft_version'] ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </span>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">Back</a>
        <a href="preview.php?id=<?= $formId ?>" class="btn btn-outline-info btn-sm"><i class="bi bi-eye"></i> Preview</a>
        <?php if ($user->hasPermission('survey_builder.publish')): ?>
        <button class="btn btn-primary btn-sm" id="btnSaveSync" form="builderForm" onclick="return confirm('Save draft v<?= (int) $definition['version'] ?> and sync it to all web/mobile users? The live version is replaced immediately.')"><i class="bi bi-arrow-repeat me-1"></i>Save & Sync to All</button>
        <?php endif; ?>
        <button class="btn btn-success btn-sm" id="btnSave"><i class="bi bi-check-lg me-1"></i>Save Structure</button>
    </div>
</div>

<div class="row g-3">
    <!-- Field palette -->
    <div class="col-lg-3">
        <div class="card border-0 shadow-sm sticky-top" style="top:1rem">
            <div class="card-header bg-white fw-semibold">Field Types</div>
            <div class="card-body d-flex flex-wrap gap-1">
                <?php foreach ($fieldTypes as $t): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-add-field="<?= e($t) ?>"><?= e(ucwords(str_replace('_', ' ', $t))) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Form structure -->
    <div class="col-lg-9">
        <form id="builderForm" method="post">
            <input type="hidden" name="action" id="actionInput" value="save">
            <input type="hidden" name="structure" id="structureInput" value="">
            <div id="sections"></div>
        </form>
        <button class="btn btn-outline-primary w-100" id="btnAddSection"><i class="bi bi-plus-lg me-1"></i>Add Section</button>
    </div>
</div>

<script>
const fieldTypes = <?= json_encode($fieldTypes) ?>;
const validationRules = <?= json_encode($validationRules) ?>;
const masterGroups = <?= json_encode($masterGroups) ?>;

// State: array of sections, each with fields.
let state = <?= json_encode($definition['sections']) ?>;

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}

function fieldTypeLabel(t) {
    return t.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function render() {
    const wrap = document.getElementById('sections');
    wrap.innerHTML = '';
    state.forEach((section, si) => {
        const sec = document.createElement('div');
        sec.className = 'card border-0 shadow-sm mb-3';
        sec.innerHTML = `
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <input class="form-control form-control-sm w-50" value="${esc(section.title)}" data-sec-title="${si}">
                <div>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-add-field-sec="${si}"><i class="bi bi-plus-lg"></i> Field</button>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-del-sec="${si}"><i class="bi bi-trash"></i></button>
                </div>
            </div>
            <div class="card-body">
                <div data-sec-fields="${si}"></div>
            </div>`;
        wrap.appendChild(sec);

        const fieldsWrap = sec.querySelector(`[data-sec-fields="${si}"]`);
        (section.fields || []).forEach((field, fi) => fieldsWrap.appendChild(renderField(si, fi, field)));

        sec.querySelector(`[data-sec-title="${si}"]`).addEventListener('change', e => state[si].title = e.target.value);
        sec.querySelector(`[data-del-sec="${si}"]`).addEventListener('click', () => { state.splice(si, 1); render(); });
        sec.querySelector(`[data-add-field-sec="${si}"]`).addEventListener('click', () => {
            state[si].fields = state[si].fields || [];
            state[si].fields.push({ field_key: 'f_' + Date.now(), label: 'New Field', type: 'textbox', mandatory: 0, options: [], validations: [], conditions: [] });
            render();
        });
    });
}

function renderField(si, fi, field) {
    const div = document.createElement('div');
    div.className = 'border rounded p-2 mb-2 bg-light';
    const opts = (field.options || []).map(o => `<option value="${esc(o.option_value || o.value)}" ${o.is_default ? 'selected' : ''}>${esc(o.option_label || o.label)}</option>`).join('');

    let optsHtml = '';
    if (['dropdown','radio','checkbox','multi_select'].includes(field.type)) {
        optsHtml = `<div class="row g-2 align-items-center mt-1">
            <div class="col-9"><input class="form-control form-control-sm" value="${esc(opts)}" placeholder="Option A, Option B, ..." data-options="${si}:${fi}"></div>
            <div class="col-3 text-muted small">comma-separated</div>
        </div>`;
    }

    let masterHtml = '';
    if (field.type === 'master') {
        masterHtml = `<div class="row g-2 align-items-center mt-1">
            <div class="col-md-8">
                <select class="form-select form-select-sm" data-master="${si}:${fi}">
                    <option value="">— Select Master Group —</option>
                    ${masterGroups.map(g => `<option value="${g.id}" ${Number((field.settings||{}).master_group_id) === Number(g.id) ? 'selected' : ''}>${esc(g.name)}</option>`).join('')}
                </select>
            </div>
            <div class="col-4 text-muted small">master data group</div>
        </div>`;
    }

    let cascadeHtml = '';
    if (field.type === 'location_cascade') {
        const levels = (field.settings || {}).levels || ['district', 'block', 'panchayat', 'village'];
        const levelLabels = { district: 'District', block: 'Block', panchayat: 'Panchayat', village: 'Village' };
        cascadeHtml = `<div class="row g-2 align-items-center mt-1">
            <div class="col-md-8">
                <div class="d-flex flex-wrap gap-3" data-cascade="${si}:${fi}">
                    ${Object.keys(levelLabels).map(l => `
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" data-casc-level="${l}" id="casc_${si}_${fi}_${l}" ${levels.includes(l) ? 'checked' : ''}>
                            <label class="form-check-label small" for="casc_${si}_${fi}_${l}">${levelLabels[l]}</label>
                        </div>`).join('')}
                </div>
            </div>
            <div class="col-4 text-muted small">dependent dropdown levels (District → Village)</div>
        </div>`;
    }

    div.innerHTML = `
        <div class="row g-2 align-items-center">
            <div class="col-md-4">
                <input class="form-control form-control-sm" value="${esc(field.label)}" data-label="${si}:${fi}" placeholder="Field label">
            </div>
            <div class="col-md-3">
                <select class="form-select form-select-sm" data-type="${si}:${fi}">
                    ${fieldTypes.map(t => `<option value="${t}" ${t === field.type ? 'selected' : ''}>${fieldTypeLabel(t)}</option>`).join('')}
                </select>
            </div>
            <div class="col-md-2">
                <input class="form-control form-control-sm" value="${esc(field.field_key)}" data-key="${si}:${fi}" placeholder="field_key">
            </div>
            <div class="col-md-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" ${field.mandatory ? 'checked' : ''} data-mandatory="${si}:${fi}" id="mand_${si}_${fi}">
                    <label class="form-check-label small" for="mand_${si}_${fi}">Mandatory</label>
                </div>
            </div>
            <div class="col-md-1 text-end">
                <button type="button" class="btn btn-sm btn-outline-danger" data-del-field="${si}:${fi}"><i class="bi bi-trash"></i></button>
            </div>
        </div>
        <div class="row g-2 align-items-center mt-1">
            <div class="col-md-8">
                <input class="form-control form-control-sm" value="${esc(field.placeholder)}" data-placeholder="${si}:${fi}" placeholder="Placeholder / help text">
            </div>
            <div class="col-md-4">
                <select class="form-select form-select-sm" data-validation="${si}:${fi}" multiple size="1">
                    ${validationRules.map(r => `<option value="${r}" ${(field.validations||[]).some(v => v.rule === r) ? 'selected' : ''}>${r}</option>`).join('')}
                </select>
                <div class="form-text small">validation rules</div>
            </div>
        </div>
        ${optsHtml}
        ${masterHtml}
        ${cascadeHtml}`;

    div.querySelector(`[data-label="${si}:${fi}"]`).addEventListener('change', e => field.label = e.target.value);
    div.querySelector(`[data-key="${si}:${fi}"]`).addEventListener('change', e => field.field_key = e.target.value.replace(/\s+/g, '_'));
    div.querySelector(`[data-placeholder="${si}:${fi}"]`).addEventListener('change', e => field.placeholder = e.target.value);
    div.querySelector(`[data-mandatory="${si}:${fi}"]`).addEventListener('change', e => field.mandatory = e.target.checked ? 1 : 0);
    div.querySelector(`[data-type="${si}:${fi}"]`).addEventListener('change', e => { field.type = e.target.value; render(); });
    div.querySelector(`[data-del-field="${si}:${fi}"]`).addEventListener('click', () => { state[si].fields.splice(fi, 1); render(); });
    div.querySelector(`[data-validation="${si}:${fi}"]`).addEventListener('change', e => {
        const rules = Array.from(e.target.selectedOptions).map(o => o.value);
        field.validations = rules.map(r => ({ rule: r, rule_value: r === 'regex' ? '' : null, error_message: null }));
    });
    if (['dropdown','radio','checkbox','multi_select'].includes(field.type)) {
        const optsInput = div.querySelector(`[data-options="${si}:${fi}"]`);
        optsInput.addEventListener('change', () => {
            field.options = optsInput.value.split(',').map(s => s.trim()).filter(Boolean).map(s => ({ option_label: s, option_value: s.toLowerCase().replace(/\s+/g, '_'), sort_order: 0, is_default: 0 }));
        });
        // hydrate text from stored options
        optsInput.value = (field.options || []).map(o => o.option_label).join(', ');
    }
    if (field.type === 'master') {
        const msel = div.querySelector(`[data-master="${si}:${fi}"]`);
        if (msel) {
            msel.addEventListener('change', e => {
                field.settings = field.settings || {};
                field.settings.master_group_id = e.target.value ? Number(e.target.value) : null;
            });
        }
    }
    if (field.type === 'location_cascade') {
        const box = div.querySelector(`[data-cascade="${si}:${fi}"]`);
        if (box) {
            box.querySelectorAll('input[data-casc-level]').forEach(cb => {
                cb.addEventListener('change', () => {
                    field.settings = field.settings || {};
                    field.settings.levels = Array.from(box.querySelectorAll('input[data-casc-level]:checked')).map(c => c.dataset.cascLevel);
                });
            });
        }
    }
    return div;
}

document.getElementById('btnAddSection').addEventListener('click', () => {
    state.push({ title: 'Untitled Section', description: '', fields: [] });
    render();
});

document.getElementById('btnSave').addEventListener('click', () => {
    document.getElementById('actionInput').value = 'save';
    document.getElementById('structureInput').value = JSON.stringify(state);
    document.getElementById('builderForm').submit();
});

const btnSaveSync = document.getElementById('btnSaveSync');
if (btnSaveSync) {
    btnSaveSync.addEventListener('click', () => {
        document.getElementById('actionInput').value = 'save_sync';
        document.getElementById('structureInput').value = JSON.stringify(state);
    });
}

render();
</script>
<?php $content = ob_get_clean();

echo view('layout', [
    'title'   => 'Edit: ' . $form['title'],
    'content' => $content,
    'user'    => $user,
    'page'    => 'builder',
]);
