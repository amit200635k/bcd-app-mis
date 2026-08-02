<?php

declare(strict_types=1);

/**
 * Create + publish the Government Building Survey form (all 17 sections of
 * Government_Building_Survey_Form.md) as a dynamic survey, plus the master
 * groups it references (DEPARTMENT cascade, BUILDING_SUBCATEGORY).
 *
 * Idempotent — safe to run repeatedly.
 * Usage: php database/seed_govt_building.php
 */

require __DIR__ . '/../common/bootstrap.php';

use App\Database\Connection;
use App\Services\SurveyService;

$pdo = Connection::instance();
$svc = new SurveyService();

function upsertMasterGroup(PDO $pdo, string $code, string $name): int
{
    $pdo->prepare('INSERT INTO master_groups (code, name, is_system) VALUES (:c, :n, 1) ON DUPLICATE KEY UPDATE name = VALUES(name)')
        ->execute(['c' => $code, 'n' => $name]);
    return (int) $pdo->query("SELECT id FROM master_groups WHERE code = '{$code}'")->fetchColumn();
}

/** @param list<array{code:string, name:string, parent?:string}> $items */
function upsertMasterItems(PDO $pdo, int $groupId, array $items): void
{
    $parentCache = [];
    $find = static function (string $code) use ($pdo, $groupId): ?int {
        $stmt = $pdo->prepare('SELECT id FROM master_items WHERE group_id = :g AND code = :c LIMIT 1');
        $stmt->execute(['g' => $groupId, 'c' => $code]);
        return ($id = $stmt->fetchColumn()) !== false ? (int) $id : null;
    };
    $insert = $pdo->prepare(
        'INSERT INTO master_items (group_id, code, name, parent_id, sort_order)
         VALUES (:g, :c, :n, :p, :s)
         ON DUPLICATE KEY UPDATE name = VALUES(name), parent_id = VALUES(parent_id)'
    );
    $sort = 0;
    foreach ($items as $item) {
        $parentId = null;
        if (isset($item['parent'])) {
            $parentId = $parentCache[$item['parent']] ?? $find($item['parent']);
        }
        $insert->execute(['g' => $groupId, 'c' => $item['code'], 'n' => $item['name'], 'p' => $parentId, 's' => ++$sort]);
        $parentCache[$item['code']] = $find($item['code']);
    }
}

// ---------- Master groups ----------
$deptGroup = upsertMasterGroup($pdo, 'DEPARTMENT', 'Department');
upsertMasterItems($pdo, $deptGroup, [
    ['code' => 'EDUCATION', 'name' => 'Education'],
    ['code' => 'EDU_PRIMARY', 'name' => 'Primary School', 'parent' => 'EDUCATION'],
    ['code' => 'EDU_MIDDLE', 'name' => 'Middle School', 'parent' => 'EDUCATION'],
    ['code' => 'EDU_HIGH', 'name' => 'High School', 'parent' => 'EDUCATION'],
    ['code' => 'EDU_COLLEGE', 'name' => 'College', 'parent' => 'EDUCATION'],
    ['code' => 'HEALTH', 'name' => 'Health'],
    ['code' => 'HLT_PHC', 'name' => 'PHC', 'parent' => 'HEALTH'],
    ['code' => 'HLT_CHC', 'name' => 'CHC', 'parent' => 'HEALTH'],
    ['code' => 'HLT_HOSPITAL', 'name' => 'Hospital', 'parent' => 'HEALTH'],
    ['code' => 'HLT_SUBCENTRE', 'name' => 'Health Sub Centre', 'parent' => 'HEALTH'],
    ['code' => 'POLICE', 'name' => 'Police'],
    ['code' => 'REVENUE', 'name' => 'Revenue'],
    ['code' => 'JUDICIARY', 'name' => 'Judiciary'],
    ['code' => 'RURAL_DEV', 'name' => 'Rural Development'],
    ['code' => 'AGRICULTURE', 'name' => 'Agriculture'],
    ['code' => 'FOREST', 'name' => 'Forest'],
    ['code' => 'MUNICIPALITY', 'name' => 'Municipality'],
    ['code' => 'OTHERS', 'name' => 'Others'],
]);

