# 打包前端並複製到 backend/public，讓 localhost:8080 顯示最新畫面
# 在專案根目錄執行: .\scripts\update-frontend.ps1

$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $ProjectRoot

Write-Host "Building frontend..." -ForegroundColor Cyan
Set-Location frontend
npm run build
if ($LASTEXITCODE -ne 0) {
    Write-Host "Build failed." -ForegroundColor Red
    Set-Location ..
    exit 1
}
Set-Location ..

Write-Host "Copying to backend/public..." -ForegroundColor Cyan
Copy-Item -Path frontend\dist\index.html -Destination backend\public\index.html -Force
if (Test-Path backend\public\assets) {
    Remove-Item -Path backend\public\assets\* -Force
}
Copy-Item -Path frontend\dist\assets\* -Destination backend\public\assets\ -Force

Write-Host "Done. Refresh http://localhost:8080 (Ctrl+F5) to see changes." -ForegroundColor Green
