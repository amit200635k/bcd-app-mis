<?php
$content = file_get_contents('D:/Xampp/htdocs/bcd-app/common/src/Services/RoleDashboardService.php');
$pos = strpos($content, 'private function resolveChartLevel');
$before = substr($content, $pos - 100, 200);
echo "Before (hex):\n";
echo bin2hex($before) . "\n";
echo "\nDecoded:\n";
echo $before . "\n";