$subcatGroup = upsertMasterGroup($pdo, 'BUILDING_SUBCATEGORY', 'Building Subcategory');
upsertMasterItems($pdo, $subcatGroup, [
    ['code' => 'OFFICE', 'name' => 'Office Building'],
    ['code' => 'SCHOOL', 'name' => 'School'],
    ['code' => 'HOSPITAL', 'name' => 'Hospital'],
    ['code' => 'PHC', 'name' => 'Primary Health Centre'],
    ['code' => 'POLICE_STATION', 'name' => 'Police Station'],
    ['code' => 'COURT', 'name' => 'Court'],
    ['code' => 'PANCHAYAT_BHAVAN', 'name' => 'Panchayat Bhavan'],
    ['code' => 'BLOCK_OFFICE', 'name' => 'Block Office'],
    ['code' => 'TREASURY', 'name' => 'Treasury'],
    ['code' => 'RESIDENTIAL', 'name' => 'Residential Quarter'],
    ['code' => 'STORE', 'name' => 'Store / Godown'],
    ['code' => 'OTHER', 'name' => 'Other'],
]);

echo "Master groups ready (DEPARTMENT, BUILDING_SUBCATEGORY)." . PHP_EOL;

// ---------- Survey form ----------
$existing = (int) $pdo->query("SELECT id FROM survey_forms WHERE code = 'GOVT_BUILDING_SURVEY'")->fetchColumn();
if ($existing > 0) {
    echo "Survey form GOVT_BUILDING_SURVEY already exists (id={$existing}); skipping creation." . PHP_EOL;
    exit(0);
}

$yesNo = [
    ['label' => 'Yes', 'value' => 'yes'],
    ['label' => 'No', 'value' => 'no'],
];

