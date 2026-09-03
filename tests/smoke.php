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
$sk = User::findByUsername('sk_district');
check('Demo user has mis portal', $sk !== null && $sk->hasPortal('mis'));
check('Demo user form access assigned', $sk !== null && in_array((int) $gbs['id'], $sk->assignedFormIds(), true));
$admin = User::find(1);
check('State admin implicit all portals', $admin->hasPortal('admin') && $admin->hasPortal('mis'));
check('State admin implicit all forms', $admin->canAccessForm((int) $gbs['id']));

// 10. Scope enforcement
$blockAdmin = User::findByUsername('jb_block');
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

$blockAdmin = User::findByUsername('jb_block'); // jb_block, block 77 / district 20
$fkRecFull = $pdo->query('SELECT * FROM survey_records WHERE id = ' . $fkRecordId)->fetch();
check('Block admin cannot view foreign-district record', !$recordSvc->canView($blockAdmin, $fkRecFull));
check('State admin can view any record', $recordSvc->canView($admin, $fkRecFull));

$blockList = $recordSvc->listRecords($formId, '', 1, 50, $blockAdmin);
$blockUuids = array_column($blockList['records'], 'record_uuid');
check('Block admin list excludes foreign record', !in_array($fkRec['record_uuid'], $blockUuids, true));

$adminList = $recordSvc->listRecords($formId, '', 1, 50, $admin);
$adminUuids = array_column($adminList['records'], 'record_uuid');
check('State admin list includes foreign record', in_array($fkRec['record_uuid'], $adminUuids, true));

$surveyor = User::findByUsername('rk_surveyor'); // rk_surveyor (leaf)
check('Surveyor scope is own id only', $recordSvc->scopeUserIds($surveyor) === [$surveyor->id()]);
check('Block admin scope is block users + self', in_array($surveyor->id(), $recordSvc->scopeUserIds($blockAdmin), true) && !in_array($fkId, $recordSvc->scopeUserIds($blockAdmin), true));

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

// 14b. Reports scoped to viewer's form access (district user assigned only one form).
$skReport = $reportSvc->surveyWise($sk);
$skFormIds = array_map('intval', $sk->assignedFormIds());
$skReportFormIds = array_map('intval', array_column($skReport, 'id'));
$skOnlyAssigned = count(array_diff($skReportFormIds, $skFormIds)) === 0;
check('District user report only shows assigned forms', $skOnlyAssigned, json_encode(['assigned' => $skFormIds, 'report' => $skReportFormIds]));

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
            $rangeExpected = 0;
            foreach ($f40All as $ar) {
                $v = (string) ($ar['built_up_area'] ?? '');
                if (preg_match('/^[0-9]+([.][0-9]+)?$/', $v) && (float) $v >= 0 && (float) $v <= 1000000) {
                    $rangeExpected++;
                }
            }
            $rangeFiltered = $reportSvc->detailReport(['form_id' => $f40, 'viewer' => $admin, 'built_up_area_min' => 0, 'built_up_area_max' => 1000000], 1000, 0);
            check('Detail range filter: built_up_area min/max applied', count($rangeFiltered) === $rangeExpected, 'all=' . $f40Count . ' expected=' . $rangeExpected . ' range=' . count($rangeFiltered));
        }

        $kpiAll = $reportSvc->detailKpis(['form_id' => $f40, 'viewer' => $admin]);
        $kpiDist = $reportSvc->detailKpis(['form_id' => $f40, 'viewer' => $admin, 'district' => $distVal]);
        check('Detail KPIs respond to filters', ($kpiDist['total'] ?? 0) > 0 && ($kpiDist['total'] ?? 0) <= ($kpiAll['total'] ?? 0), json_encode(['all' => $kpiAll['total'], 'dist' => $kpiDist['total']]));
        check('Detail KPIs report departments/categories', ($kpiAll['departments'] ?? -1) >= 0 && ($kpiAll['categories'] ?? -1) >= 0, json_encode(['dept' => $kpiAll['departments'], 'cat' => $kpiAll['categories']]));
    }
}

