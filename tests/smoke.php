<?php

declare(strict_types=1);

/**
 * End-to-end smoke test of the platform services.
 * Usage: php tests/smoke.php
 */

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Services\LocationService;
use App\Services\NotificationService;
use App\Services\ReplicationService;
use App\Services\ReportService;
use App\Services\SurveyService;
use App\Services\UserService;
use App\Models\User;

function check(string $name, bool $ok, string $detail = ''): void
{
    echo ($ok ? "[PASS] " : "[FAIL] ") . $name . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    if (!$ok) {
        exit(1);
    }
}

// 1. Survey form lifecycle
$svc = new SurveyService();
$formId = $svc->createForm(1, ['code' => 'SMOKE_' . time(), 'title' => 'Smoke Test Form']);
$versionId = $svc->createVersion($formId, 1, 'draft');
$svc->saveStructure($formId, $versionId, [
    ['title' => 'A', 'fields' => [
        ['field_key' => 'name', 'label' => 'Name', 'type' => 'textbox', 'mandatory' => 1],
        ['field_key' => 'age', 'label' => 'Age', 'type' => 'number'],
        ['field_key' => 'location', 'label' => 'Location', 'type' => 'location_cascade',
         'settings' => ['levels' => ['district', 'block', 'panchayat', 'village']]],
    ]],
]);
$svc->publish($formId, 1, 'publish');
$def = $svc->formDefinition($formId, $versionId);
check('Form create/version/publish', $def['form']['status'] === 'published', $def['form']['status']);
$locField = $def['sections'][0]['fields'][2] ?? [];
check('Cascade field in definition', ($locField['type'] ?? '') === 'location_cascade' && in_array('village', $locField['settings']['levels'] ?? [], true));

// 1b. Edit published form + sync-to-all flow
$vi = $svc->versionInfo($formId);
check('Version info before edit (no pending)', $vi['published_version'] >= 1 && !$vi['pending_changes'], json_encode($vi));

$editVersionId = $svc->draftForEditing($formId, 1);
$vi2 = $svc->versionInfo($formId);
check('draftForEditing creates pending draft', $vi2['pending_changes'] && $vi2['draft_version'] > $vi['published_version'], json_encode($vi2));

$editDef = $svc->formDefinition($formId, $editVersionId);
check('Edit draft clones published structure', count($editDef['sections'] ?? []) >= 1 && count($editDef['sections'][0]['fields'] ?? []) >= 3, 'fields=' . count($editDef['sections'][0]['fields'] ?? []));

$resumeId = $svc->draftForEditing($formId, 1);
check('draftForEditing resumes existing non-empty draft', $resumeId === $editVersionId, "{$resumeId} vs {$editVersionId}");

$editDef['sections'][0]['fields'][] = ['field_key' => 'sync_extra', 'label' => 'Sync Extra', 'type' => 'textbox', 'mandatory' => 0];
$svc->saveStructure($formId, $editVersionId, $editDef['sections']);
$publishedVersionId = $svc->publish($formId, 1, 'sync via smoke');
$vi3 = $svc->versionInfo($formId);
check('Sync publish bumps published version + clears pending', $vi3['published_version'] === $vi2['draft_version'] && !$vi3['pending_changes'], json_encode($vi3));

$live = $svc->formDefinition($formId);
$liveKeys = array_map(static fn($f) => (string) ($f['field_key'] ?? ''), $live['sections'][0]['fields'] ?? []);
check('Live definition includes synced field', in_array('sync_extra', $liveKeys, true), json_encode($liveKeys));

// 1c. Notification broadcast to all (web + mobile sync signal)
$broadcastId = (new NotificationService())->send('Survey sync', 'New form version available: ' . $live['form']['code'], null, null, 1, 'info');
$recipientCount = (int) \App\Database\Connection::instance()
    ->query('SELECT COUNT(*) FROM notification_recipients WHERE notification_id = ' . (int) $broadcastId)
    ->fetchColumn();
check('Sync notification broadcast to all active users', $recipientCount >= 2, "recipients={$recipientCount}");

