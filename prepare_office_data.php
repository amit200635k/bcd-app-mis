<?php
require 'D:/Xampp/htdocs/bcd-app/common/bootstrap.php';
use App\Database\Connection;

$pdo = Connection::instance();

// Get all active districts with their blocks, panchayats, villages
$districts = $pdo->query("SELECT id, name FROM districts WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$output = "OFFICE_BUILDING_SURVEY - Dummy Data for 24 Districts\n";
$output .= "====================================================\n\n";

$totalRecords = 0;
$recordCounter = 0;

foreach ($districts as $district) {
    $districtId = $district['id'];
    $districtName = $district['name'];
    
    // Get blocks for this district
    $stmt = $pdo->prepare("SELECT id, name FROM blocks WHERE district_id = :did AND is_active = 1 ORDER BY name");
    $stmt->execute(['did' => $districtId]);
    $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($blocks)) {
        $output .= "District: $districtName - NO ACTIVE BLOCKS, skipping\n\n";
        continue;
    }
    
    // 4-10 records per district
    $districtRecordCount = rand(4, 10);
    $output .= "District: $districtName (ID: $districtId) - $districtRecordCount records\n";
    $output .= str_repeat("-", 80) . "\n";
    
    for ($d = 1; $d <= $districtRecordCount; $d++) {
        $recordCounter++;
        $totalRecords++;
        
        // Pick random block
        $block = $blocks[array_rand($blocks)];
        $blockId = $block['id'];
        $blockName = $block['name'];
        
        // Get panchayats for this block
        $stmt = $pdo->prepare("SELECT id, name FROM panchayats WHERE block_id = :bid AND is_active = 1 ORDER BY name");
        $stmt->execute(['bid' => $blockId]);
        $panchayats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $panchayat = $panchayats ? $panchayats[array_rand($panchayats)] : null;
        $panchayatId = $panchayat ? (int)$panchayat['id'] : 0;
        $panchayatName = $panchayat ? $panchayat['name'] : 'N/A';
        
        // Get villages for this panchayat
        $villageId = 0;
        $villageName = 'N/A';
        if ($panchayatId) {
            $stmt = $pdo->prepare("SELECT id, name FROM villages WHERE panchayat_id = :pid AND is_active = 1 ORDER BY name");
            $stmt->execute(['pid' => $panchayatId]);
            $villages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $village = $villages ? $villages[array_rand($villages)] : null;
            $villageId = $village ? (int)$village['id'] : 0;
            $villageName = $village ? $village['name'] : 'N/A';
        }
        
        $output .= "  Record " . $d . ":\n";
        $output .= "    District:  $districtName (ID: $districtId)\n";
        $output .= "    Block:     $blockName (ID: $blockId)\n";
        $output .= "    Panchayat: $panchayatName (ID: $panchayatId)\n";
        $output .= "    Village:   $villageName (ID: $villageId)\n";
        $output .= "    Location JSON: " . json_encode([
            'district_id' => $districtId,
            'district_name' => $districtName,
            'block_id' => $blockId,
            'block_name' => $blockName,
            'panchayat_id' => $panchayatId,
            'panchayat_name' => $panchayatName,
            'village_id' => $villageId,
            'village_name' => $villageName,
        ]) . "\n\n";
    }
    
    $output .= "\n";
}

$output .= "\n====================================================\n";
$output .= "Total Records: $totalRecords across 24 districts\n";

file_put_contents('D:/Xampp/htdocs/bcd-app/office_dummy_data.txt', $output);
echo "Data file created: office_dummy_data.txt\n";
echo "Total records: $totalRecords\n";