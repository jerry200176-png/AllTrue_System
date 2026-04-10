#!/bin/bash
# safe-deploy.sh — Deploy frontend build to production WITHOUT deleting PHP files
# Usage: bash /home/admin/safe-deploy.sh

set -e

FRONTEND_DIR="/home/admin/frontend"
PUBLIC_DIR="/home/admin/backend/public"

echo "[1/3] Building frontend..."
cd "$FRONTEND_DIR"
npm run build

echo "[2/3] Syncing dist_build/ to public/ (preserving PHP files)..."
rsync -a \
  --exclude='index.php' \
  --exclude='.htaccess' \
  --exclude='branches.json' \
  --exclude='*.php' \
  "$FRONTEND_DIR/dist_build/" "$PUBLIC_DIR/"

echo "[3/3] Running Laravel migrations..."
cd /home/admin/backend
php artisan migrate --force

echo ""
echo "Deploy complete."
echo "Public dir contents:"
ls "$PUBLIC_DIR"
echo "Assets:"
ls "$PUBLIC_DIR/assets/"