// 1d. Conditional logic round-trip (target resolved by field_key, incl. forward reference)
$condFormId = $svc->createForm(1, ['code' => 'SMOKE_COND_' . time(), 'title' => 'Smoke Conditional Form']);
$condVersionId = $svc->createVersion($condFormId, 1, 'draft');
$svc->saveStructure($condFormId, $condVersionId, [
    ['title' => 'C', 'fields' => [
        ['field_key' => 'trigger', 'label' => 'Trigger', 'type' => 'dropdown',
         'options' => [['option_label' => 'Yes', 'option_value' => 'yes']]],
        ['field_key' => 'shown', 'label' => 'Shown Field', 'type' => 'textbox', 'conditions' => [
            ['target_field_key' => 'trigger', 'operator' => 'equals', 'condition_value' => 'yes', 'action' => 'show'],
        ]],
        // forward reference: 'early' condition targets 'later', defined below it
        ['field_key' => 'early', 'label' => 'Early', 'type' => 'textbox', 'conditions' => [
            ['target_field_key' => 'later', 'operator' => 'not_equals', 'condition_value' => '', 'action' => 'hide'],
        ]],
        ['field_key' => 'later', 'label' => 'Later', 'type' => 'textbox'],
    ]],
]);
$condDef = $svc->formDefinition($condFormId, $condVersionId);
$condFields = $condDef['sections'][0]['fields'];
$condByKey = [];
foreach ($condFields as $cf) {
    $condByKey[$cf['field_key']] = $cf;
}
$shownConds = $condByKey['shown']['conditions'] ?? [];
$earlyConds = $condByKey['early']['conditions'] ?? [];
check('Condition target resolved by field_key', count($shownConds) === 1 && ($shownConds[0]['target_field_key'] ?? '') === 'trigger');
check('Forward-referenced condition resolved', count($earlyConds) === 1 && ($earlyConds[0]['target_field_key'] ?? '') === 'later');
$condRow = \App\Database\Connection::instance()
    ->query('SELECT c.target_field_id, f.field_key FROM survey_conditions c JOIN survey_fields f ON f.id = c.target_field_id WHERE c.field_id = ' . (int) $condByKey['early']['id'])
    ->fetch();
check('Forward ref persisted as valid FK', $condRow !== false && $condRow['field_key'] === 'later', json_encode($condRow));
$shownRow = \App\Database\Connection::instance()
    ->query('SELECT c.target_field_id, c.operator, c.condition_value, c.action FROM survey_conditions c WHERE c.field_id = ' . (int) $condByKey['shown']['id'])
    ->fetch();
check('Condition values round-trip intact', $shownRow !== false && $shownRow['operator'] === 'equals' && $shownRow['condition_value'] === 'yes' && $shownRow['action'] === 'show', json_encode($shownRow));

// 2. Record upsert
$rec = new \App\Services\RecordService();
$out = $rec->upsert(1, [
    'record_uuid' => bin2hex(random_bytes(8)),
    'form_id' => $formId,
    'form_version_id' => $versionId,
    'answers' => ['name' => 'Test Person', 'age' => 30,
        'location' => ['district_id' => 20, 'district_name' => 'Ranchi', 'block_id' => 77, 'block_name' => 'Kanke']],
]);
check('Record upsert', $out['status'] === 'submitted');
$locAnswer = \App\Database\Connection::instance()
    ->query('SELECT value_text, value_json FROM survey_answers WHERE record_id = ' . (int) $out['record_id'] . " AND field_key = 'location'")
    ->fetch();
$locJson = json_decode((string) ($locAnswer['value_json'] ?? ''), true);
check('Cascade answer stores id+name', str_contains((string) ($locAnswer['value_text'] ?? ''), 'Ranchi / Kanke') && (int) ($locJson['district_id'] ?? 0) === 20);

// 3. Workflow transition
$rec->transition($out['record_id'], 1, 'published');
check('Workflow transition', $rec->listRecords($formId, 'published')['records'][0]['status'] === 'published');

// 4. Location import
$loc = new LocationService();
$csv = tempnam(sys_get_temp_dir(), 'loc');
file_put_contents($csv, "district,block,panchayat,village\nTest Dist,Test Block,Test Panchayat,Test Village\n");
$stats = $loc->importCsv($csv, 1);
check('Location CSV import', $stats['imported'] === 1, json_encode($stats));

// 5. Notifications
$notif = new NotificationService();
$nid = $notif->send('Smoke', 'Test notification', null, 1, 1);
$list = $notif->forUser(1);
check('Notification send/deliver', in_array($nid, array_column($list, 'id'), false));
check('Unread count', $notif->unreadCount(1) >= 1);
$notif->markRead($nid, 1);

// 6. Replication queue
$repl = new ReplicationService();
$repl->enqueue('survey_records', '1', 'insert', ['id' => 1]);
$repl->processOne(fn($payload) => true);
$stats = $repl->stats();
$ok = array_sum(array_column($stats, 'c')) >= 1;
check('Replication queue drain', $ok, json_encode($stats));

