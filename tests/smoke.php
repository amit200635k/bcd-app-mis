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
check('Notification send/deliver', $list[0]['id'] == $nid);
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

echo PHP_EOL . "All smoke tests passed." . PHP_EOL;
