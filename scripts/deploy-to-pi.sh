#!/bin/bash
# 在樹莓派 5 (Linux) 上重新佈署：建置前端、複製到 backend/public、清除 Laravel 快取
# 使用方式（在專案根目錄執行）：
#   chmod +x scripts/deploy-to-pi.sh
#   ./scripts/deploy-to-pi.sh
#
# 若前端建置在本地 Windows 完成，可改為在 Pi 上只做「解壓 + 清快取」見下方說明。

set -e
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
echo ""
echo "=========================================="
echo "  AllTrue 佈署到樹莓派 (Linux)"
echo "  工作目錄: $ROOT"
echo "=========================================="
echo ""

# 1) 前端建置（需 Node.js）
echo "[1/4] 前端建置..."
if ! command -v node &>/dev/null; then
  echo "  [略過] 未安裝 Node.js，跳過前端建置。若已在本機建置，請將 frontend/dist 或 deploy.tar.gz 複製到 Pi 後執行解壓步驟。"
else
  if [ ! -d "frontend/node_modules" ]; then
    echo "  安裝 frontend 依賴..."
    (cd frontend && npm install)
  fi
  (cd frontend && npm run build) || { echo "[ERROR] 前端 build 失敗"; exit 1; }
  echo "  OK"
fi

# 2) 複製前端到 backend/public（Vite 可能輸出到 dist 或 dist_build）
echo "[2/4] 複製前端到 backend/public..."
FRONTEND_OUT="frontend/dist_build"
[ -d "$FRONTEND_OUT" ] || FRONTEND_OUT="frontend/dist"
if [ -d "$FRONTEND_OUT" ]; then
  mkdir -p backend/public/assets
  cp -f "$FRONTEND_OUT/index.html" backend/public/
  cp -rf "$FRONTEND_OUT/assets"/* backend/public/assets/
  echo "  OK (已複製 index.html 與 assets，來源: $FRONTEND_OUT)"
else
  echo "  [略過] 找不到 frontend/dist 或 frontend/dist_build，請先執行 npm run build"
fi

# 3) Laravel 快取清除
echo "[3/4] 清除 Laravel 快取..."
if command -v php &>/dev/null && [ -f "backend/artisan" ]; then
  (cd backend && php artisan optimize:clear)
  echo "  OK"
else
  echo "  [略過] 未找到 php 或 backend/artisan"
fi

# 4) 權限（可選，依實際執行身份調整）
echo "[4/4] 檢查權限..."
if [ -d "backend/public/assets" ]; then
  chmod -R a+r backend/public/assets 2>/dev/null || true
  chmod a+r backend/public/index.html 2>/dev/null || true
  echo "  OK"
fi

echo ""
echo "=========================================="
echo "  佈署完成。請重新整理瀏覽器（必要時強制重新整理 Ctrl+Shift+R）。"
echo "=========================================="
echo ""