// 7. Reports
$rep = new ReportService();
check('Survey-wise report', count($rep->surveyWise()) >= 1);
check('Status summary', count($rep->statusSummary()) >= 1);
check('GPS missing report', is_array($rep->gpsMissing()));

// 8. Government Building Survey seed integrity (17 sections, published)
$pdo = \App\Database\Connection::instance();
$gbs = $pdo->query("SELECT id, status FROM survey_forms WHERE code = 'GOVT_BUILDING_SURVEY'")->fetch();
check('Govt Building form exists + published', $gbs !== false && $gbs['status'] === 'published', json_encode($gbs));
if ($gbs !== false) {
    $gbsVersionId = $pdo->query(
        "SELECT id FROM survey_versions WHERE form_id = {$gbs['id']} AND status = 'published' ORDER BY version DESC LIMIT 1"
    )->fetchColumn();
    $gbsSections = (int) $pdo->query(
        "SELECT COUNT(*) FROM survey_sections WHERE form_version_id = " . (int) $gbsVersionId
    )->fetchColumn();
    $gbsFields = (int) $pdo->query(
        "SELECT COUNT(*) FROM survey_fields WHERE section_id IN (SELECT id FROM survey_sections WHERE form_version_id = " . (int) $gbsVersionId . ")"
    )->fetchColumn();
    check('Govt Building form has 17 sections', $gbsSections === 17, "sections={$gbsSections}");
    check('Govt Building form has 100+ fields', $gbsFields >= 100, "fields={$gbsFields}");
    check('Govt Building master groups', (int) $pdo->query("SELECT COUNT(*) FROM master_groups WHERE code IN ('DEPARTMENT','BUILDING_SUBCATEGORY')")->fetchColumn() === 2);
}

// 9. Portal & form access helpers
$usvc = new UserService();
$sk = User::find(5);
check('Demo user has mis portal', $sk !== null && $sk->hasPortal('mis'));
check('Demo user form access assigned', $sk !== null && in_array((int) $gbs['id'], $sk->assignedFormIds(), true));
$admin = User::find(1);
check('State admin implicit all portals', $admin->hasPortal('admin') && $admin->hasPortal('mis'));
check('State admin implicit all forms', $admin->canAccessForm((int) $gbs['id']));

// 10. Scope enforcement
$blockAdmin = User::find(4);
$assignable = $usvc->assignableRoles($blockAdmin);
$codes = array_column($assignable, 'code');
check('Block admin cannot assign district role', !in_array('district', $codes, true) && in_array('surveyor', $codes, true), implode(',', $codes));
$blocked = false;
try {
    $usvc->create([
        'username' => 'SMOKE_noscope' . random_int(100, 999),
        'password' => 'StrongPass1',
        'full_name' => 'No Scope',
        'district_id' => 21,
        'roles' => [$assignable[0]['id']],
    ], $blockAdmin->id(), $blockAdmin);
} catch (Throwable) {
    $blocked = true;
}
check('Scope blocks cross-district user create', $blocked);

// 11. assignableForms filtered by actor access
$assignableForms = $usvc->assignableForms($sk);
$allPublished = (int) $pdo->query('SELECT COUNT(*) FROM survey_forms WHERE status = "published" AND is_active = 1')->fetchColumn();
check('assignableForms scoped for district user', count($assignableForms) < $allPublished && count($assignableForms) >= 1, count($assignableForms) . '/' . $allPublished);

// 12. Data scope: own + sub-users only; state admin sees all
$recordSvc = new \App\Services\RecordService();