// 16. Role-specific dashboards (demo users by username).
$roleSvc = new \App\Services\RoleDashboardService();
$demoRoles = [
    'dh_surveyor'  => 'district',
    'jb_block'     => 'block',
    'pm_panchayat' => 'panchayat',
    'vp_village'   => 'village',
    'rk_surveyor'  => 'surveyor',
];
$homeByRole = [
    'district' => 'mis/home_district.php', 'block' => 'mis/home_block.php',
    'panchayat' => 'mis/home_panchayat.php', 'village' => 'mis/home_village.php',
    'surveyor' => 'mis/home_surveyor.php',
];
foreach ($demoRoles as $uname => $expectRole) {
    $du = User::findByUsername($uname);
    if ($du === null) {
        check("Role dashboard user {$uname} seeded", false);
        continue;
    }
    check("Role dashboard routes {$uname} to {$expectRole}", $roleSvc->roleOf($du) === $expectRole, $uname . '=' . $roleSvc->roleOf($du));
    check("Role dashboard homeUrl for {$uname}", $du->homeUrl() === $homeByRole[$expectRole], $du->homeUrl());
}
$dhStats = $roleSvc->stats(User::findByUsername('dh_surveyor'));
check('District dashboard unit = Ranchi', ($dhStats['unit'] ?? '') === 'Ranchi' && ($dhStats['unit_type'] ?? '') === 'district', json_encode($dhStats['unit']));
check('District dashboard has sub-units', count($dhStats['children'] ?? []) >= 3 && ($dhStats['children'][0]['count'] ?? 0) > 0, json_encode($dhStats['children']));
check('District dashboard user + surveyor counts', ($dhStats['users']['total'] ?? 0) >= 5 && ($dhStats['users']['surveyors'] ?? 0) >= 1, json_encode($dhStats['users']));

$blockStats = $roleSvc->stats(User::findByUsername('jb_block'));
$subTypes = array_column($blockStats['children'] ?? [], 'type');
check('Block dashboard sub-units are panchayat+village', in_array('panchayat', $subTypes, true) && in_array('village', $subTypes, true), implode(',', $subTypes));

$panchStats = $roleSvc->stats(User::findByUsername('pm_panchayat'));
check('Panchayat dashboard unit resolved', ($panchStats['unit'] ?? '') !== '' && ($panchStats['unit_type'] ?? '') === 'panchayat', json_encode([$panchStats['unit'], $panchStats['unit_type']]));
check('Panchayat dashboard lists villages', (($panchStats['children'][0]['type'] ?? '') === 'village') && ($panchStats['children'][0]['count'] ?? 0) > 0, json_encode($panchStats['children']));

$villageStats = $roleSvc->stats(User::findByUsername('vp_village'));
check('Village dashboard unit resolved', ($villageStats['unit'] ?? '') !== '' && ($villageStats['unit_type'] ?? '') === 'village', json_encode($villageStats['unit']));

$survStats = $roleSvc->stats(User::findByUsername('rk_surveyor'));
$survId = User::findByUsername('rk_surveyor')->id();
$ownStmt = \App\Database\Connection::instance()->prepare('SELECT COUNT(*) FROM survey_records WHERE user_id = :id');
$ownStmt->execute(['id' => $survId]);
$ownRecords = (int) $ownStmt->fetchColumn();
check('Surveyor dashboard scopes to own records', ($survStats['records']['total'] ?? 0) === $ownRecords && $ownRecords >= 4, json_encode(['stats' => $survStats['records']['total'], 'own' => $ownRecords]));
check('Surveyor dashboard exposes accessible forms', ($survStats['forms'] ?? 0) >= 1, 'forms=' . ($survStats['forms'] ?? 0));
check('Surveyor dashboard has no user aggregation', ($survStats['users']['total'] ?? -1) === 0 && ($survStats['children'] ?? null) === [], json_encode($survStats['users']));