$formId = $svc->createForm(1, [
    'code' => 'GOVT_BUILDING_SURVEY',
    'title' => 'Government Building Survey - Comprehensive',
    'description' => 'Government building inventory and condition survey capturing identification, structure, utilities, accessibility, photos, GIS and legal details.',
]);
$versionId = $svc->createVersion($formId, 1, 'initial');
$svc->saveStructure($formId, $versionId, [

    // ---------- Section 1: Survey Information ----------
    ['title' => 'Section 1: Survey Information', 'fields' => [
        ['field_key' => 'survey_id', 'label' => 'Survey ID', 'type' => 'auto_number', 'mandatory' => 1],
        ['field_key' => 'survey_date', 'label' => 'Survey Date', 'type' => 'date', 'mandatory' => 1],
        ['field_key' => 'surveyor_name', 'label' => 'Surveyor Name', 'type' => 'textbox', 'mandatory' => 1],
        ['field_key' => 'surveyor_id', 'label' => 'Surveyor ID', 'type' => 'textbox', 'mandatory' => 1],
        ['field_key' => 'department', 'label' => 'Department', 'type' => 'master', 'mandatory' => 1,
            'settings' => ['master_group_id' => $deptGroup]],
        ['field_key' => 'state', 'label' => 'State', 'type' => 'dropdown', 'mandatory' => 1,
            'options' => [['label' => 'Jharkhand', 'value' => 'jharkhand']]],
        ['field_key' => 'location', 'label' => 'Location (District → Block → Panchayat → Village)', 'type' => 'location_cascade', 'mandatory' => 1,
            'settings' => ['levels' => ['district', 'block', 'panchayat', 'village']]],
        ['field_key' => 'subdivision', 'label' => 'Subdivision', 'type' => 'textbox'],
        ['field_key' => 'habitation', 'label' => 'Habitation', 'type' => 'textbox'],
    ]],

    // ---------- Section 2: GPS & GIS ----------
    ['title' => 'Section 2: GPS & GIS', 'fields' => [
        ['field_key' => 'capture_gps', 'label' => 'Capture GPS Point', 'type' => 'gps', 'mandatory' => 1],
        ['field_key' => 'latitude', 'label' => 'Latitude', 'type' => 'decimal', 'mandatory' => 1],
        ['field_key' => 'longitude', 'label' => 'Longitude', 'type' => 'decimal', 'mandatory' => 1],
        ['field_key' => 'elevation', 'label' => 'Elevation', 'type' => 'decimal'],
        ['field_key' => 'gps_accuracy', 'label' => 'GPS Accuracy', 'type' => 'decimal', 'mandatory' => 1],
        ['field_key' => 'building_polygon', 'label' => 'Capture Building Polygon (GeoJSON)', 'type' => 'file_upload'],
        ['field_key' => 'landmark', 'label' => 'Landmark', 'type' => 'textbox'],
        ['field_key' => 'plus_code', 'label' => 'Plus Code', 'type' => 'textbox'],
        ['field_key' => 'nearest_road', 'label' => 'Nearest Road', 'type' => 'textbox'],
    ]],

    // ---------- Section 3: Building Identification ----------
    ['title' => 'Section 3: Building Identification', 'fields' => [
        ['field_key' => 'building_name', 'label' => 'Building Name', 'type' => 'textbox', 'mandatory' => 1],
        ['field_key' => 'building_code', 'label' => 'Building Code', 'type' => 'textbox'],
        ['field_key' => 'asset_id', 'label' => 'Asset ID (QR)', 'type' => 'qr_code'],
        ['field_key' => 'dept_owner', 'label' => 'Department Owner', 'type' => 'master', 'mandatory' => 1,
            'settings' => ['master_group_id' => $deptGroup]],
        ['field_key' => 'office_type', 'label' => 'Office Type', 'type' => 'dropdown', 'mandatory' => 1,
            'options' => [
                ['label' => 'Directorate', 'value' => 'directorate'], ['label' => 'Field Office', 'value' => 'field_office'],
                ['label' => 'School', 'value' => 'school'], ['label' => 'Hospital / PHC', 'value' => 'hospital'],
                ['label' => 'Police Station', 'value' => 'police_station'], ['label' => 'Court', 'value' => 'court'],
                ['label' => 'Panchayat Bhavan', 'value' => 'panchayat_bhavan'], ['label' => 'Residential', 'value' => 'residential'],
            ]],
        ['field_key' => 'building_category', 'label' => 'Building Category', 'type' => 'dropdown', 'mandatory' => 1,
            'options' => [
                ['label' => 'Office', 'value' => 'office'], ['label' => 'Education', 'value' => 'education'],
                ['label' => 'Health', 'value' => 'health'], ['label' => 'Police', 'value' => 'police'],
                ['label' => 'Court', 'value' => 'court'], ['label' => 'Community', 'value' => 'community'],
            ]],
        ['field_key' => 'building_subcategory', 'label' => 'Building Subcategory', 'type' => 'master', 'mandatory' => 1,
            'settings' => ['master_group_id' => $subcatGroup]],
        ['field_key' => 'ownership_type', 'label' => 'Ownership Type', 'type' => 'dropdown', 'mandatory' => 1,
            'options' => [
                ['label' => 'Government', 'value' => 'government'], ['label' => 'Leasehold', 'value' => 'leasehold'],
                ['label' => 'Private', 'value' => 'private'], ['label' => 'Other', 'value' => 'other'],
            ]],
        ['field_key' => 'occupancy_status', 'label' => 'Occupancy Status', 'type' => 'dropdown', 'mandatory' => 1,
            'options' => [
                ['label' => 'Occupied', 'value' => 'occupied'], ['label' => 'Partially Occupied', 'value' => 'partially_occupied'],
                ['label' => 'Vacant', 'value' => 'vacant'], ['label' => 'Under Construction', 'value' => 'under_construction'],
                ['label' => 'Under Repair', 'value' => 'under_repair'],
            ]],
    ]],

    // ---------- Section 4: Administrative Details ----------
    ['title' => 'Section 4: Administrative Details', 'fields' => [
        ['field_key' => 'controlling_authority', 'label' => 'Controlling Authority', 'type' => 'textbox'],
        ['field_key' => 'head_of_office', 'label' => 'Head of Office', 'type' => 'textbox'],
        ['field_key' => 'contact_number', 'label' => 'Contact Number', 'type' => 'textbox', 'validations' => [['rule' => 'mobile']]],
        ['field_key' => 'email', 'label' => 'Email', 'type' => 'textbox', 'validations' => [['rule' => 'email']]],
        ['field_key' => 'office_timing', 'label' => 'Office Timing', 'type' => 'textbox'],
    ]],

    // ---------- Section 5: Building Details ----------
    ['title' => 'Section 5: Building Details', 'fields' => [
        ['field_key' => 'construction_year', 'label' => 'Construction Year', 'type' => 'number'],
        ['field_key' => 'last_renovation_year', 'label' => 'Last Renovation Year', 'type' => 'number'],
        ['field_key' => 'num_floors', 'label' => 'Number of Floors', 'type' => 'number'],
        ['field_key' => 'basement_available', 'label' => 'Basement Available', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'built_up_area', 'label' => 'Total Built-up Area (Sq.m.)', 'type' => 'decimal'],
        ['field_key' => 'plot_area', 'label' => 'Plot Area (Sq.m.)', 'type' => 'decimal'],
        ['field_key' => 'carpet_area', 'label' => 'Carpet Area (Sq.m.)', 'type' => 'decimal'],
        ['field_key' => 'building_height', 'label' => 'Building Height (m)', 'type' => 'decimal'],
        ['field_key' => 'num_rooms', 'label' => 'Number of Rooms', 'type' => 'number'],
        ['field_key' => 'num_toilets', 'label' => 'Number of Toilets', 'type' => 'number'],
        ['field_key' => 'num_halls', 'label' => 'Number of Halls', 'type' => 'number'],
        ['field_key' => 'num_staircases', 'label' => 'Number of Staircases', 'type' => 'number'],
        ['field_key' => 'num_lifts', 'label' => 'Number of Lifts', 'type' => 'number'],
    ]],

    // ---------- Section 6: Structural Details ----------
    ['title' => 'Section 6: Structural Details', 'fields' => [
        ['field_key' => 'structure_type', 'label' => 'Structure Type', 'type' => 'radio', 'mandatory' => 1,
            'options' => [
                ['label' => 'RCC', 'value' => 'rcc'], ['label' => 'Steel', 'value' => 'steel'],
                ['label' => 'Brick Masonry', 'value' => 'brick_masonry'], ['label' => 'Stone Masonry', 'value' => 'stone_masonry'],
                ['label' => 'Mixed', 'value' => 'mixed'],
            ]],
        ['field_key' => 'roof_type', 'label' => 'Roof Type', 'type' => 'dropdown',
            'options' => [
                ['label' => 'RCC', 'value' => 'rcc'], ['label' => 'GI Sheet', 'value' => 'gi_sheet'],
                ['label' => 'Tile', 'value' => 'tile'], ['label' => 'Asbestos', 'value' => 'asbestos'],
                ['label' => 'Wooden', 'value' => 'wooden'], ['label' => 'Other', 'value' => 'other'],
            ]],
        ['field_key' => 'wall_material', 'label' => 'Wall Material', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Brick', 'value' => 'brick'], ['label' => 'Concrete', 'value' => 'concrete'],
                ['label' => 'Stone', 'value' => 'stone'], ['label' => 'Mud', 'value' => 'mud'],
                ['label' => 'Precast', 'value' => 'precast'],
            ]],
        ['field_key' => 'flooring_type', 'label' => 'Flooring Type', 'type' => 'textbox'],
        ['field_key' => 'foundation_type', 'label' => 'Foundation Type', 'type' => 'textbox'],
        ['field_key' => 'roof_condition', 'label' => 'Roof Condition', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Excellent', 'value' => 'excellent'], ['label' => 'Good', 'value' => 'good'],
                ['label' => 'Fair', 'value' => 'fair'], ['label' => 'Poor', 'value' => 'poor'],
                ['label' => 'Damaged', 'value' => 'damaged'],
            ]],
        ['field_key' => 'structural_condition', 'label' => 'Structural Condition', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Excellent', 'value' => 'excellent'], ['label' => 'Good', 'value' => 'good'],
                ['label' => 'Fair', 'value' => 'fair'], ['label' => 'Poor', 'value' => 'poor'],
                ['label' => 'Critical', 'value' => 'critical'],
            ]],
        ['field_key' => 'earthquake_resistant', 'label' => 'Earthquake Resistant', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'fire_resistant', 'label' => 'Fire Resistant', 'type' => 'dropdown', 'options' => $yesNo],
    ]],

    // ---------- Section 7: Utilities ----------
    ['title' => 'Section 7: Utilities', 'fields' => [
        ['field_key' => 'utilities', 'label' => 'Utilities Available', 'type' => 'multi_select',
            'options' => [
                ['label' => 'Electricity', 'value' => 'electricity'], ['label' => 'Solar Power', 'value' => 'solar'],
                ['label' => 'Water Supply', 'value' => 'water_supply'], ['label' => 'Rain Water Harvesting', 'value' => 'rain_water_harvesting'],
                ['label' => 'Internet', 'value' => 'internet'], ['label' => 'LAN', 'value' => 'lan'],
                ['label' => 'CCTV', 'value' => 'cctv'], ['label' => 'Fire Alarm', 'value' => 'fire_alarm'],
                ['label' => 'Fire Extinguishers', 'value' => 'fire_extinguishers'], ['label' => 'Generator', 'value' => 'generator'],
                ['label' => 'Lift Working', 'value' => 'lift_working'],
            ]],
    ]],

    // ---------- Section 8: Accessibility ----------
    ['title' => 'Section 8: Accessibility', 'fields' => [
        ['field_key' => 'ramp_available', 'label' => 'Ramp Available', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'wheelchair_accessible', 'label' => 'Wheelchair Accessible', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'accessible_toilet', 'label' => 'Accessible Toilet', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'braille_signage', 'label' => 'Braille Signage', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'parking_available', 'label' => 'Parking Available', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'parking_capacity', 'label' => 'Parking Capacity', 'type' => 'number'],
    ]],

    // ---------- Section 9: Occupancy ----------
    ['title' => 'Section 9: Occupancy', 'fields' => [
        ['field_key' => 'occupied_by', 'label' => 'Occupied By', 'type' => 'textbox'],
        ['field_key' => 'num_employees', 'label' => 'Number of Employees', 'type' => 'number'],
        ['field_key' => 'avg_daily_visitors', 'label' => 'Average Daily Visitors', 'type' => 'number'],
        ['field_key' => 'working_days', 'label' => 'Working Days', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Mon-Fri', 'value' => 'mon_fri'], ['label' => 'Mon-Sat', 'value' => 'mon_sat'],
                ['label' => 'All Days', 'value' => 'all_days'],
            ]],
    ]],

    // ---------- Section 10: Maintenance ----------
    ['title' => 'Section 10: Maintenance', 'fields' => [
        ['field_key' => 'maintenance_agency', 'label' => 'Maintenance Agency', 'type' => 'textbox'],
        ['field_key' => 'last_maintenance_date', 'label' => 'Last Maintenance Date', 'type' => 'date'],
        ['field_key' => 'maintenance_frequency', 'label' => 'Maintenance Frequency', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Weekly', 'value' => 'weekly'], ['label' => 'Monthly', 'value' => 'monthly'],
                ['label' => 'Quarterly', 'value' => 'quarterly'], ['label' => 'Yearly', 'value' => 'yearly'],
                ['label' => 'Never', 'value' => 'never'],
            ]],
        ['field_key' => 'current_condition', 'label' => 'Current Condition', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Excellent', 'value' => 'excellent'], ['label' => 'Good', 'value' => 'good'],
                ['label' => 'Fair', 'value' => 'fair'], ['label' => 'Poor', 'value' => 'poor'],
                ['label' => 'Damaged', 'value' => 'damaged'],
            ]],
        ['field_key' => 'repairs_required', 'label' => 'Repairs Required', 'type' => 'multi_select',
            'options' => [
                ['label' => 'Roof', 'value' => 'roof'], ['label' => 'Wall', 'value' => 'wall'],
                ['label' => 'Flooring', 'value' => 'flooring'], ['label' => 'Plumbing', 'value' => 'plumbing'],
                ['label' => 'Electrical', 'value' => 'electrical'], ['label' => 'Paint', 'value' => 'paint'],
                ['label' => 'Doors', 'value' => 'doors'], ['label' => 'Windows', 'value' => 'windows'],
                ['label' => 'Drainage', 'value' => 'drainage'],
            ]],
    ]],

    // ---------- Section 11: Disaster Preparedness ----------
    ['title' => 'Section 11: Disaster Preparedness', 'fields' => [
        ['field_key' => 'fire_exit', 'label' => 'Fire Exit', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'emergency_assembly_area', 'label' => 'Emergency Assembly Area', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'disaster_plan', 'label' => 'Disaster Plan Available', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'flood_zone', 'label' => 'Flood Zone', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'earthquake_zone', 'label' => 'Earthquake Zone', 'type' => 'dropdown', 'options' => $yesNo],
    ]],

    // ---------- Section 12: Geo-tagged Photos ----------
    ['title' => 'Section 12: Geo-tagged Photos', 'fields' => [
        ['field_key' => 'photo_front', 'label' => 'Front Elevation', 'type' => 'camera'],
        ['field_key' => 'photo_rear', 'label' => 'Rear View', 'type' => 'camera'],
        ['field_key' => 'photo_left', 'label' => 'Left Side', 'type' => 'camera'],
        ['field_key' => 'photo_right', 'label' => 'Right Side', 'type' => 'camera'],
        ['field_key' => 'photo_entrance', 'label' => 'Entrance', 'type' => 'camera'],
        ['field_key' => 'photo_name_board', 'label' => 'Name Board', 'type' => 'camera'],
        ['field_key' => 'photo_roof', 'label' => 'Roof', 'type' => 'camera'],
        ['field_key' => 'photo_interior', 'label' => 'Interior', 'type' => 'camera'],
        ['field_key' => 'photo_electrical_panel', 'label' => 'Electrical Panel', 'type' => 'camera'],
        ['field_key' => 'photo_water_tank', 'label' => 'Water Tank', 'type' => 'camera'],
        ['field_key' => 'photo_toilets', 'label' => 'Toilets', 'type' => 'camera'],
        ['field_key' => 'photo_parking', 'label' => 'Parking', 'type' => 'camera'],
        ['field_key' => 'photo_boundary_wall', 'label' => 'Boundary Wall', 'type' => 'camera'],
        ['field_key' => 'damage_photos', 'label' => 'Damage Photos (Multiple)', 'type' => 'camera', 'allow_multiple' => 1],
    ]],

    // ---------- Section 13: GIS Features ----------
    ['title' => 'Section 13: GIS Features', 'fields' => [
        ['field_key' => 'gps_point', 'label' => 'GPS Point', 'type' => 'gps'],
        ['field_key' => 'boundary_length', 'label' => 'Boundary Length (m)', 'type' => 'decimal'],
        ['field_key' => 'area_calc', 'label' => 'Area Calculation (Sq.m.)', 'type' => 'decimal'],
        ['field_key' => 'nearby_road', 'label' => 'Nearby Road', 'type' => 'textbox'],
        ['field_key' => 'distance_main_road', 'label' => 'Distance from Main Road (m)', 'type' => 'decimal'],
        ['field_key' => 'flood_zone_lookup', 'label' => 'Flood Zone Lookup', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Not in Flood Zone', 'value' => 'none'], ['label' => 'Low', 'value' => 'low'],
                ['label' => 'Medium', 'value' => 'medium'], ['label' => 'High', 'value' => 'high'],
            ]],
        ['field_key' => 'land_parcel_id', 'label' => 'Land Parcel ID', 'type' => 'textbox'],
    ]],

    // ---------- Section 14: Asset Inventory ----------
    ['title' => 'Section 14: Asset Inventory', 'fields' => [
        ['field_key' => 'furniture_count', 'label' => 'Furniture Count', 'type' => 'number'],
        ['field_key' => 'computer_count', 'label' => 'Computer Count', 'type' => 'number'],
        ['field_key' => 'printer_count', 'label' => 'Printer Count', 'type' => 'number'],
        ['field_key' => 'vehicle_count', 'label' => 'Vehicle Count', 'type' => 'number'],
        ['field_key' => 'generator_available', 'label' => 'Generator', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'solar_panels', 'label' => 'Solar Panels', 'type' => 'number'],
        ['field_key' => 'water_tank_capacity', 'label' => 'Water Tank Capacity (L)', 'type' => 'decimal'],
        ['field_key' => 'cctv_cameras', 'label' => 'CCTV Cameras', 'type' => 'number'],
    ]],

    // ---------- Section 15: Legal Information ----------
    ['title' => 'Section 15: Legal Information', 'fields' => [
        ['field_key' => 'land_ownership', 'label' => 'Land Ownership', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Government', 'value' => 'government'], ['label' => 'Leasehold', 'value' => 'leasehold'],
                ['label' => 'Private', 'value' => 'private'], ['label' => 'Other', 'value' => 'other'],
            ]],
        ['field_key' => 'land_record_number', 'label' => 'Land Record Number', 'type' => 'textbox'],
        ['field_key' => 'mutation_number', 'label' => 'Mutation Number', 'type' => 'textbox'],
        ['field_key' => 'building_approval', 'label' => 'Building Approval Available', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'completion_certificate', 'label' => 'Completion Certificate', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'occupancy_certificate', 'label' => 'Occupancy Certificate', 'type' => 'dropdown', 'options' => $yesNo],
    ]],

    // ---------- Section 16: Room / Floor Details (optional) ----------
    ['title' => 'Section 16: Room / Floor Details', 'fields' => [
        ['field_key' => 'capture_room_details', 'label' => 'Capture Room Details', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'floor_number', 'label' => 'Floor Number', 'type' => 'number'],
        ['field_key' => 'floor_name', 'label' => 'Floor Name', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Basement', 'value' => 'basement'], ['label' => 'Ground Floor', 'value' => 'ground'],
                ['label' => 'First Floor', 'value' => 'first'], ['label' => 'Second Floor', 'value' => 'second'],
                ['label' => 'Third Floor', 'value' => 'third'], ['label' => 'Fourth Floor', 'value' => 'fourth'],
                ['label' => 'Fifth Floor', 'value' => 'fifth'], ['label' => 'Terrace', 'value' => 'terrace'],
                ['label' => 'Other', 'value' => 'other'],
            ]],
        ['field_key' => 'wing_block', 'label' => 'Wing / Block', 'type' => 'textbox'],
        ['field_key' => 'room_number', 'label' => 'Room Number', 'type' => 'textbox'],
        ['field_key' => 'room_name', 'label' => 'Room Name', 'type' => 'textbox'],
        ['field_key' => 'room_type', 'label' => 'Room Type', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Office', 'value' => 'office'], ['label' => 'Chamber', 'value' => 'chamber'],
                ['label' => 'Cabin', 'value' => 'cabin'], ['label' => 'Conference Room', 'value' => 'conference'],
                ['label' => 'Meeting Hall', 'value' => 'meeting_hall'], ['label' => 'Record Room', 'value' => 'record_room'],
                ['label' => 'Store Room', 'value' => 'store_room'], ['label' => 'Computer Room', 'value' => 'computer_room'],
                ['label' => 'Server Room', 'value' => 'server_room'], ['label' => 'Laboratory', 'value' => 'laboratory'],
                ['label' => 'Library', 'value' => 'library'], ['label' => 'Classroom', 'value' => 'classroom'],
                ['label' => 'Training Hall', 'value' => 'training_hall'], ['label' => 'Reception', 'value' => 'reception'],
                ['label' => 'Toilet', 'value' => 'toilet'], ['label' => 'Pantry', 'value' => 'pantry'],
                ['label' => 'Electrical Room', 'value' => 'electrical_room'], ['label' => 'Security Room', 'value' => 'security_room'],
                ['label' => 'Control Room', 'value' => 'control_room'], ['label' => 'Dormitory', 'value' => 'dormitory'],
                ['label' => 'Residential Quarter', 'value' => 'residential_quarter'], ['label' => 'Other', 'value' => 'other'],
            ]],
        ['field_key' => 'room_usage', 'label' => 'Room Usage', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Administrative', 'value' => 'administrative'], ['label' => 'Public Service', 'value' => 'public_service'],
                ['label' => 'Storage', 'value' => 'storage'], ['label' => 'Technical', 'value' => 'technical'],
                ['label' => 'Training', 'value' => 'training'], ['label' => 'Health Service', 'value' => 'health_service'],
                ['label' => 'Educational', 'value' => 'educational'], ['label' => 'Security', 'value' => 'security'],
                ['label' => 'Utility', 'value' => 'utility'], ['label' => 'Vacant', 'value' => 'vacant'],
                ['label' => 'Under Renovation', 'value' => 'under_renovation'], ['label' => 'Other', 'value' => 'other'],
            ]],
        ['field_key' => 'room_area', 'label' => 'Room Area (Sq.m.)', 'type' => 'decimal'],
        ['field_key' => 'room_occupancy', 'label' => 'Room Occupancy', 'type' => 'number'],
        ['field_key' => 'room_condition', 'label' => 'Room Condition', 'type' => 'dropdown',
            'options' => [
                ['label' => 'Excellent', 'value' => 'excellent'], ['label' => 'Good', 'value' => 'good'],
                ['label' => 'Fair', 'value' => 'fair'], ['label' => 'Poor', 'value' => 'poor'],
                ['label' => 'Damaged', 'value' => 'damaged'], ['label' => 'Under Repair', 'value' => 'under_repair'],
            ]],
        ['field_key' => 'air_conditioned', 'label' => 'Air Conditioned', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'room_furniture', 'label' => 'Furniture Available', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'room_internet', 'label' => 'Internet Available', 'type' => 'dropdown', 'options' => $yesNo],
        ['field_key' => 'room_photo', 'label' => 'Room Photo (Geotagged)', 'type' => 'camera'],
        ['field_key' => 'room_remarks', 'label' => 'Remarks', 'type' => 'textarea'],
    ]],

    // ---------- Section 17: Remarks & Verification ----------
    ['title' => 'Section 17: Remarks & Verification', 'fields' => [
        ['field_key' => 'general_remarks', 'label' => 'General Remarks', 'type' => 'textarea'],
        ['field_key' => 'recommendation', 'label' => 'Recommendation', 'type' => 'textarea'],
        ['field_key' => 'supervisor_name', 'label' => 'Supervisor Name', 'type' => 'textbox'],
        ['field_key' => 'supervisor_signature', 'label' => 'Supervisor Signature', 'type' => 'signature'],
        ['field_key' => 'surveyor_signature', 'label' => 'Surveyor Signature', 'type' => 'signature'],
        ['field_key' => 'verification_date', 'label' => 'Verification Date', 'type' => 'date'],
    ]],
]);

$svc->publish($formId, 1, 'Government Building Survey v1');
echo "Survey form GOVT_BUILDING_SURVEY created & published (id={$formId})." . PHP_EOL;

// Grant access to the new form for admin + demo users (state admin is implicit).
$pdo->prepare('INSERT IGNORE INTO user_form_access (user_id, form_id, granted_by) VALUES (:u, :f, 1)')
    ->execute(['u' => 1, 'f' => $formId]);
foreach ($pdo->query("SELECT id FROM users WHERE username IN ('dh_surveyor','rk_surveyor','jb_block','sk_district')")->fetchAll() as $u) {
    $pdo->prepare('INSERT IGNORE INTO user_form_access (user_id, form_id, granted_by) VALUES (:u, :f, 1)')
        ->execute(['u' => $u['id'], 'f' => $formId]);
}
echo "Form access granted to demo users." . PHP_EOL;
