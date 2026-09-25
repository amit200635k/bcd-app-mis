<?php

declare(strict_types=1);

/**
 * Insert dummy building survey data for testing replication.
 * Generates 4-10 records per district for GOVT_BUILDING_SURVEY form.
 *
 * Usage:
 *   php database/insert_dummy_survey.php
 */

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Database\Connection;
use App\Services\RecordService;
use App\Services\ReplicationService;
use App\Models\User;

$pdo = Connection::instance();

// Get a surveyor user
$stmt = $pdo->query("
    SELECT u.id FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'surveyor' AND u.status = 'active' AND u.deleted_at IS NULL
    LIMIT 1
");
$surveyorId = (int) $stmt->fetchColumn();

if ($surveyorId === 0) {
    echo "No active surveyor found. Run seed_demo.php first.\n";
    exit(1);
}

$surveyor = User::find($surveyorId);
if ($surveyor === null) {
    echo "Surveyor not found.\n";
    exit(1);
}

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

// Get all active districts
$districts = $pdo->query("SELECT id, name FROM districts WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

if (empty($districts)) {
    echo "No active districts found.\n";
    exit(1);
}

// Get active surveyors
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
$roofTypes = ['RCC', 'GI Sheet', 'Tile', 'Asbestos', 'Wooden', 'Other'];
$wallMaterials = ['Brick', 'Concrete', 'Stone', 'Mud', 'Precast'];
$roofConditions = ['Excellent', 'Good', 'Fair', 'Poor', 'Damaged'];
$structuralConditions = ['Excellent', 'Good', 'Fair', 'Poor', 'Critical'];
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

echo "Generating dummy records for each district (4-10 records per district)...\n\n";

foreach ($districts as $district) {
    $districtId = (int) $district['id'];
    $districtName = $district['name'];
    
    // Get blocks for this district
    $stmt = $pdo->prepare("SELECT id, name FROM blocks WHERE district_id = :did AND is_active = 1 ORDER BY name");
    $stmt->execute(['did' => $districtId]);
    $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($blocks)) {
        echo "  District: $districtName - No active blocks, skipping\n";
        continue;
    }
    
    // Number of records for this district (4-10)
    $districtRecordCount = rand(4, 10);
    echo "  District: $districtName - Generating $districtRecordCount records\n";
    
    for ($d = 1; $d <= $districtRecordCount; $d++) {
        $recordCounter++;
        
        // Pick random block
        $block = $blocks[array_rand($blocks)];
        $blockId = (int) $block['id'];
        
        // Get panchayats for this block
        $stmt = $pdo->prepare("SELECT id, name FROM panchayats WHERE block_id = :bid AND is_active = 1 ORDER BY name");
        $stmt->execute(['bid' => $blockId]);
        $panchayats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $panchayat = $panchayats ? $panchayats[array_rand($panchayats)] : null;
        $panchayatId = $panchayat ? (int) $panchayat['id'] : 0;
        
        // Get villages for this panchayat
        $villageId = 0;
        if ($panchayatId) {
            $stmt = $pdo->prepare("SELECT id, name FROM villages WHERE panchayat_id = :pid AND is_active = 1 ORDER BY name");
            $stmt->execute(['pid' => $panchayatId]);
            $villages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $village = $villages ? $villages[array_rand($villages)] : null;
            $villageId = $village ? (int) $village['id'] : 0;
        }
        
        // Pick random surveyor
        $surveyor = $surveyors[array_rand($surveyors)];
        $surveyorId = (int) $surveyor['id'];
        $surveyorName = $surveyor['full_name'];
        
        // Location data
        $locationData = [
            'district_id' => $districtId,
            'block_id' => $blockId,
            'panchayat_id' => $panchayatId,
            'village_id' => $villageId,
        ];
        
        // Random GPS coordinates (Jharkhand bounds)
        $lat = 21.5 + (rand(0, 30000) / 10000);
        $lng = 83.5 + (rand(0, 40000) / 10000);
        
        $constructionYear = rand(1960, 2024);
        $buildingAge = date('Y') - $constructionYear;
        $constructionCost = rand(500000, 50000000);
        $builtUpArea = rand(50, 5000) + rand(0, 99) / 100;
        $numFloors = rand(1, 5);
        $numRooms = rand(2, 50);
        $numToilets = rand(1, 20);
        
        $department = $departments[array_rand($departments)];
        $buildingLevel = $buildingLevelMap[$department] ?? 'Office';
        $constructionYearStr = (string)$constructionYear;
        $buildingAgeStr = (string)(date('Y') - $constructionYear);
        $constructionCostStr = (string)$constructionCost;
        
        // Dummy photo paths
        $photoTimestamp = date('His') . '_' . $recordCounter;
        $photoPaths = [
            'photo_front' => 'uploads/survey/photos/dist' . $districtId . '_front_' . $photoTimestamp . '.jpg',
            'photo_campus' => 'uploads/survey/photos/dist' . $districtId . '_campus_' . $photoTimestamp . '.jpg',
            'photo_back' => 'uploads/survey/photos/dist' . $districtId . '_back_' . $photoTimestamp . '.jpg',
        ];
        
        $surveyorRec = $surveyors[array_rand($surveyors)];
        $surveyorId = (int) $surveyorRec['id'];
        $surveyorName = $surveyorRec['full_name'];
        
        $department = $departments[array_rand($departments)];
        $buildingLevel = $buildingLevelMap[$department] ?? 'Office';
        
        $constructionYear = rand(1960, 2024);
        $buildingAge = date('Y') - $constructionYear;
        $constructionCost = rand(500000, 50000000);
        $builtUpArea = rand(50, 5000) + rand(0, 99) / 100;
        $numFloors = rand(1, 5);
        $numRooms = rand(2, 50);
        $numToilets = rand(1, 20);
        
        $data = [
            'survey_id' => 'BS-' . date('Y') . '-' . str_pad((string)$recordCounter, 6, '0', STR_PAD_LEFT),
            'survey_date' => date('Y-m-d', strtotime('-' . rand(0, 365) . ' days')),
            'surveyor_name' => $surveyorName,
            'surveyor_id' => $surveyorId,
            'department' => $department,
            'state' => 'jharkhand',
            'location' => json_encode([
                'district_id' => $districtId,
                'block_id' => $blockId,
                'panchayat_id' => $panchayatId,
                'village_id' => $villageId,
            ]),
            'subdivision' => 'Subdivision ' . rand(1, 10),
            'habitation' => 'Habitation ' . rand(1, 50),
            'capture_gps' => 'yes',
            'latitude' => $lat,
            'longitude' => $lng,
            'elevation' => rand(100, 800),
            'gps_accuracy' => rand(1, 20) + rand(0, 9) / 10,
            'landmark' => 'Near ' . ['Market', 'School', 'Hospital', 'Bus Stand', 'Temple'][array_rand(['Market', 'School', 'Hospital', 'Bus Stand', 'Temple'])],
            'plus_code' => 'ABCD+EFGH',
            'nearest_road' => 'NH-' . rand(10, 99),
            'building_name' => 'Building ' . $recordCounter . ' - ' . ['Admin Block', 'Office', 'Hospital', 'School', 'Court', 'Police Station'][array_rand(['Admin Block', 'Office', 'Hospital', 'School', 'Court', 'Police Station'])],
            'building_code' => 'BLD-' . str_pad((string)$recordCounter, 4, '0', STR_PAD_LEFT),
            'asset_id' => 'AST-' . str_pad((string)$recordCounter, 6, '0', STR_PAD_LEFT),
            'dept_owner' => $departments[array_rand($departments)],
            'office_type' => $officeTypes[array_rand($officeTypes)],
            'building_category' => $buildingCategories[array_rand($buildingCategories)],
            'building_subcategory' => $buildingCategories[array_rand($buildingCategories)],
            'ownership_type' => 'government',
            'occupancy_status' => 'Occupied',
            'controlling_authority' => 'Department of ' . $departments[array_rand($departments)],
            'head_of_office' => 'Officer ' . rand(1, 100),
            'contact_number' => '9' . rand(100000000, 999999999),
            'email' => 'office' . $recordCounter . '@gov.in',
            'office_timing' => '9:00 AM - 5:00 PM',
            'construction_year' => (string)$constructionYear,
            'last_renovation_year' => rand(0, 1) ? (string)rand($constructionYear, 2024) : '',
            'num_floors' => (string)rand(1, 4),
            'basement_available' => $yesNo[array_rand($yesNo)],
            'built_up_area' => (string)(rand(100, 5000)),
            'num_floors' => (string)rand(1, 4),
            'num_rooms' => (string)rand(5, 50),
            'num_toilets' => (string)rand(2, 20),
            'structure_type' => $structureTypes[array_rand($structureTypes)],
            'roof_type' => 'RCC',
            'wall_material' => 'Brick',
            'roof_condition' => 'Good',
            'structural_condition' => 'Good',
            'earthquake_resistant' => $yesNo[array_rand($yesNo)],
            'fire_resistant' => $yesNo[array_rand($yesNo)],
            'utilities' => ['electricity', 'water_supply', 'internet'],
            'ramp_available' => $yesNo[array_rand($yesNo)],
            'wheelchair_accessible' => $yesNo[array_rand($yesNo)],
            'accessible_toilet' => $yesNo[array_rand($yesNo)],
            'parking_available' => 'yes',
            'parking_capacity' => '10',
            'occupied_by' => 'Test Department',
            'num_employees' => (string)rand(5, 50),
            'avg_daily_visitors' => (string)rand(10, 100),
            'working_days' => 'mon_fri',
            'maintenance_agency' => 'PWD',
            'last_maintenance_date' => date('Y-m-d', strtotime('-' . rand(1, 365) . ' days')),
            'maintenance_frequency' => 'monthly',
            'current_condition' => 'Good',
            'repairs_required' => [],
            'fire_exit' => 'yes',
            'emergency_assembly_area' => 'yes',
            'disaster_plan' => 'yes',
            'flood_zone' => 'no',
            'earthquake_zone' => 'no',
            'gps_point' => json_encode(['lat' => $lat, 'lng' => $lng]),
            'boundary_length' => '100',
            'area_calc' => '500',
            'nearby_road' => 'NH-33',
            'distance_main_road' => '50',
            'flood_zone_lookup' => 'none',
            'land_parcel_id' => 'LP-TEST-' . date('His') . '_' . $recordCounter,
            'furniture_count' => '50',
            'computer_count' => '10',
            'printer_count' => '5',
            'vehicle_count' => '2',
            'generator_available' => 'yes',
            'solar_panels' => '0',
            'water_tank_capacity' => '5000',
            'cctv_cameras' => '4',
            'land_ownership' => 'government',
            'land_record_number' => 'LR-TEST-' . date('His') . '_' . $recordCounter,
            'mutation_number' => 'MUT-TEST-' . date('His') . '_' . $recordCounter,
            'building_approval' => 'yes',
            'completion_certificate' => 'yes',
            'occupancy_certificate' => 'yes',
            'capture_room_details' => 'no',
            'general_remarks' => 'Test record for district ' . $districtName . ' - record ' . $d,
            'recommendation' => 'Test record for replication testing',
            'supervisor_name' => 'Supervisor ' . rand(1, 50),
            'verification_date' => date('Y-m-d'),
            // Required ArcGIS fields
            'survey_id' => 'TEST-' . date('Ymd') . '-' . str_pad((string)$recordCounter, 6, '0', STR_PAD_LEFT),
            'building_level' => $buildingLevel,
            'building_name' => 'Test Building ' . $recordCounter . ' - ' . $districtName,
            'department' => $department,
            'office_name' => 'Test Office ' . $recordCounter,
            'office_incharge' => 'Test Officer ' . rand(1, 100),
            'contact_mobile' => '9876543210',
            'contact_email' => 'test@example.com',
            'location' => json_encode(['district_id' => $districtId, 'block_id' => $blockId, 'panchayat_id' => $panchayatId, 'village_id' => $villageId]),
            'address' => 'Test Address, ' . $districtName . ', Jharkhand',
            'landmark' => 'Test Landmark near NH-33',
            'approach_roads' => 'Pucca Road, 2-lane',
            'construction_year' => (string)$constructionYear,
            'building_age' => (string)(date('Y') - $constructionYear),
            'construction_cost' => (string)$constructionCost,
            'physical_status' => 'Good',
            'occupancy_status' => 'Occupied',
            'remarks' => 'Test record for district ' . $districtName . ' - replication testing',
            'geo_location' => json_encode(['lat' => $lat, 'lng' => $lng]),
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
                'device_id' => 'auto-dist-' . $districtId . '-' . $recordCounter,
                'status' => 'submitted',
                'answers' => $data,
                'gps' => [
                    'latitude' => $lat,
                    'longitude' => $lng,
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

            echo "  [$recordCounter] District: $districtName - Record ID: {$result['record_id']} (Survey: {$data['survey_id']})\n";
        } catch (\Throwable $e) {
            echo "  ERROR for district $districtName: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nDone! Generated $recordCounter records across " . count($districts) . " districts.\n";
echo "Run replication worker to push to external databases:\n";
echo "  php replication/worker.php --daemon\n";