// 17. Server-side condition evaluation on record store (Phase 3).
$condFormId = $svc->createForm(1, ['code' => 'SMOKE_COND2_' . time(), 'title' => 'Smoke Condition Store']);
$condVerId = $svc->createVersion($condFormId, 1, 'draft');
$svc->saveStructure($condFormId, $condVerId, [
    ['title' => 'D', 'fields' => [
        ['field_key' => 'trigger', 'label' => 'Trigger', 'type' => 'dropdown',
         'options' => [['option_label' => 'Yes', 'option_value' => 'yes'], ['option_label' => 'No', 'option_value' => 'no']]],
        ['field_key' => 'shown', 'label' => 'Shown', 'type' => 'textbox', 'conditions' => [
            ['target_field_key' => 'trigger', 'operator' => 'equals', 'condition_value' => 'yes', 'action' => 'show'],
        ]],
        ['field_key' => 'condreq', 'label' => 'Cond Req', 'type' => 'textbox', 'conditions' => [
            ['target_field_key' => 'trigger', 'operator' => 'equals', 'condition_value' => 'yes', 'action' => 'required'],
        ]],
        ['field_key' => 'hidden_mand', 'label' => 'Hidden Mandatory', 'type' => 'textbox', 'mandatory' => 1, 'conditions' => [
            ['target_field_key' => 'trigger', 'operator' => 'equals', 'condition_value' => 'never', 'action' => 'show'],
        ]],
    ]],
]);
$svc->publish($condFormId, 1, 'publish');
$condDef2 = $svc->formDefinition($condFormId, $condVerId);
$condSections2 = $condDef2['sections'];
$shown2 = null;
foreach ($condSections2 as $s) {
    foreach ($s['fields'] as $f) {
        if ($f['field_key'] === 'shown') {
            $shown2 = $f;
        }
    }
}
check('Definition exposes condition target_field_key for API', ($shown2['conditions'][0]['target_field_key'] ?? '') === 'trigger');

$condRec = $recordSvc->upsert(1, [
    'record_uuid' => 'smoke-cond-' . time(),
    'form_id' => $condFormId,
    'form_version_id' => $condVerId,
    'answers' => [
        'trigger' => 'yes',
        'shown' => 'visible value',
        'condreq' => 'required value',
        'hidden_mand' => 'stale hidden value',
    ],
]);
$storedStmt = $pdo->prepare('SELECT field_key FROM survey_answers WHERE record_id = :id');
$storedStmt->execute(['id' => $condRec['record_id']]);
$storedKeys = array_column($storedStmt->fetchAll(), 'field_key');
check('Hidden field answer dropped on store', in_array('shown', $storedKeys, true) && !in_array('hidden_mand', $storedKeys, true), implode(',', $storedKeys));

$reqThrew = false;
try {
    $recordSvc->upsert(1, [
        'record_uuid' => 'smoke-cond-req-' . time(),
        'form_id' => $condFormId,
        'form_version_id' => $condVerId,
        'answers' => ['trigger' => 'yes', 'shown' => 'x'],
    ]);
} catch (\App\Exceptions\ValidationException $e) {
    $reqThrew = isset($e->errors()['condreq']);
}
check('Condition-required validated on submit (422)', $reqThrew);

$draftRec = $recordSvc->upsert(1, [
    'record_uuid' => 'smoke-cond-draft-' . time(),
    'form_id' => $condFormId,
    'form_version_id' => $condVerId,
    'status' => 'draft',
    'answers' => ['trigger' => 'no', 'shown' => 'still dropped', 'hidden_mand' => 'draft hidden'],
]);
$draftStmt = $pdo->prepare('SELECT field_key FROM survey_answers WHERE record_id = :id');
$draftStmt->execute(['id' => $draftRec['record_id']]);
$draftKeys = array_column($draftStmt->fetchAll(), 'field_key');
check('Draft skips validation but drops hidden answers', $draftRec['status'] === 'draft' && !in_array('shown', $draftKeys, true) && !in_array('hidden_mand', $draftKeys, true), implode(',', $draftKeys));

