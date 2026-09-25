<?php
$content = file_get_contents('D:/Xampp/htdocs/bcd-app/common/src/Services/RoleDashboardService.php');
$pos = strpos($content, 'private function resolveChartLevel');
echo "Position: $pos\n";
$before = substr($content, $pos - 50, 100);
echo "Before:\n";
echo bin2hex($before) . "\n";
echo "Decoded:\n";
echo $before . "\n";