<?php

declare(strict_types=1);

/**
 * Create + publish the Office Building Survey form.
 *
 * Inventories government office buildings: identification (building level,
 * department, office), in-charge contact details, location/address, building
 * particulars (approach roads, construction year/cost, floors/rooms/toilets,
 * standardized DOM_PHYSICAL_STATUS + DOM_Occupancy_Status master dropdowns),
 * remarks and geo-tagged photos (front / campus / backside).
 *
 * Idempotent - safe to run repeatedly.
 * Usage: php database/seed_office_building.php
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

/** @param list<array{code:string, name:string}> $items */
function upsertMasterItems(PDO $pdo, int $groupId, array $items): void
{
    $insert = $pdo->prepare(
        'INSERT INTO master_items (group_id, code, name, sort_order)
         VALUES (:g, :c, :n, :s)
         ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order)'
    );
    foreach ($items as $i => $item) {
        $insert->execute(['g' => $groupId, 'c' => $item['code'], 'n' => $item['name'], 's' => $i]);
    }
}

// ---------- Master groups (standardized dashboard statistics) ----------
$physicalGroup = upsertMasterGroup($pdo, 'DOM_PHYSICAL_STATUS', 'Physical Status');
upsertMasterItems($pdo, $physicalGroup, [
    ['code' => 'GOOD', 'name' => 'Good'],
    ['code' => 'FAIR', 'name' => 'Fair / Minor Repair Required'],
    ['code' => 'POOR', 'name' => 'Poor'],
    ['code' => 'MAJOR', 'name' => 'Major Repair Required'],
    ['code' => 'DILAPIDATED', 'name' => 'Dilapidated'],
    ['code' => 'UNDER_CONST', 'name' => 'Under Construction'],
    ['code' => 'ABANDONED', 'name' => 'Abandoned'],
    ['code' => 'DEMOLISHED', 'name' => 'Demolished'],
]);

$occupancyGroup = upsertMasterGroup($pdo, 'DOM_OCCUPANCY_STATUS', 'Occupancy Status');
upsertMasterItems($pdo, $occupancyGroup, [
    ['code' => 'OCCUPIED', 'name' => 'Fully Occupied'],
    ['code' => 'PARTIAL', 'name' => 'Partially Occupied'],
    ['code' => 'VACANT', 'name' => 'Vacant'],
    ['code' => 'UNUSED', 'name' => 'Not in Use'],
    ['code' => 'UNDER_CONST', 'name' => 'Under Construction'],
]);

// Department Name reuses the shared DEPARTMENT master group.
$deptGroup = (int) $pdo->query("SELECT id FROM master_groups WHERE code = 'DEPARTMENT'")->fetchColumn();

// ---------- Survey form ----------
$existing = (int) $pdo->query("SELECT id FROM survey_forms WHERE code = 'OFFICE_BUILDING_SURVEY'")->fetchColumn();
if ($existing > 0) {
    echo "Survey form OFFICE_BUILDING_SURVEY already exists (id={$existing}); masters ensured, skipping creation." . PHP_EOL;
    exit(0);
}