// Foreign surveyor in a DIFFERENT district (21) — nobody in Ranchi (district 20) may see their data.
$fkUname = 'SMOKE_scope' . random_int(100, 999);
$pdo->prepare('INSERT INTO users (username, password_hash, plain_password, full_name, district_id, status)
               VALUES (:u, :p, :plain, :n, :d, "active")')
    ->execute(['u' => $fkUname, 'p' => \App\Security\Password::hash('StrongPass1'), 'plain' => config('app.env') !== 'production' ? 'StrongPass1' : null, 'n' => 'Foreign Surveyor', 'd' => 21]);
$fkId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO user_roles (user_id, role_id) SELECT :u, id FROM roles WHERE code = "surveyor"')
    ->execute(['u' => $fkId]);

$fkRec = $recordSvc->upsert($fkId, [
    'record_uuid' => 'smoke-scope-' . $fkId,
    'form_id' => $formId,
    'form_version_id' => $versionId,
    'answers' => ['name' => 'Foreign Person', 'age' => 45],
]);
$fkRecordId = (int) $fkRec['record_id'];

$fkRow = $pdo->query('SELECT submitted_by FROM survey_records WHERE id = ' . $fkRecordId)->fetch();
check('upsert sets submitted_by to submitter', (int) $fkRow['submitted_by'] === $fkId, json_encode($fkRow));

$blockAdmin = User::find(4); // jb_block, block 77 / district 20
$fkRecFull = $pdo->query('SELECT * FROM survey_records WHERE id = ' . $fkRecordId)->fetch();
check('Block admin cannot view foreign-district record', !$recordSvc->canView($blockAdmin, $fkRecFull));
check('State admin can view any record', $recordSvc->canView($admin, $fkRecFull));

$blockList = $recordSvc->listRecords($formId, '', 1, 50, $blockAdmin);
$blockUuids = array_column($blockList['records'], 'record_uuid');
check('Block admin list excludes foreign record', !in_array($fkRec['record_uuid'], $blockUuids, true));

$adminList = $recordSvc->listRecords($formId, '', 1, 50, $admin);
$adminUuids = array_column($adminList['records'], 'record_uuid');
check('State admin list includes foreign record', in_array($fkRec['record_uuid'], $adminUuids, true));

$surveyor = User::find(3); // rk_surveyor (leaf)
check('Surveyor scope is own id only', $recordSvc->scopeUserIds($surveyor) === [$surveyor->id()]);
check('Block admin scope is block users + self', in_array(3, $recordSvc->scopeUserIds($blockAdmin), true) && !in_array($fkId, $recordSvc->scopeUserIds($blockAdmin), true));

$detail = $recordSvc->find($fkRecordId);
check('Record detail returns labelled answers', $detail !== null && ($detail['answers'][0]['field_label'] ?? '') !== '', json_encode($detail['answers'][0] ?? null));
check('Record detail shows submitter name', ($detail['submitted_by_name'] ?? '') === 'Foreign Surveyor');
check('Detail not found for missing record', $recordSvc->find(99999999) === null);

// 13. Stored files surface on record detail (photo/answer link round-trip).
$photoAnswer = $pdo->prepare(
    'INSERT INTO survey_answers (record_id, field_id, field_key, value_text, value_json) VALUES (:rid, :fid, :k, :t, :j)'
);
$fid = (int) $pdo->query('SELECT id FROM survey_fields WHERE field_key = "name" LIMIT 1')->fetchColumn();
$photoAnswer->execute([
    'rid' => $fkRecordId, 'fid' => $fid, 'k' => 'photo_front',
    't' => null, 'j' => json_encode(['image_id' => 1, 'file_path' => 'uploads/survey/' . $fkRecordId . '/photo_test.jpg']),
]);
$answerId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO survey_images (record_id, answer_id, file_path, original_name, mime_type, size_bytes, category)
     VALUES (:rid, :aid, :p, :n, :m, :s, "photo")'
)->execute([
    'rid' => $fkRecordId, 'aid' => $answerId,
    'p' => 'uploads/survey/' . $fkRecordId . '/photo_test.jpg', 'n' => 'test.jpg', 'm' => 'image/jpeg', 's' => 100,
]);
$detailWithImage = $recordSvc->find($fkRecordId);
check('Record detail surfaces stored images', count($detailWithImage['images'] ?? []) === 1 && ($detailWithImage['images'][0]['answer_id'] ?? 0) === $answerId);
check('Record detail images carry web path', str_starts_with((string) ($detailWithImage['images'][0]['file_path'] ?? ''), 'uploads/survey/'));

// 14. Reports are scoped to the viewer's hierarchy.
$reportSvc = new ReportService();
$adminReport = $reportSvc->surveyWise($admin);
$blockReport = $reportSvc->surveyWise($blockAdmin);
$smokeRow = static fn(array $rows) => (int) array_reduce(
    $rows,
    static fn(int $c, array $row) => $c + ((int) ($row['id'] ?? 0) === $formId ? (int) $row['total'] : 0),
    0
);
$adminTotal = $smokeRow($adminReport);
$blockTotal = $smokeRow($blockReport);
check('Reports scoped to viewer hierarchy', $adminTotal >= 2 && $blockTotal === 0, "admin={$adminTotal} block={$blockTotal}");

// 15. Detailed, filterable report (pivoted answers) + KPIs.
$locRec = $recordSvc->upsert($admin->id(), [
    'record_uuid' => 'smoke-detail-' . time(),
    'form_id' => $formId,
    'form_version_id' => $versionId,
    'answers' => [
        'name' => 'Detail Report Test', 'age' => 30,
        'location' => ['district_id' => 20, 'district' => 'Ranchi', 'block_id' => 77, 'block' => 'Ranchi'],
    ],
]);

