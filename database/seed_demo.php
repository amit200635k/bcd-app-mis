<?php

declare(strict_types=1);

/**
 * Seed demo users, a published survey form, and sample records
 * scoped to the Jharkhand hierarchy for live testing.
 *
 * Usage: php database/seed_demo.php
 */

require __DIR__ . '/../common/bootstrap.php';

use App\Audit\AuditLog;
use App\Database\Connection;
use App\Security\Password;
use App\Services\RecordService;
use App\Services\SurveyService;

$pdo = Connection::instance();

$pdo->beginTransaction();
try {
    $roleIds = [];
    foreach ($pdo->query('SELECT id, code FROM roles')->fetchAll() as $r) {
        $roleIds[$r['code']] = (int) $r['id'];
    }

    // Pick Ranchi district and its first block/panchayat/village.
    $ranchi = $pdo->query("SELECT id FROM districts WHERE code = 'JH-RK'")->fetchColumn();
    $block  = $pdo->query('SELECT id FROM blocks WHERE district_id = ' . (int) $ranchi . ' LIMIT 1')->fetchColumn();
    $panch  = $pdo->query('SELECT id FROM panchayats WHERE block_id = ' . (int) $block . ' LIMIT 1')->fetchColumn();
    $village = $pdo->query('SELECT id FROM villages WHERE panchayat_id = ' . (int) $panch . ' LIMIT 1')->fetchColumn();

    $users = [
        ['dh_surveyor',  'Deepak Hans',   '9000000001', 'district', $ranchi],
        ['rk_surveyor',  'Ravi Kumar',    '9000000002', 'surveyor',  $ranchi],
        ['jb_block',     'Jyoti Bharti',  '9000000003', 'block',     $ranchi],
        ['sk_district',  'Suresh Kachhap','9000000004', 'district',  $ranchi],
    ];

    $userInsert = $pdo->prepare(
        'INSERT INTO users (username, password_hash, full_name, mobile, district_id, block_id, status)
         VALUES (:u, :p, :n, :m, :d, :b, "active")'
    );
    $roleAssign = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:u, :r)');
    $userIds = [];

    foreach ($users as [$username, $name, $mobile, $role, $dist]) {
        $userInsert->execute([
            'u' => $username,
            'p' => Password::hash('Demo@123'),
            'n' => $name,
            'm' => $mobile,
            'd' => $dist,
            'b' => $role === 'surveyor' ? $block : null,
        ]);
        $uid = (int) $pdo->lastInsertId();
        $userIds[$username] = $uid;
        $roleAssign->execute(['u' => $uid, 'r' => $roleIds[$role]]);
        if ($role === 'surveyor') {
            // Surveyors additionally get mobile permissions.
            $roleAssign->execute(['u' => $uid, 'r' => $roleIds['surveyor']]);
        }
    }

    $pdo->commit();
    echo "Demo users created: " . implode(', ', array_keys($userIds)) . PHP_EOL;
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'User seeding failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// Survey form + records (outside the user transaction).
$svc = new SurveyService();
$formId = (int) $pdo->query("SELECT id FROM survey_forms WHERE code = 'AGRICULTURE_CENSUS'")->fetchColumn();

