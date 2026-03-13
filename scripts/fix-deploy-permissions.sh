#!/bin/bash
# 一次性修正：讓當前使用者擁有 backend/public 寫入權限
cd "$(dirname "$0")"
echo "修正 backend/public 權限..."
sudo chown -R "$(whoami)" backend/public
echo "完成。之後可直接執行: cd frontend && npm run deploy"
