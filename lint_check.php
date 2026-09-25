<?php
exec('D:/Xampp/php/php.exe -l D:/Xampp/htdocs/bcd-app/common/src/Services/RoleDashboardService.php 2>&1', $output, $ret);
echo "Return code: $ret\n";
echo "Output:\n";
print_r($output);