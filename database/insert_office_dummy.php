<?php

declare(strict_types=1);

/**
 * Insert dummy building survey data for OFFICE_BUILDING_SURVEY form
 * using pre-generated data from office_dummy_data.txt
 *
 * Usage:
 *   php database/insert_office_dummy.php
 */

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Database\Connection;
use App\Services\RecordService;
use App\Services\ReplicationService;
use App\Models\User;

$pdo = Connection::instance();

// Get the OFFICE_BUILDING_SURVEY form
$stmt = $pdo->prepare("SELECT id, current_version FROM survey_forms WHERE code = 'OFFICE_BUILDING_SURVEY' AND status = 'published' LIMIT 1");
$stmt->execute();
$form = $stmt->fetch();

if ($form === false) {
    echo "OFFICE_BUILDING_SURVEY form not found. Run seed_office_building.php first.\n";
    exit(1);
}

$formId = (int) $form['id'];
$formVersionId = (int) $form['current_version'];

// Get a surveyor user (use the first active one)
$stmt = $pdo->query("
    SELECT u.id, u.full_name FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'surveyor' AND u.status = 'active' AND u.deleted_at IS NULL
    LIMIT 1
");
$surveyorRow = $stmt->fetch();

if ($surveyorRow === false) {
    echo "No active surveyor found. Run seed_demo.php first.\n";
    exit(1);
}

$surveyorId = (int) $surveyorRow['id'];
$surveyorName = $surveyorRow['full_name'];

// Parse the dummy data file
$content = file_get_contents('D:/Xampp/htdocs/bcd-app/office_dummy_data.txt');

// Parse records from the text file
$records = [];
$lines = explode("\n", file_get_contents('D:/Xampp/htdocs/bcd-app/office_dummy_data.txt'));

$currentRecord = null;
$currentDistrict = '';
$currentBlock = '';
$currentPanchayat = '';
$currentVillage = '';
$currentDistrictId = 0;
$currentBlockId = 0;
$currentPanchayatId = 0;
$currentVillageId = 0;
$currentLocationJson = '';

foreach ($lines as $line) {
    $line = trim($line);
    
    // District header
    if (preg_match('/^District: ([^\(]+) \(ID: (\d+)\) - (\d+) records$/', $line, $matches)) {
        $currentDistrict = $matches[1];
        $currentDistrictId = (int)$matches[2];
        continue;
    }
    
    // Record line
    if (preg_match('/^\s*Record (\d+):$/', $line)) {
        if ($currentRecord !== null) {
            $records[] = $currentRecord;
        }
        $currentRecord = [
            'district' => $currentDistrict,
            'district_id' => 0,
            'block' => '',
            'block_id' => 0,
            'panchayat' => '',
            'panchayat_id' => 0,
            'village' => '',
            'village_id' => 0,
            'location_json' => '',
        ];
        continue;
    }
    
    if ($currentRecord !== null) {
        if (preg_match('/District:\s+([^\(]+)\s+\(ID:\s+(\d+)\)/', $line, $matches)) {
            $currentRecord['district'] = $matches[1];
            $currentRecord['district_id'] = (int)$matches[2];
        } elseif (preg_match('/Block:\s+([^\(]+)\s+\(ID:\s+(\d+)\)/', $line, $matches)) {
            $currentRecord['block'] = $matches[1];
            $currentRecord['block_id'] = (int)$matches[2];
        } elseif (preg_match('/Panchayat:\s+([^\(]+)\s+\(ID:\s+(\d+)\)/', $line, $matches)) {
            $currentRecord['panchayat'] = $matches[1];
            $currentRecord['panchayat_id'] = (int)$matches[2];
        } elseif (preg_match('/Village:\s+([^\(]+)\s+\(ID:\s+(\d+)\)/', $line, $matches)) {
            $currentRecord['village'] = $matches[1];
            $currentRecord['village_id'] = (int)$matches[2];
        } elseif (preg_match('/Location JSON:\s+(.+)$/', $line, $matches)) {
            $currentRecord['location_json'] = $matches[1];
        }
    }
}

if ($currentRecord !== null) {
    $records[] = $currentRecord;
}

echo "Parsed " . count($records) . " records from file.\n";

if (empty($records)) {
    echo "No records parsed. Check file format.\n";
    exit(1);
}

// Get a surveyor user
$stmt = $pdo->query("
    SELECT u.id, u.full_name FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'surveyor' AND u.status = 'active' AND u.deleted_at IS NULL
    LIMIT 1
");
$surveyorRow = $stmt->fetch();

if ($surveyorRow === false) {
    echo "No active surveyor found. Run seed_demo.php first.\n";
    exit(1);
}

$surveyorId = (int) $surveyorRow['id'];
$surveyorName = $surveyorRow['full_name'];

// Get the OFFICE_BUILDING_SURVEY form
$stmt = $pdo->prepare("SELECT id, current_version FROM survey_forms WHERE code = 'OFFICE_BUILDING_SURVEY' AND status = 'published' LIMIT 1");
$stmt->execute();
$form = $stmt->fetch();

if ($form === false) {
    echo "OFFICE_BUILDING_SURVEY form not found. Run seed_office_building.php first.\n";
    exit(1);
}

$formId = (int) $form['id'];
$formVersionId = (int) $form['current_version'];

// Get active surveyors for rotation
$surveyors = $pdo->query("
    SELECT u.id, u.full_name FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'surveyor' AND u.status = 'active' AND u.deleted_at IS NULL
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($surveyors)) {
    echo "No active surveyors found.\n";
    exit(1);
}

$recordService = new RecordService();
$replicationService = new ReplicationService();

$departments = ['Education', 'Health', 'Police', 'Revenue', 'Rural Development', 'Agriculture', 'Forest', 'Municipality'];
$buildingCategories = ['Office', 'Education', 'Health', 'Police', 'Court', 'Community', 'Residential', 'Other'];
$officeTypes = ['Directorate', 'Field Office', 'School', 'Hospital', 'Police Station', 'Court', 'Panchayat Bhavan', 'Residential'];
$structureTypes = ['RCC', 'Steel', 'Brick Masonry', 'Stone Masonry', 'Mixed'];
$yesNo = ['yes', 'no'];
$occupancyStatuses = ['Occupied', 'Partially Occupied', 'Vacant', 'Under Construction', 'Under Repair'];

$buildingLevelMap = [
    'Education' => 'School',
    'Health' => 'Hospital',
    'Police' => 'Police Station',
    'Revenue' => 'Office',
    'Rural Development' => 'Office',
];

$recordCounter = 0;

echo "Inserting " . count($records) . " records for OFFICE_BUILDING_SURVEY form...\n\n";

foreach ($records as $record) {
    $recordCounter++;
    
    // Pick random surveyor
    $surveyor = $surveyors[array_rand($surveyors)];
    $surveyorId = (int) $surveyor['id'];
    $surveyorName = $surveyor['full_name'];
    
    // Get location IDs from parsed data
    $districtId = $record['district_id'];
    $blockId = $record['block_id'];
    $panchayatId = $record['panchayat_id'];
    $villageId = $record['village_id'];
    
    $constructionYear = rand(1960, 2024);
    $buildingAge = date('Y') - $constructionYear;
    $constructionCost = rand(500000, 50000000);
    
    $department = ['Education', 'Health', 'Police', 'Revenue', 'Rural Development', 'Agriculture', 'Forest', 'Municipality'][array_rand(['Education', 'Health', 'Police', 'Revenue', 'Rural Development', 'Agriculture', 'Forest', 'Municipality'])];
    $buildingLevel = ['Education' => 'School', 'Health' => 'Hospital', 'Police' => 'Police Station', 'Revenue' => 'Office', 'Rural Development' => 'Office'][$department] ?? 'Office';
    
    // Dummy photo paths with district ID and record counter
    $photoPaths = [
        'photo_front' => 'uploads/survey/photos/dist' . $districtId . '_front_' . $recordCounter . '.jpg',
        'photo_campus' => 'uploads/survey/photos/dist' . $districtId . '_campus_' . $recordCounter . '.jpg',
        'photo_back' => 'uploads/survey/photos/dist' . $districtId . '_back_' . $recordCounter . '.jpg',
    ];
    
    $lat = 21.5 + (rand(0, 30000) / 10000);
    $lng = 83.5 + (rand(0, 40000) / 10000);
    
    // Build location JSON with names
    $locationJson = json_encode([
        'district_id' => $record['district_id'],
        'district_name' => $record['district'],
        'block_id' => $record['block_id'],
        'block_name' => $record['block'],
        'panchayat_id' => $record['panchayat_id'],
        'panchayat_name' => $record['panchayat'],
        'village_id' => $record['village_id'],
        'village_name' => $record['village'],
    ]);
    
    $department = ['Education', 'Health', 'Police', 'Revenue', 'Rural Development', 'Agriculture', 'Forest', 'Municipality'][array_rand(['Education', 'Health', 'Police', 'Revenue', 'Rural Development', 'Agriculture', 'Forest', 'Municipality'])];
    $buildingLevel = ['Education' => 'School', 'Health' => 'Hospital', 'Police' => 'Police Station', 'Revenue' => 'Office', 'Rural Development' => 'Office'][$department] ?? 'Office';
    $constructionYear = rand(1960, 2024);
    $buildingAge = date('Y') - $constructionYear;
    $constructionCost = rand(500000, 50000000);
    
    $surveyorRec = ['Education', 'Health', 'Police', 'Revenue', 'Rural Development', 'Agriculture', 'Forest', 'Municipality'][array_rand(['Education', 'Health', 'Police', 'Revenue', 'Rural Development', 'Agriculture', 'Forest', 'Municipality'])];
    $surveyorId = $surveyors[array_rand($surveyors)]['id'];
    $surveyorName = $surveyors[array_rand($surveyors)]['full_name'];
    
    $data = [
        'survey_id' => 'OFF-' . date('Ymd') . '-' . str_pad((string)$recordCounter, 6, '0', STR_PAD_LEFT),
        'building_level' => $buildingLevel,
        'building_name' => 'Office Building ' . $recordCounter . ' - ' . $record['district'],
        'department' => $department,
        'office_name' => 'Office ' . $recordCounter,
        'office_incharge' => 'Officer ' . rand(1, 100),
        'contact_mobile' => '9' . rand(100000000, 999999999),
        'contact_email' => 'office' . $recordCounter . '@gov.in',
        'location' => $locationJson,
        'address' => 'Office Address, ' . $record['district'] . ', Jharkhand',
        'landmark' => 'Near NH-' . rand(10, 99),
        'approach_roads' => 'Pucca Road, 2-lane',
        'construction_year' => (string)$constructionYear,
        'building_age' => (string)(date('Y') - $constructionYear),
        'construction_cost' => (string)$constructionCost,
        'physical_status' => 'Good',
        'occupancy_status' => 'Occupied',
        'remarks' => 'Office building survey for ' . $record['district'] . ' district - replication test',
        'geo_location' => json_encode(['lat' => 23.3441, 'lng' => 85.3096]),
        'photo_front' => 'uploads/survey/photos/dist' . $districtId . '_front_' . $recordCounter . '.jpg',
        'photo_campus' => 'uploads/survey/photos/dist' . $districtId . '_campus_' . $recordCounter . '.jpg',
        'photo_back' => 'uploads/survey/photos/dist' . $districtId . '_back_' . $recordCounter . '.jpg',
        'no_floors' => (string)rand(1, 4),
        'no_rooms' => (string)rand(5, 50),
        'toilets_male' => (string)rand(1, 10),
        'toilets_female' => (string)rand(1, 10),
    ];

    try {
        $result = $recordService->upsert($surveyorId, [
            'form_id' => $formId,
            'form_version_id' => $formVersionId,
            'record_uuid' => bin2hex(random_bytes(16)),
            'device_id' => 'auto-office-dist' . $districtId . '-' . $recordCounter,
            'status' => 'submitted',
            'answers' => $data,
            'gps' => [
                'latitude' => 23.3441 + (rand(0, 30000) / 100000),
                'longitude' => 85.3096 + (rand(0, 40000) / 100000),
                'accuracy' => 5.0,
                'captured_at' => date('Y-m-d H:i:s'),
            ],
        ]);

        // Enqueue for replication to all enabled targets
        $targets = $pdo->query("SELECT id, name FROM external_db_configs WHERE enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($targets as $target) {
            $replicationService->enqueue('survey_record', (string)$result['record_id'], 'upsert', [
                'entity_type' => 'survey_record',
                'operation' => 'upsert',
                'form_id' => $formId,
                'data' => $data,
                'record_id' => $result['record_id'],
            ], (int)$target['id']);
        }

        echo "[$recordCounter/" . count($records) . "] " . $record['district'] . " - Record ID: {$result['record_id']} (Survey: {$data['survey_id']})\n";
    } catch (\Throwable $e) {
        echo "ERROR for {$record['district']}: " . $e->getMessage() . "\n";
        // Print validation errors if available
        if (method_exists($e, 'getErrors')) {
            print_r($e->getErrors());
        }
    }
}

echo "\nDone! Inserted " . count($records) . " records for OFFICE_BUILDING_SURVEY form.\n";
echo "Run replication worker to push to external databases:\n";
echo "  php replication/worker.php --daemon\n";