if ($formId === 0) {
    $formId = $svc->createForm(1, [
        'code' => 'AGRICULTURE_CENSUS',
        'title' => 'Agriculture Census - Crop & Land Survey',
        'description' => 'Annual agriculture census capturing landholding, crops and irrigation across Jharkhand.',
    ]);
    $versionId = $svc->createVersion($formId, 1, 'initial');
    $svc->saveStructure($formId, $versionId, [
        ['title' => 'Land Details', 'fields' => [
            ['field_key' => 'landowner_name', 'label' => 'Landowner Name', 'type' => 'textbox', 'mandatory' => 1],
            ['field_key' => 'aadhaar', 'label' => 'Aadhaar Number', 'type' => 'textbox', 'validations' => [['rule' => 'aadhaar']]],
            ['field_key' => 'mobile', 'label' => 'Mobile', 'type' => 'textbox', 'validations' => [['rule' => 'mobile']]],
            ['field_key' => 'land_area', 'label' => 'Land Area (acres)', 'type' => 'decimal', 'mandatory' => 1,
                'validations' => [['rule' => 'min', 'rule_value' => '0.1']]],
            ['field_key' => 'irrigation', 'label' => 'Irrigation Type', 'type' => 'dropdown',
                'options' => [['label' => 'Canal', 'value' => 'canal'], ['label' => 'Well', 'value' => 'well'],
                              ['label' => 'Rain-fed', 'value' => 'rainfed'], ['label' => 'Borewell', 'value' => 'borewell']]],
        ]],
        ['title' => 'Crops', 'fields' => [
            ['field_key' => 'crop', 'label' => 'Primary Crop', 'type' => 'radio',
                'options' => [['label' => 'Paddy', 'value' => 'paddy'], ['label' => 'Wheat', 'value' => 'wheat'],
                              ['label' => 'Maize', 'value' => 'maize'], ['label' => 'Pulses', 'value' => 'pulses']]],
            ['field_key' => 'crop_area', 'label' => 'Crop Area (acres)', 'type' => 'number', 'mandatory' => 1],
            ['field_key' => 'sowing_date', 'label' => 'Sowing Date', 'type' => 'date'],
            ['field_key' => 'survey_location', 'label' => 'Plot Location', 'type' => 'gps'],
        ]],
    ]);
    $svc->publish($formId, 1, 'publish v1');
    echo "Survey form AGRICULTURE_CENSUS created & published." . PHP_EOL;
} else {
    $versionId = (int) $pdo->query(
        "SELECT id FROM survey_versions WHERE form_id = {$formId} AND status = 'published' ORDER BY version DESC LIMIT 1"
    )->fetchColumn();
    echo "Survey form AGRICULTURE_CENSUS already exists (v{$versionId})." . PHP_EOL;
}

// Sample records (only if table empty for this form).
$existing = (int) $pdo->query("SELECT COUNT(*) FROM survey_records WHERE form_id = {$formId}")->fetchColumn();
if ($existing === 0 && $versionId > 0) {
    $recordService = new RecordService();
    $sample = [
        ['Ravi Kumar',    '678912345678', '9000000002', '2.5',  'well',    'Paddy',  '1.8', '2026-06-15'],
        ['Mukesh Soren',  '334455667788', '9000000005', '1.2',  'rainfed', 'Maize',  '1.0', '2026-06-18'],
        ['Sita Devi',     '556677889900', '9000000006', '0.8',  'canal',   'Wheat',  '0.6', '2026-06-20'],
        ['Anil Munda',    '112233445566', '9000000007', '3.0',  'borewell', 'Paddy', '2.2', '2026-06-22'],
    ];
    $crops = ['paddy' => [23.3441, 85.3096], 'maize' => [23.3550, 85.3250], 'wheat' => [23.3650, 85.3350], 'paddy2' => [23.3750, 85.3450]];
    foreach ($sample as $i => [$name, $aadhaar, $mobile, $area, $irr, $crop, $cropArea, $sowing]) {
        $loc = $crops['paddy'];
        if ($i === 1) { $loc = $crops['maize']; }
        if ($i === 2) { $loc = $crops['wheat']; }
        if ($i === 3) { $loc = $crops['paddy2']; }
        $recordService->upsert($userIds['rk_surveyor'], [
            'record_uuid' => sprintf('demo-%06d', $i + 1),
            'form_id' => $formId,
            'form_version_id' => $versionId,
            'answers' => [
                'landowner_name' => $name,
                'aadhaar' => $aadhaar,
                'mobile' => $mobile,
                'land_area' => $area,
                'irrigation' => $irr,
                'crop' => $crop,
                'crop_area' => $cropArea,
                'sowing_date' => $sowing,
                'survey_location' => ['lat' => $loc[0], 'lng' => $loc[1]],
            ],
            'gps' => ['latitude' => $loc[0], 'longitude' => $loc[1], 'accuracy' => 4.2],
        ]);
    }
    echo "4 sample survey records created." . PHP_EOL;
} else {
    echo "Records already exist for AGRICULTURE_CENSUS." . PHP_EOL;
}

AuditLog::record('seed.demo', 'setup', null, null, [], ['jharkhand' => true]);
echo "Demo data ready." . PHP_EOL;
