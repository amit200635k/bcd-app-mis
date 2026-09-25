$r = Invoke-WebRequest -Uri 'http://localhost:81/bcd-app/admin/replication.php' -Method GET
$r.Content | Out-File -Encoding utf8 'D:\Xampp\htdocs\bcd-app\page_output.html'
Write-Host "Done. Length: $($r.Content.Length)"