$formId = $svc->createForm(1, [
    'code' => 'OFFICE_BUILDING_SURVEY',
    'title' => 'Office Building Survey',
    'description' => 'Inventory of government office buildings: identification, department, office in-charge and contact, location/address, building particulars (construction, floors, rooms, toilets, physical & occupancy status) with geo-tagged photos.',
]);
$versionId = $svc->createVersion($formId, 1, 'initial');
$svc->saveStructure($formId, $versionId, [

    // ---------- Section 1: Identification ----------
    ['title' => 'Section 1: Identification', 'fields' => [
        ['field_key' => 'survey_id', 'label' => 'Survey ID', 'type' => 'auto_number', 'mandatory' => 1,
            'help_text' => 'Auto-generated unique survey ID (JH/district/date/count/time).'],
        ['field_key' => 'building_level', 'label' => 'Building Level', 'type' => 'dropdown', 'mandatory' => 1,
            'options' => [
                ['label' => 'State', 'value' => 'state'],
                ['label' => 'District', 'value' => 'district'],
                ['label' => 'Sub Division', 'value' => 'sub_division'],
                ['label' => 'Block', 'value' => 'block'],
                ['label' => 'Panchayat', 'value' => 'panchayat'],
            ]],
        ['field_key' => 'building_name', 'label' => 'Building Name', 'type' => 'textbox', 'mandatory' => 1],
        ['field_key' => 'department', 'label' => 'Department Name', 'type' => 'master', 'mandatory' => 1,
            'settings' => ['master_group_id' => $deptGroup]],
        ['field_key' => 'office_name', 'label' => 'Office Name', 'type' => 'textbox', 'mandatory' => 1],
    ]],

    // ---------- Section 2: Office In-charge & Contact ----------
    ['title' => 'Section 2: Office In-charge & Contact', 'fields' => [
        ['field_key' => 'office_incharge', 'label' => 'Office In-charge', 'type' => 'textbox', 'mandatory' => 1],
        ['field_key' => 'contact_mobile', 'label' => 'Contact - Mobile', 'type' => 'textbox',
            'help_text' => '10-digit mobile number.'],
        ['field_key' => 'contact_email', 'label' => 'Contact - Email', 'type' => 'textbox'],
    ]],

    // ---------- Section 3: Location ----------
    ['title' => 'Section 3: Location', 'fields' => [
        ['field_key' => 'location', 'label' => 'Location (District > Block > Panchayat > Village)', 'type' => 'location_cascade', 'mandatory' => 1,
            'help_text' => 'For urban areas pick the nearest unit (ULB/Ward mapping pending).',
            'settings' => ['levels' => ['district', 'block', 'panchayat', 'village']]],
        ['field_key' => 'address', 'label' => 'Address', 'type' => 'textarea', 'mandatory' => 1],
        ['field_key' => 'landmark', 'label' => 'Landmark', 'type' => 'textbox'],
    ]],

    // ---------- Section 4: Building Details ----------
    ['title' => 'Section 4: Building Details', 'fields' => [
        ['field_key' => 'approach_roads', 'label' => 'Approach Road Details', 'type' => 'multi_select',
            'help_text' => 'Select every road type that reaches the building.',
            'options' => [
                ['label' => 'NH (National Highway)', 'value' => 'nh'],
                ['label' => 'SH (State Highway)', 'value' => 'sh'],
                ['label' => 'ODR (Other District Road)', 'value' => 'odr'],
                ['label' => 'Rural Road', 'value' => 'rural'],
                ['label' => 'PMGSY', 'value' => 'pmgsy'],
                ['label' => 'Other', 'value' => 'other'],
            ]],
        ['field_key' => 'construction_year', 'label' => 'Construction Year', 'type' => 'number',
            'help_text' => 'Year of construction (e.g. 1998). Building age is derived from this year.'],
        ['field_key' => 'building_age', 'label' => 'Building Age (Years)', 'type' => 'number',
            'help_text' => 'Auto-calculated from Construction Year.',
            'settings' => ['calc' => ['watch' => 'construction_year', 'expr' => 'current_year - {construction_year}']]],
        ['field_key' => 'construction_cost', 'label' => 'Construction Cost (INR)', 'type' => 'decimal'],
        ['field_key' => 'no_floors', 'label' => 'Number of Floors', 'type' => 'number'],
        ['field_key' => 'no_rooms', 'label' => 'Number of Rooms', 'type' => 'number'],
        ['field_key' => 'toilets_male', 'label' => 'Number of Toilets - Male', 'type' => 'number'],
        ['field_key' => 'toilets_female', 'label' => 'Number of Toilets - Female', 'type' => 'number'],
        ['field_key' => 'physical_status', 'label' => 'Physical Status', 'type' => 'master', 'mandatory' => 1,
            'settings' => ['master_group_id' => $physicalGroup]],
        ['field_key' => 'occupancy_status', 'label' => 'Occupancy Status', 'type' => 'master', 'mandatory' => 1,
            'settings' => ['master_group_id' => $occupancyGroup]],
    ]],

    // ---------- Section 5: Evidence & Remarks ----------
    ['title' => 'Section 5: Evidence & Remarks', 'fields' => [
        ['field_key' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea'],
        ['field_key' => 'geo_location', 'label' => 'Lat/Long', 'type' => 'gps', 'mandatory' => 1],
        ['field_key' => 'photo_front', 'label' => 'Photo - Front', 'type' => 'camera'],
        ['field_key' => 'photo_campus', 'label' => 'Photo - Campus', 'type' => 'camera'],
        ['field_key' => 'photo_back', 'label' => 'Photo - Backside', 'type' => 'camera'],
    ]],
]);

$svc->publish($formId, 1, 'Office Building Survey v1');
echo "Survey form OFFICE_BUILDING_SURVEY created & published (id={$formId})." . PHP_EOL;

// Grant access to the new form for admin + demo users (state admin is implicit).
$pdo->prepare('INSERT IGNORE INTO user_form_access (user_id, form_id, granted_by) VALUES (:u, :f, 1)')
    ->execute(['u' => 1, 'f' => $formId]);
foreach ($pdo->query("SELECT id FROM users WHERE username IN ('dh_surveyor','rk_surveyor','jb_block','sk_district')")->fetchAll() as $u) {
    $pdo->prepare('INSERT IGNORE INTO user_form_access (user_id, form_id, granted_by) VALUES (:u, :f, 1)')
        ->execute(['u' => $u['id'], 'f' => $formId]);
}
echo "Form access granted to demo users." . PHP_EOL;
