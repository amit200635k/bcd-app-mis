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

    $districtStmt = $pdo->prepare('INSERT INTO districts (state_id, code, name, short_name) VALUES (:s, :c, :n, :sn)');
    $blockStmt = $pdo->prepare('INSERT INTO blocks (district_id, code, name) VALUES (:d, :c, :n)');
    $panchayatStmt = $pdo->prepare('INSERT INTO panchayats (block_id, code, name) VALUES (:b, :c, :n)');
    $villageStmt = $pdo->prepare('INSERT INTO villages (panchayat_id, code, name) VALUES (:p, :c, :n)');

    $counts = ['district' => 0, 'block' => 0, 'panchayat' => 0, 'village' => 0];

    foreach ($districts as $code => $name) {
        $districtStmt->execute(['s' => $stateId, 'c' => 'JH-' . $code, 'n' => $name, 'sn' => $name]);
        $districtId = (int) $pdo->lastInsertId();
        $counts['district']++;

        foreach ($blocksByDistrict[$code] as $bi => $blockName) {
            $blockStmt->execute(['d' => $districtId, 'c' => 'JH-' . $code . '-B' . ($bi + 1), 'n' => $blockName]);
            $blockId = (int) $pdo->lastInsertId();
            $counts['block']++;

            // 3 panchayats per block
            for ($pi = 1; $pi <= 3; $pi++) {
                $panchayatName = $blockName . ' ' . $panchayatNames[($pi - 1) % count($panchayatNames)];
                $panchayatStmt->execute(['b' => $blockId, 'c' => 'JH-' . $code . '-P' . ($bi + 1) . '-' . $pi, 'n' => $panchayatName]);
                $panchayatId = (int) $pdo->lastInsertId();
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
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seeding failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
