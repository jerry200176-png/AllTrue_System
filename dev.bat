@echo off
chcp 65001 >nul 2>nul
title AllTrue Dev

:: 切到腳本自身所在的資料夾
cd /d "%~dp0"

echo.
echo ========================================
echo   AllTrue - Dev Mode
echo ========================================
echo.
echo   Backend API:  http://localhost:3001
echo   Frontend:     http://localhost:5173
echo.

:: Install deps if needed
if not exist "backend-local\node_modules" (
    echo Installing backend packages...
    cd /d "%~dp0backend-local"
    call npm install
    cd /d "%~dp0"
)
if not exist "frontend\node_modules" (
    echo Installing frontend packages...
    cd /d "%~dp0frontend"
    call npm install
    cd /d "%~dp0"
)

:: Start backend in a new window
start "AllTrue Backend" cmd /k "cd /d "%~dp0backend-local" && node server.js"

:: Wait for backend
timeout /t 2 /nobreak >nul

:: Start frontend
cd /d "%~dp0frontend"
call npm run dev

echo.
echo Frontend stopped.
pause
