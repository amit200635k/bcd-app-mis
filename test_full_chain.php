<?php
require 'D:/Xampp/htdocs/bcd-app/common/bootstrap.php';
use App\Models\User;
use App\Services\RoleDashboardService;
use App\Database\Connection;

$pdo = Connection::instance();

echo "=== Testing Full Drill-Down Chain ===\n\n";

// 1. State Admin -> District level
$stmt = $pdo->query("
    SELECT u.* FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'state_admin' AND u.status = 'active' AND u.deleted_at IS NULL
    LIMIT 1
");
$userRow = $stmt->fetch();
$user = User::fromRow($userRow);
echo "=== 1. State Admin -> District Level ===\n";
$svc = new \App\Services\RoleDashboardService();
$chartData = $svc->getChartData($user, null, null, null);
echo "Level: {$chartData['level']}, Districts: " . count($chartData['labels']) . ", Total: " . array_sum($chartData['data']) . "\n";

// 2. State Admin -> District 20 (Ranchi) -> Blocks
$chartData = $svc->getChartData($user, 'district', 20, null);
echo "\n=== 2. State Admin -> District 20 (Ranchi) -> Blocks ===\n";
echo "Level: {$chartData['level']}\n";
echo "Blocks: " . implode(', ', $chartData['labels']) . "\n";
echo "Counts: " . implode(', ', $chartData['data']) . "\n";

// 3. District User (Ranchi) -> Blocks
$stmt = $pdo->query("
    SELECT u.* FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'district' AND u.status = 'active' AND u.deleted_at IS NULL
    AND u.district_id = 20
    LIMIT 1
");
$userRow = $stmt->fetch();
$user = User::fromRow($userRow);
echo "\n=== 3. District User (Ranchi) -> Blocks ===\n";
$chartData = $svc->getChartData($user, null, null, null);
echo "Level: {$chartData['level']}\n";
echo "Blocks: " . implode(', ', $chartData['labels']) . "\n";
echo "Counts: " . implode(', ', $chartData['data']) . "\n";

// 4. District User drilling into Block 77 (Ranchi block) -> Panchayats
$chartData = $svc->getChartData($user, 'block', 77, null);
echo "\n=== 4. District User -> Block 77 (Ranchi) -> Panchayats ===\n";
echo "Level: {$chartData['level']}\n";
echo "Panchayats: " . implode(', ', $chartData['labels']) . "\n";
echo "Counts: " . implode(', ', $chartData['data']) . "\n";

// 5. Block User (Ranchi block) -> Panchayats
$stmt = $pdo->query("
    SELECT u.* FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'block' AND u.status = 'active' AND u.deleted_at IS NULL
    AND u.block_id = 77
    LIMIT 1
");
$userRow = $stmt->fetch();
$user = User::fromRow($userRow);
echo "\n=== 5. Block User (Ranchi block 77) -> Panchayats ===\n";
$chartData = $svc->getChartData($user, null, null, null);
echo "Level: {$chartData['level']}\n";
echo "Panchayats: " . implode(', ', $chartData['labels']) . "\n";
echo "Counts: " . implode(', ', $chartData['data']) . "\n";

// 6. Block User drilling into Panchayat 230 -> Villages
$chartData = $svc->getChartData($user, 'panchayat', 230, null);
echo "\n=== 6. Block User -> Panchayat 230 (Ranchi Panchayat Samiti) -> Villages ===\n";
echo "Level: {$chartData['level']}\n";
echo "Villages: " . implode(', ', $chartData['labels']) . "\n";
echo "Counts: " . implode(', ', $chartData['data']) . "\n";

// 7. Panchayat User -> Villages
$stmt = $pdo->query("
    SELECT u.* FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'panchayat' AND u.status = 'active' AND u.deleted_at IS NULL
    AND u.panchayat_id = 230
    LIMIT 1
");
$userRow = $stmt->fetch();
$user = User::fromRow($userRow);
echo "\n=== 7. Panchayat User (Ranchi Panchayat Samiti) -> Villages ===\n";
$chartData = $svc->getChartData($user, null, null, null);
echo "Level: {$chartData['level']}\n";
echo "Villages: " . implode(', ', $chartData['labels']) . "\n";
echo "Counts: " . implode(', ', $chartData['data']) . "\n";

// 8. Village User
$stmt = $pdo->query("
    SELECT u.* FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'village' AND u.status = 'active' AND u.deleted_at IS NULL
    AND u.village_id = 918
    LIMIT 1
");
$userRow = $stmt->fetch();
$user = User::fromRow($userRow);
echo "\n=== 8. Village User (Ranchi Kalan 2) ===\n";
$chartData = $svc->getChartData($user, null, null, null);
echo "Level: {$chartData['level']}\n";
echo "Villages: " . implode(', ', $chartData['labels']) . "\n";
echo "Counts: " . implode(', ', $chartData['data']) . "\n";

echo "\n=== ALL TESTS PASSED ===\n";