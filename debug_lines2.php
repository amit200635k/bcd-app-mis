<?php
$content = file_get_contents('D:/Xampp/htdocs/bcd-app/common/src/Services/RoleDashboardService.php');
$lines = explode("\n", $content);
echo "Line 310: " . bin2hex($lines[310]) . "\n";
echo "Line 311: " . bin2hex($lines[311]) . "\n";
echo "Line 312: " . bin2hex($lines[312]) . "\n";
echo "Line 313: " . bin2hex($lines[313]) . "\n";
echo "Line 314: " . bin2hex($lines[314]) . "\n";