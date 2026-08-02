<?php

declare(strict_types=1);

/** JSON feed of location dropdowns for the MIS UI (session-authenticated). */

require dirname(__DIR__) . '/common/bootstrap.php';

use App\Auth\SessionAuth;
use App\Database\Connection;

SessionAuth::requireAuth();

$type = (string) ($_GET['type'] ?? '');
$pdo = Connection::instance();
header('Content-Type: application/json; charset=utf-8');

switch ($type) {
    case 'district':
        $items = $pdo->query('SELECT id, name FROM districts WHERE is_active = 1 ORDER BY name')->fetchAll();
        break;
    case 'block':
        $districtId = (int) ($_GET['district_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, name FROM blocks WHERE district_id = :d AND is_active = 1 ORDER BY name');
        $stmt->execute(['d' => $districtId]);
        $items = $stmt->fetchAll();
        break;
    case 'panchayat':
        $blockId = (int) ($_GET['block_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, name FROM panchayats WHERE block_id = :b AND is_active = 1 ORDER BY name');
        $stmt->execute(['b' => $blockId]);
        $items = $stmt->fetchAll();
        break;
    case 'village':
        $panchayatId = (int) ($_GET['panchayat_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, name FROM villages WHERE panchayat_id = :p AND is_active = 1 ORDER BY name');
        $stmt->execute(['p' => $panchayatId]);
        $items = $stmt->fetchAll();
        break;
    case 'scope':
        $scope = SessionAuth::user()->scope();
        $items = [
            'district_id'  => $scope['district_id']  !== null ? (int) $scope['district_id'] : null,
            'block_id'     => $scope['block_id']     !== null ? (int) $scope['block_id'] : null,
            'panchayat_id' => $scope['panchayat_id'] !== null ? (int) $scope['panchayat_id'] : null,
            'village_id'   => $scope['village_id']   !== null ? (int) $scope['village_id'] : null,
        ];
        break;
    default:
        $items = [];
}

echo json_encode(['items' => $items]);