$detailCols = $reportSvc->detailColumns($formId);
check('Detail report detects location pivot columns', isset($detailCols['district']) && isset($detailCols['block']), implode(',', array_keys($detailCols)));

$detailRows = $reportSvc->detailReport(['form_id' => $formId, 'viewer' => $admin], 50, 0);
$found = array_values(array_filter($detailRows, static fn (array $r) => ($r['record_uuid'] ?? '') === $locRec['record_uuid']));
check('Detail report pivots location into district/block', ($found[0]['district'] ?? '') === 'Ranchi' && ($found[0]['block'] ?? '') === 'Ranchi', json_encode($found[0] ?? null));

$detailKpis = $reportSvc->detailKpis(['form_id' => $formId, 'viewer' => $admin]);
check('Detail KPIs aggregate records', ($detailKpis['total'] ?? 0) >= 2 && ($detailKpis['districts'] ?? 0) >= 1, json_encode($detailKpis));

$blockDetailRows = $reportSvc->detailReport(['form_id' => $formId, 'viewer' => $blockAdmin], 50, 0);
$blockDetailUuids = array_column($blockDetailRows, 'record_uuid');
check('Detail report scoped to viewer', !in_array($fkRec['record_uuid'], $blockDetailUuids, true));

// 15b. All-column filters + KPI responsiveness (form-40 fixture, guarded).
$f40 = (int) \App\Database\Connection::instance()
    ->query("SELECT id FROM survey_forms WHERE code = 'GOVT_BUILDING_SURVEY' AND status = 'published' LIMIT 1")
    ->fetchColumn();
if ($f40 > 0) {
    $f40All = $reportSvc->detailReport(['form_id' => $f40, 'viewer' => $admin], 1000, 0);
    if ($f40All !== []) {
        $f40Count = count($f40All);
        $distVal = (string) ($f40All[0]['district'] ?? '');
        if ($distVal !== '') {
            $distFiltered = $reportSvc->detailReport(['form_id' => $f40, 'viewer' => $admin, 'district' => $distVal], 1000, 0);
            check('Detail filter narrows rows by district', count($distFiltered) > 0 && count($distFiltered) <= $f40Count, "all={$f40Count} dist=" . count($distFiltered));
        }

        $f40Cols = $reportSvc->detailColumns($f40);
        if (isset($f40Cols['building_category'])) {
            $cats = $reportSvc->detailFieldDistinct('building_category', $f40, $admin);
            if ($cats !== []) {
                $catFiltered = $reportSvc->detailReport(['form_id' => $f40, 'viewer' => $admin, 'building_category' => $cats[0]], 1000, 0);
                $allMatch = true;
                foreach ($catFiltered as $cr) {
                    if ((string) ($cr['building_category'] ?? '') !== $cats[0]) {
                        $allMatch = false;
                        break;
                    }
                }
                check('Detail exact filter: building_category matches only', $allMatch && $catFiltered !== [], 'cat=' . $cats[0] . ' rows=' . count($catFiltered));
            }
        }
        if (isset($f40Cols['built_up_area'])) {
            $rangeFiltered = $reportSvc->detailReport(['form_id' => $f40, 'viewer' => $admin, 'built_up_area_min' => 0, 'built_up_area_max' => 1000000], 1000, 0);
            check('Detail range filter: built_up_area min/max applied', count($rangeFiltered) === $f40Count, 'all=' . $f40Count . ' range=' . count($rangeFiltered));
        }

        $kpiAll = $reportSvc->detailKpis(['form_id' => $f40, 'viewer' => $admin]);
        $kpiDist = $reportSvc->detailKpis(['form_id' => $f40, 'viewer' => $admin, 'district' => $distVal]);
        check('Detail KPIs respond to filters', ($kpiDist['total'] ?? 0) > 0 && ($kpiDist['total'] ?? 0) <= ($kpiAll['total'] ?? 0), json_encode(['all' => $kpiAll['total'], 'dist' => $kpiDist['total']]));
        check('Detail KPIs report departments/categories', ($kpiAll['departments'] ?? -1) >= 0 && ($kpiAll['categories'] ?? -1) >= 0, json_encode(['dept' => $kpiAll['departments'], 'cat' => $kpiAll['categories']]));
    }
}

echo PHP_EOL . "All smoke tests passed." . PHP_EOL;
