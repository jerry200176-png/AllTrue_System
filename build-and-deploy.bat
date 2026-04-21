@echo off
chcp 65001 >nul
echo Building frontend...
cd /d "%~dp0frontend"
call npm run build
if errorlevel 1 (
  echo Build failed.
  pause
  exit /b 1
)
echo Deploying to backend...
call npm run deploy
if errorlevel 1 (
  echo Deploy failed.
  pause
  exit /b 1
)
echo Done. Refresh the browser.
pause
exit /b 0