// 17b. Conditional structure round-trips through edit + re-publish (stale target ids).
$tripVer = $svc->draftForEditing($condFormId, 1);
$tripDef = $svc->formDefinition($condFormId, $tripVer);
$svc->saveStructure($condFormId, $tripVer, $tripDef['sections']);
$svc->publish($condFormId, 1, 'round-trip');
$tripLive = $svc->formDefinition($condFormId);
$shownTrip = null;
$hiddenTrip = null;
foreach ($tripLive['sections'] as $s) {
    foreach ($s['fields'] as $f) {
        if ($f['field_key'] === 'shown') {
            $shownTrip = $f;
        }
        if ($f['field_key'] === 'hidden_mand') {
            $hiddenTrip = $f;
        }
    }
}
check('Condition target survives edit+re-publish (key resolution)', ($shownTrip['conditions'][0]['target_field_key'] ?? '') === 'trigger' && ($shownTrip['conditions'][0]['target_field_id'] ?? 0) > 0, json_encode($shownTrip['conditions'] ?? []));
check('Mandatory survives edit+re-publish', (int) ($hiddenTrip['is_mandatory'] ?? 0) === 1, 'mandatory=' . ($hiddenTrip['is_mandatory'] ?? '?'));
$tripRec = $recordSvc->upsert(1, [
    'record_uuid' => 'smoke-cond-trip-' . time(),
    'form_id' => $condFormId,
    'form_version_id' => $tripLive['version'],
    'answers' => ['trigger' => 'yes', 'shown' => 'x', 'condreq' => 'y', 'hidden_mand' => 'stale'],
]);
$tripStmt = $pdo->prepare('SELECT field_key FROM survey_answers WHERE record_id = :id');
$tripStmt->execute(['id' => $tripRec['record_id']]);
$tripKeys = array_column($tripStmt->fetchAll(), 'field_key');
check('Round-tripped conditions still drop hidden answers', !in_array('hidden_mand', $tripKeys, true) && in_array('condreq', $tripKeys, true), implode(',', $tripKeys));

// 18. Mobile sync queue populated on record store (Phase 4).
// Purge devices left behind by previously interrupted runs so the per-device
// row counts below stay deterministic.
$pdo->exec("DELETE FROM devices WHERE device_id LIKE 'TEST-DEV-SMOKE-%'");
$syncDevice = 'TEST-DEV-SMOKE-' . time();
$pdo->prepare('INSERT INTO devices (user_id, device_id, device_name, platform, is_active) VALUES (:u, :d, :n, :p, 1)')
    ->execute(['u' => 3, 'd' => $syncDevice, 'n' => 'Smoke Phone', 'p' => 'android']);
$syncDeviceId = (int) $pdo->lastInsertId();

// Upsert a record, then enqueue the sync change (as the API store does).
$syncRec = $recordSvc->upsert(3, [
    'record_uuid' => 'smoke-sync-' . time(),
    'form_id' => $formId,
    'form_version_id' => $versionId,
    'answers' => ['name' => 'Sync Person'],
]);
$recordSvc->enqueueSync(3, $syncDevice, [
    'record_uuid' => $syncRec['record_uuid'],
    'record_id'   => (int) $syncRec['record_id'],
    'survey_code' => (string) $syncRec['survey_code'],
    'form_id'     => $formId,
    'form_version_id' => $versionId,
    'status'      => $syncRec['status'],
]);

