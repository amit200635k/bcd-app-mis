<?php
require 'D:/Xampp/htdocs/bcd-app/common/bootstrap.php';
use App\Models\User;
use App\Services\RoleDashboardService;
use App\Database\Connection;

$pdo = Connection::instance();

// Test district user (drill-down from state admin)
$stmt = $pdo->query("
    SELECT u.* FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'district' AND u.status = 'active' AND u.deleted_at IS NULL
    LIMIT 1
");
$userRow = $stmt->fetch();
$user = User::fromRow($userRow);
echo "User: " . $user->fullName() . "\n";
echo "Role: " . json_encode($user->roleCodes()) . PHP_EOL;
echo "Scope: " . json_encode($user->scope()) . PHP_EOL;

$svc = new \App\Services\RoleDashboardService();

// Test district user with parent district_id (drill-down from state admin)
$chartData = $svc->getChartData($user, 'district', 20, null); // district_id = 20 (Ranchi)
echo "\n--- District User with parent district_id=20 (Ranchi) ---\n";
echo "Chart Level: " . $chartData['level'] . PHP_EOL;
echo "Labels: " . json_encode($chartData['labels']) . PHP_EOL;
echo "Data: " . json_encode($chartData['data']) . PHP_EOL;
echo "Items count: " . count($chartData['items']) . "\n";

// Test state admin drilling into a district
$stmt = $pdo->query("
    SELECT u.* FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'state_admin' AND u.status = 'active' AND u.deleted_at IS NULL
    LIMIT 1
");
$userRow = $stmt->fetch();
$user = User::fromRow($userRow);

// Test state admin drilling into district 20 (Ranchi)
$chartData = $svc->getChartData($user, 'district', 20, null);
echo "\n--- State Admin drilling into district_id=20 (Ranchi) ---\n";
echo "Chart Level: " . $chartData['level'] . PHP_EOL;
echo "Labels: " . json_encode($chartData['labels']) . PHP_EOL;
echo "Data: " . json_encode($chartData['data']) . PHP_EOL;
echo "Items count: " . count($chartData['items']) . "\n";