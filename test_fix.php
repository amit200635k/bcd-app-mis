<?php
require 'D:/Xampp/htdocs/bcd-app/common/bootstrap.php';
use App\Models\User;
use App\Services\RoleDashboardService;
use App\Database\Connection;

$pdo = Connection::instance();
$stmt = $pdo->query("
    SELECT u.* FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'state_admin' AND u.status = 'active' AND u.deleted_at IS NULL
    LIMIT 1
");
$userRow = $stmt->fetch();
$user = User::fromRow($userRow);
$svc = new \App\Services\RoleDashboardService();
$chartData = $svc->getChartData($user, null, null, null);
echo 'Chart Level: ' . $chartData['level'] . PHP_EOL;
echo 'Total Districts: ' . count($chartData['labels']) . PHP_EOL;
echo 'Total Records: ' . array_sum($chartData['data']) . PHP_EOL;
echo 'All 24 Districts: ' . (count($chartData['labels']) === 24 ? 'YES' : 'NO') . PHP_EOL;