$queueStmt = $pdo->prepare('SELECT device_id, record_uuid, action, status, payload_json FROM sync_queue WHERE record_uuid = :u');
$queueStmt->execute(['u' => $syncRec['record_uuid']]);
$queueRows = $queueStmt->fetchAll();
$q = $queueRows[0] ?? null;
$queuePayload = $q !== null ? json_decode((string) $q['payload_json'], true) : null;
check('Record store enqueues pending sync item', $q !== null && (int) $q['device_id'] === $syncDeviceId && $q['action'] === 'upsert' && $q['status'] === 'pending', json_encode($q));
check('Sync payload carries record metadata', ($queuePayload['record_uuid'] ?? '') === $syncRec['record_uuid'] && (int) ($queuePayload['record_id'] ?? 0) === (int) $syncRec['record_id'] && ($queuePayload['form_id'] ?? 0) == $formId, json_encode($queuePayload));
check('Sync payload carries survey_code', ($queuePayload['survey_code'] ?? '') === (string) $syncRec['survey_code'], (string) ($queuePayload['survey_code'] ?? ''));

// Re-enqueueing the same record (re-sync) must not stack duplicates.
$recordSvc->enqueueSync(3, $syncDevice, [
    'record_uuid' => $syncRec['record_uuid'],
    'record_id'   => (int) $syncRec['record_id'],
    'form_id'     => $formId,
    'form_version_id' => $versionId,
    'status'      => $syncRec['status'],
]);
$queueStmt->execute(['u' => $syncRec['record_uuid']]);
$afterResync = $queueStmt->fetchAll();
check('Re-sync replaces the pending item (no duplicates)', count($afterResync) === 1, 'rows=' . count($afterResync));

// sync/status mirror: pending count for the user's devices.
$pending = (int) $pdo->query('SELECT COUNT(*) FROM sync_queue WHERE user_id = 3 AND status = "pending"')->fetchColumn();
check('sync/status pending reflects queued record', $pending >= 1, "pending={$pending}");

// Unknown device id falls back to the user's active devices (still enqueued,
// once per active device — the count is data-dependent, not hardcoded).
$activeDevices = (int) $pdo->query('SELECT COUNT(*) FROM devices WHERE user_id = 3 AND is_active = 1')->fetchColumn();
$recordSvc->enqueueSync(3, 'NO-SUCH-DEVICE', [
    'record_uuid' => $syncRec['record_uuid'],
    'record_id'   => (int) $syncRec['record_id'],
    'form_id'     => $formId,
    'form_version_id' => $versionId,
    'status'      => $syncRec['status'],
]);
$queueStmt->execute(['u' => $syncRec['record_uuid']]);
$afterFallback = $queueStmt->fetchAll();
check('Unknown device falls back to active devices (one row each)', count($afterFallback) === $activeDevices, "rows=" . count($afterFallback) . " devices={$activeDevices}");

// Cleanup: remove the smoke device (cascades its sync_queue rows). The other
// rows belong to the user's remaining active devices.
$pdo->prepare('DELETE FROM devices WHERE id = :id')->execute(['id' => $syncDeviceId]);
$queueStmt->execute(['u' => $syncRec['record_uuid']]);
$afterCascade = $queueStmt->fetchAll();
check('Deleting the device removes its queued items (cascade)', count($afterCascade) === max(0, $activeDevices - 1), 'rows=' . count($afterCascade));

// 19. Survey Unique ID (survey_code): JH/{district}/{MM}/{YY}/{daywise}/{unix4}.
$codeFormId = $svc->createForm(1, ['code' => 'SMOKE_CODE_' . time(), 'title' => 'Smoke Code Form']);
$codeVersionId = $svc->createVersion($codeFormId, 1, 'draft');
$svc->saveStructure($codeFormId, $codeVersionId, [
    ['title' => 'A', 'fields' => [
        ['field_key' => 'survey_id', 'label' => 'Survey ID', 'type' => 'auto_number'],
        ['field_key' => 'name', 'label' => 'Name', 'type' => 'textbox'],
        ['field_key' => 'loc', 'label' => 'Location', 'type' => 'location_cascade',
         'settings' => ['levels' => ['district', 'block', 'panchayat', 'village']]],
    ]],
]);
$svc->publish($codeFormId, 1, 'publish');

