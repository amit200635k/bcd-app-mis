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

echo PHP_EOL . "All smoke tests passed." . PHP_EOL;
