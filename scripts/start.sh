#!/bin/bash
cd "$(dirname "$0")"

echo ""
echo "========================================"
echo "  AllTrue - Starting (Apache/PHP/MySQL)"
echo "========================================"
echo ""
echo "  Working directory: $(pwd)"
echo ""

# Check PHP
if ! command -v php &>/dev/null; then
    echo "[ERROR] PHP not found!"
    echo "Please install PHP 8.1+ and extensions: mbstring, pdo_mysql, tokenizer, xml, ctype, json"
    echo ""
    exit 1
fi

# Check Node.js (only for frontend build)
if ! command -v node &>/dev/null; then
    echo "[ERROR] Node.js not found (required for frontend build)!"
    echo "Please install from: https://nodejs.org/"
    echo ""
    exit 1
fi

echo "[1/3] Checking frontend dependencies..."
if [ ! -d "frontend/node_modules" ]; then
    echo "      Installing frontend packages..."
    (cd frontend && npm install) || { echo "[ERROR] Frontend npm install failed!"; exit 1; }
else
    echo "      OK"
fi

echo "[2/3] Building frontend..."
if ! (cd frontend && npm run build); then
    echo "      Retrying with clean install..."
    (cd frontend && rm -rf node_modules package-lock.json && npm install && npm run build) || { echo "[ERROR] Frontend build failed!"; exit 1; }
fi

echo "[3/3] Deploying frontend to backend/public..."
(cd frontend && npm run deploy) || { echo "[ERROR] Deploy failed!"; exit 1; }

echo ""
echo "[4/4] Starting PHP server..."
echo "  URL: http://localhost:8000"
echo "  (生產環境請使用 Apache，見 apache/alltrue.conf)"
echo ""
cd backend && php artisan serve --port=8000

echo ""
echo "Server stopped."