// District 21 user (created in sec. 12) → code carries their district; drafts included.
$draftRec = $recordSvc->upsert($fkId, [
    'record_uuid' => 'smoke-code-draft-' . time(),
    'form_id' => $codeFormId,
    'form_version_id' => $codeVersionId,
    'status' => 'draft',
    'answers' => [],
]);
$codePattern = '/^JH\/21\/\d{2}\/\d{2}\/\d{4,}\/\d{4}$/';
check('survey_code format with user district (draft included)', (bool) preg_match($codePattern, (string) $draftRec['survey_code']), (string) $draftRec['survey_code']);

// Re-sync preserves the assigned code.
$redraft = $recordSvc->upsert($fkId, [
    'record_uuid' => $draftRec['record_uuid'],
    'form_id' => $codeFormId,
    'form_version_id' => $codeVersionId,
    'status' => 'submitted',
    'answers' => ['loc' => ['district_id' => 5]],
]);
check('Re-sync keeps the original survey_code', $redraft['survey_code'] === $draftRec['survey_code'], json_encode($redraft));

// Location-cascade district in the answers wins over the user's district.
$locRec = $recordSvc->upsert($fkId, [
    'record_uuid' => 'smoke-code-loc-' . time(),
    'form_id' => $codeFormId,
    'form_version_id' => $codeVersionId,
    'answers' => ['loc' => ['district_id' => 5]],
]);
check('survey_code district prefers submitted location cascade', str_starts_with((string) $locRec['survey_code'], 'JH/05/'), (string) $locRec['survey_code']);

// Daywise counter increments across records.
$seg1 = explode('/', (string) $draftRec['survey_code']);
$seg2 = explode('/', (string) $locRec['survey_code']);
$d1 = (int) ($seg1[4] ?? 0);
$d2 = (int) ($seg2[4] ?? 0);
check('Daywise count increments per record', $d2 === $d1 + 1, "{$d1} -> {$d2}");

// The authoritative code overwrites/stamps every visible auto_number field.
$ansStmt = $pdo->prepare("SELECT value_text FROM survey_answers WHERE record_id = :rid AND field_key = 'survey_id'");
$ansStmt->execute(['rid' => $locRec['record_id']]);
check('auto_number answer carries the server survey_code', (string) $ansStmt->fetchColumn() === (string) $locRec['survey_code']);

// Codes are unique across the table.
$dupe = (int) $pdo->query(
    "SELECT COUNT(*) - COUNT(DISTINCT survey_code) FROM survey_records WHERE survey_code IS NOT NULL"
)->fetchColumn();
check('survey_code unique across all records', $dupe === 0, "dupes={$dupe}");

// 20. Unpublish: move a published form back to draft; hidden from surveyors.
$unpCode = 'SMOKE_UNPUB_' . time();
$unpFormId = $svc->createForm(1, ['code' => $unpCode, 'title' => 'Smoke Unpublish Form']);
$unpVersionId = $svc->createVersion($unpFormId, 1, 'draft');
$svc->saveStructure($unpFormId, $unpVersionId, [
    ['title' => 'A', 'fields' => [
        ['field_key' => 'name', 'label' => 'Name', 'type' => 'textbox'],
    ]],
]);
$svc->publish($unpFormId, 1, 'publish');
$inList = static function () use ($svc, $unpFormId): bool {
    foreach ($svc->publishedForms() as $f) {
        if ((int) $f['id'] === $unpFormId) {
            return true;
        }
    }
    return false;
};
check('Published form listed for surveyors before unpublish', $inList());

