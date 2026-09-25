<?php
$content = file_get_contents('D:/Xampp/htdocs/bcd-app/common/src/Services/RoleDashboardService.php');
$len = strlen($content);
echo "File length: $len\n";

// Find the position of "private function resolveChartLevel"
$pos = strpos($content, 'private function resolveChartLevel');
if ($pos !== false) {
    echo "Found at position: $pos\n";
    // Show 100 chars before and after
    $start = max(0, $pos - 50);
    $context = substr($content, $start, 200);
    echo "Context:\n";
    echo bin2hex($context) . "\n";
    echo "\nDecoded:\n";
    echo $context . "\n";
} else {
    echo "Not found\n";
}