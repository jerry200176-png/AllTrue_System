#!/bin/bash
# 當 deploy 因 EPERM 失敗時使用（需 sudo）
cd "$(dirname "$0")/.."
npm run build || exit 1
sudo mkdir -p ../backend/public/assets
sudo cp -f dist/index.html ../backend/public/
sudo cp -rf dist/assets/. ../backend/public/assets/
echo "Done. Refresh the app in the browser."
