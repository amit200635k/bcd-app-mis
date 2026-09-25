Add-Type -AssemblyName System.Drawing

Get-ChildItem "D:\Xampp\htdocs\bcd-app\mobile2\android\app\src\main\res" -File -Recurse |
Where-Object {
    $_.Extension -match '\.(png|jpg|jpeg|gif|bmp|webp|tif|tiff)$'
} |
 
ForEach-Object {
    try {
        $img = [System.Drawing.Image]::FromFile($_.FullName)

        $relative = $_.FullName.Replace((Resolve-Path ".\mobile2\android\app\src\main\res").Path, "").TrimStart('\')

        "File       : $($_.Name)"
        "Resource   : $relative"
        "Dimensions : $($img.Width) x $($img.Height)"
        "Size       : $([math]::Round($_.Length / 1KB, 2)) KB"
        "----------------------------------------"

        $img.Dispose()
    }
    catch {
        "File       : $($_.Name)"
        "Resource   : $relative"
        "Dimensions : ERROR"
        "----------------------------------------"
    }
} | Out-File ".\android-res-images.txt" -Encoding utf8