<?php

declare(strict_types=1);

/**
 * Populate Jharkhand administrative hierarchy (state, districts, blocks,
 * panchayats, villages) for demo/live-testing.
 *
 * Usage: php database/seed_jharkhand.php
 */

require __DIR__ . '/../common/bootstrap.php';

use App\Database\Connection;

$pdo = Connection::instance();

// Jharkhand's 24 districts (code => name).
$districts = [
    'BO' => 'Bokaro',
    'CH' => 'Chatra',
    'DE' => 'Deoghar',
    'DH' => 'Dhanbad',
    'DU' => 'Dumka',
    'ES' => 'East Singhbhum',
    'GA' => 'Garhwa',
    'GI' => 'Giridih',
    'GO' => 'Godda',
    'GU' => 'Gumla',
    'HA' => 'Hazaribagh',
    'JA' => 'Jamtara',
    'KH' => 'Khunti',
    'KO' => 'Koderma',
    'LA' => 'Latehar',
    'LO' => 'Lohardaga',
    'PA' => 'Pakur',
    'PL' => 'Palamu',
    'RA' => 'Ramgarh',
    'RK' => 'Ranchi',
    'SA' => 'Sahibganj',
    'SK' => 'Saraikela-Kharsawan',
    'SI' => 'Simdega',
    'WS' => 'West Singhbhum',
];

// Sample blocks per district (subset for demo).
$blocksByDistrict = [
    'BO' => ['Chas', 'Bermo', 'Jaridih', 'Gomia'],
    'CH' => ['Chatra', 'Simaria', 'Tandwa', 'Pathalgada'],
    'DE' => ['Deoghar', 'Mohanpur', 'Madhupur', 'Satsang'],
    'DH' => ['Baghmara', 'Dhanbad', 'Topchanchi', 'Tundi'],
    'DU' => ['Dumka', 'Jarmundi', 'Sikaripara', 'Masalia'],
    'ES' => ['Dhalbhumgarh', 'Ghatshila', 'Potka', 'Musabani'],
    'GA' => ['Garhwa', 'Ranka', 'Nagar Untari', 'Bhandaria'],
    'GI' => ['Giridih', 'Bagodar', 'Dhanwar', 'Pirtand'],
    'GO' => ['Godda', 'Pathargama', 'Poriyahat', 'Sunder Pahari'],
    'GU' => ['Gumla', 'Basia', 'Sisai', 'Ghaghra'],
    'HA' => ['Hazaribagh', 'Barhi', 'Ichak', 'Katkamsandi'],
    'JA' => ['Jamtara', 'Nala', 'Kundahit', 'Fatehpur'],
    'KH' => ['Khunti', 'Karra', 'Torpa', 'Rania'],
    'KO' => ['Koderma', 'Domchanch', 'Jainagar', 'Markacho'],
    'LA' => ['Latehar', 'Chandwa', 'Bariyatu', 'Manika'],
    'LO' => ['Lohardaga', 'Kisko', 'Kuru', 'Bhandra'],
    'PA' => ['Pakur', 'Maheshpur', 'Barhait', 'Littipara'],
    'PL' => ['Daltonganj', 'Panki', 'Lesliganj', 'Satbarwa'],
    'RA' => ['Ramgarh', 'Patratu', 'Mandu', 'Gola'],
    'RK' => ['Ranchi', 'Kanke', 'Bundu', 'Namkum'],
    'SA' => ['Sahibganj', 'Rajmahal', 'Barharwa', 'Borio'],
    'SK' => ['Seraikela', 'Chandil', 'Ichagarh', 'Kuchai'],
    'SI' => ['Simdega', 'Bano', 'Kolebira', 'Thethaitangar'],
    'WS' => ['Chaibasa', 'Chakradharpur', 'Jhinkpani', 'Tonto'],
];

// Village name suffixes for generating panchayat/village names.
$panchayatNames = ['Gram Panchayat', 'Panchayat Samiti', 'Adarsh Gram', 'Vikas Panchayat'];
$villageSuffixes = ['Tola', 'Khurd', 'Kalan', 'Basti', 'Nagar', 'Pahari', 'Khera', 'Pur'];