$svc->unpublish($unpFormId, 1);
$formRow = $svc->findForm($unpFormId);
check('unpublish flips form status to draft', $formRow !== null && $formRow['status'] === 'draft', (string) ($formRow['status'] ?? ''));
check('Unpublished form NOT listed for surveyors', !$inList());
$pubStmt = $pdo->prepare("SELECT COUNT(*) FROM survey_versions WHERE form_id = :f AND status = 'published'");
$pubStmt->execute(['f' => $unpFormId]);
$pubRows = (int) $pubStmt->fetchColumn();
check('No published version rows remain after unpublish', $pubRows === 0, "published_rows={$pubRows}");
$unpDef = $svc->formDefinition($unpFormId);
$unpFieldKeys = [];
foreach (($unpDef['sections'] ?? []) as $s) {
    foreach (($s['fields'] ?? []) as $f) {
        $unpFieldKeys[] = $f['field_key'];
    }
}
check('Structure survives unpublish (definition falls back to draft version)', in_array('name', $unpFieldKeys, true), json_encode($unpFieldKeys));

try {
    $svc->unpublish($unpFormId, 1);
    check('unpublish on a draft form is rejected', false);
} catch (RuntimeException $e) {
    check('unpublish on a draft form is rejected', str_contains($e->getMessage(), 'Only published forms'), $e->getMessage());
}

// Re-publishing after unpublish reuses the same (draft-downgraded) version and
// restores surveyor visibility — never a blank structure.
$republishedVersionId = $svc->publish($unpFormId, 1, 're-publish');
check('Re-publish reuses the unpublished version id', $republishedVersionId === $unpVersionId, "{$republishedVersionId} vs {$unpVersionId}");
check('Re-published form is listed for surveyors again', $inList());
$reDef = $svc->formDefinition($unpFormId);
$reFields = 0;
foreach (($reDef['sections'] ?? []) as $s) {
    $reFields += count($s['fields'] ?? []);
}
check('Re-published definition keeps its fields', $reFields === 1, "fields={$reFields}");

// 21. Calculated fields: settings.calc evaluates on store and overwrites the client value.
$calcFormId = $svc->createForm(1, ['code' => 'SMOKE_CALC_' . time(), 'title' => 'Smoke Calc Form']);
$calcVersionId = $svc->createVersion($calcFormId, 1, 'draft');
$svc->saveStructure($calcFormId, $calcVersionId, [
    ['title' => 'A', 'fields' => [
        ['field_key' => 'construction_year', 'label' => 'Construction Year', 'type' => 'number'],
        ['field_key' => 'building_age', 'label' => 'Building Age', 'type' => 'number',
            'settings' => ['calc' => ['watch' => 'construction_year', 'expr' => 'current_year - {construction_year}']]],
    ]],
]);
$svc->publish($calcFormId, 1, 'publish');

$calcRec = $recordSvc->upsert($fkId, [
    'record_uuid' => 'smoke-calc-' . time(),
    'form_id' => $calcFormId,
    'form_version_id' => $calcVersionId,
    'answers' => ['construction_year' => '2000', 'building_age' => '999'],
]);
$ageStmt = $pdo->prepare("SELECT value_text FROM survey_answers WHERE record_id = :rid AND field_key = 'building_age'");
$ageStmt->execute(['rid' => $calcRec['record_id']]);
$storedAge = (string) $ageStmt->fetchColumn();
check('Calculated field overwrites client value on store', $storedAge === (string) ((int) date('Y') - 2000), "stored={$storedAge}");

// Without the watched field, any submitted calculated answer is removed.
$calcRec2 = $recordSvc->upsert($fkId, [
    'record_uuid' => $calcRec['record_uuid'],
    'form_id' => $calcFormId,
    'form_version_id' => $calcVersionId,
    'answers' => ['construction_year' => '', 'building_age' => '42'],
]);
$ageStmt->execute(['rid' => $calcRec2['record_id']]);
check('Calculated field dropped when watched value is empty', $ageStmt->fetchColumn() === false);

echo PHP_EOL . "All smoke tests passed." . PHP_EOL;