$pdo->beginTransaction();
try {
    // State
    $stmt = $pdo->prepare('INSERT INTO states (code, name) VALUES (:c, :n) ON DUPLICATE KEY UPDATE name = VALUES(name)');
    $stmt->execute(['c' => 'JH', 'n' => 'Jharkhand']);
    $stateId = (int) $pdo->query("SELECT id FROM states WHERE code = 'JH'")->fetchColumn();

    $districtStmt = $pdo->prepare('INSERT INTO districts (state_id, code, name, short_name) VALUES (:s, :c, :n, :sn) ON DUPLICATE KEY UPDATE name = VALUES(name), short_name = VALUES(short_name), state_id = VALUES(state_id)');
    $blockStmt = $pdo->prepare('INSERT INTO blocks (district_id, code, name) VALUES (:d, :c, :n) ON DUPLICATE KEY UPDATE name = VALUES(name), district_id = VALUES(district_id)');
    $panchayatStmt = $pdo->prepare('INSERT INTO panchayats (block_id, code, name) VALUES (:b, :c, :n) ON DUPLICATE KEY UPDATE name = VALUES(name), block_id = VALUES(block_id)');
    $villageStmt = $pdo->prepare('INSERT INTO villages (panchayat_id, code, name) VALUES (:p, :c, :n) ON DUPLICATE KEY UPDATE name = VALUES(name), panchayat_id = VALUES(panchayat_id)');

    $counts = ['district' => 0, 'block' => 0, 'panchayat' => 0, 'village' => 0];

    foreach ($districts as $code => $name) {
        $districtStmt->execute(['s' => $stateId, 'c' => 'JH-' . $code, 'n' => $name, 'sn' => $name]);
        $districtId = (int) $pdo->query("SELECT id FROM districts WHERE code = 'JH-" . $code . "'")->fetchColumn();
        $counts['district']++;

        foreach ($blocksByDistrict[$code] as $bi => $blockName) {
            $blockStmt->execute(['d' => $districtId, 'c' => 'JH-' . $code . '-B' . ($bi + 1), 'n' => $blockName]);
            $blockId = (int) $pdo->query("SELECT id FROM blocks WHERE code = 'JH-" . $code . "-B" . ($bi + 1) . "'")->fetchColumn();
            $counts['block']++;

            // 3 panchayats per block
            for ($pi = 1; $pi <= 3; $pi++) {
                $panchayatName = $blockName . ' ' . $panchayatNames[($pi - 1) % count($panchayatNames)];
                $panchayatStmt->execute(['b' => $blockId, 'c' => 'JH-' . $code . '-P' . ($bi + 1) . '-' . $pi, 'n' => $panchayatName]);
                $panchayatId = (int) $pdo->query("SELECT id FROM panchayats WHERE code = 'JH-" . $code . "-P" . ($bi + 1) . "-" . $pi . "'")->fetchColumn();
                $counts['panchayat']++;

                // 4 villages per panchayat
                for ($vi = 1; $vi <= 4; $vi++) {
                    $villageName = $blockName . ' ' . $villageSuffixes[($pi + $vi - 2) % count($villageSuffixes)] . ' ' . $vi;
                    $villageStmt->execute(['p' => $panchayatId, 'c' => 'JH-' . $code . '-V' . ($bi + 1) . '-' . $pi . '-' . $vi, 'n' => $villageName]);
                    $counts['village']++;
                }
            }
        }
    }

    $pdo->commit();
    echo "Jharkhand hierarchy seeded:" . PHP_EOL;
    foreach ($counts as $k => $v) {
        printf("  %-10s %d" . PHP_EOL, $k, $v);
    }

    syncDistrictMasterItems($pdo);
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seeding failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * Keep the DISTRICT master group in sync with the districts table so the
 * survey builder can link a "master" field to it.
 */
function syncDistrictMasterItems(PDO $pdo): void
{
    $pdo->exec(
        'INSERT INTO master_groups (code, name, is_system)
         VALUES ("DISTRICT", "District", 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name)'
    );
    $groupId = (int) $pdo->query("SELECT id FROM master_groups WHERE code = 'DISTRICT'")->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO master_items (group_id, code, name) VALUES (:g, :c, :n)
         ON DUPLICATE KEY UPDATE name = VALUES(name)'
    );
    $districts = $pdo->query('SELECT code, name FROM districts ORDER BY name')->fetchAll();
    $count = 0;
    foreach ($districts as $d) {
        $stmt->execute(['g' => $groupId, 'c' => $d['code'], 'n' => $d['name']]);
        $count++;
    }
    printf("  %-10s %d" . PHP_EOL, 'master_items', $count);
}
