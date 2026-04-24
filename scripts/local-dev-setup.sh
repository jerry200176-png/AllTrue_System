#!/usr/bin/env bash
# =============================================================================
# AllTrue — Local Development Setup (WSL2 / Ubuntu)
# =============================================================================
# 用法：在 WSL2 Ubuntu 內執行
#   bash scripts/local-dev-setup.sh
#
# 這個腳本會安裝並設定：
#   PHP 8.2 + 所需 extension
#   MariaDB（本地開發用，與生產隔離）
#   Composer 2.x
#   Node.js 22 LTS + npm
#   Laravel .env（local）
#   資料庫 + migrate
#   前端 npm install
# =============================================================================

set -e
RESET='\033[0m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'
info()  { echo -e "${GREEN}[INFO]${RESET} $*"; }
warn()  { echo -e "${YELLOW}[WARN]${RESET} $*"; }
error() { echo -e "${RED}[ERROR]${RESET} $*"; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKEND="$REPO_ROOT/backend"
FRONTEND="$REPO_ROOT/frontend"

# =============================================================================
# 0. 確認在 WSL2
# =============================================================================
if ! grep -qi microsoft /proc/version 2>/dev/null; then
  warn "這個腳本設計給 WSL2 Ubuntu 使用，偵測到非 WSL 環境，請確認後繼續。"
  read -rp "確認繼續？(y/N) " ans; [[ "$ans" =~ ^[Yy]$ ]] || exit 1
fi

# =============================================================================
# 1. 系統套件
# =============================================================================
info "更新 apt..."
sudo apt-get update -q

info "加入 PHP 8.2 PPA..."
sudo apt-get install -yq software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update -q

info "安裝 PHP 8.2 + extensions..."
sudo apt-get install -yq \
  php8.2 php8.2-cli php8.2-fpm \
  php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl \
  php8.2-zip php8.2-bcmath php8.2-tokenizer php8.2-json \
  php8.2-openssl php8.2-fileinfo php8.2-gd php8.2-intl \
  unzip git curl

php -v | head -1
info "PHP 安裝完成"

# =============================================================================
# 2. Composer
# =============================================================================
if ! command -v composer &>/dev/null; then
  info "安裝 Composer..."
  curl -sS https://getcomposer.org/installer | php
  sudo mv composer.phar /usr/local/bin/composer
fi
composer --version
info "Composer 安裝完成"

# =============================================================================
# 3. Node.js 22 (LTS) via nvm
# =============================================================================
if ! command -v node &>/dev/null; then
  info "安裝 nvm + Node.js 22..."
  curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash
  export NVM_DIR="$HOME/.nvm"
  # shellcheck source=/dev/null
  source "$NVM_DIR/nvm.sh"
  nvm install 22
  nvm use 22
  nvm alias default 22
else
  info "Node.js 已安裝：$(node -v)"
fi

# Ensure nvm is loaded for remainder of script
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && source "$NVM_DIR/nvm.sh"

# =============================================================================
# 4. MariaDB（本地開發）
# =============================================================================
if ! command -v mysql &>/dev/null; then
  info "安裝 MariaDB..."
  sudo apt-get install -yq mariadb-server
fi

info "啟動 MariaDB..."
sudo service mariadb start 2>/dev/null || sudo systemctl start mariadb 2>/dev/null || true

# 設定本地開發 DB
info "設定本地資料庫..."
echo -n "請輸入要設定的本地 MySQL 密碼（自訂，不需與 Pi 相同）: "
read -rs LOCAL_DB_PASSWORD
echo

sudo mysql -e "
  CREATE DATABASE IF NOT EXISTS AllTrue_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS 'alltrue_dev'@'127.0.0.1' IDENTIFIED BY '${LOCAL_DB_PASSWORD}';
  GRANT ALL PRIVILEGES ON AllTrue_dev.* TO 'alltrue_dev'@'127.0.0.1';
  CREATE USER IF NOT EXISTS 'alltrue_dev'@'localhost' IDENTIFIED BY '${LOCAL_DB_PASSWORD}';
  GRANT ALL PRIVILEGES ON AllTrue_dev.* TO 'alltrue_dev'@'localhost';
  FLUSH PRIVILEGES;
"
info "資料庫 AllTrue_dev 建立完成"

# =============================================================================
# 5. Laravel .env（local）
# =============================================================================
cd "$BACKEND"

if [ ! -f .env ]; then
  info "建立 backend/.env..."
  cp .env.example .env 2>/dev/null || cat > .env << 'ENVEOF'
APP_NAME=AllTrueAdmin
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=AllTrue_dev
DB_USERNAME=alltrue_dev
DB_PASSWORD=

CACHE_DRIVER=file
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

MAIL_MAILER=log
ENVEOF

  # 填入 DB 密碼
  sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=${LOCAL_DB_PASSWORD}/" .env
  sed -i "s/^DB_DATABASE=.*/DB_DATABASE=AllTrue_dev/" .env
  sed -i "s/^DB_USERNAME=.*/DB_USERNAME=alltrue_dev/" .env
else
  warn "backend/.env 已存在，跳過建立（請確認 DB 設定正確）"
fi

# =============================================================================
# 6. Composer install + key
# =============================================================================
info "安裝 PHP 相依套件..."
composer install --no-interaction --prefer-dist

info "產生 APP_KEY..."
php artisan key:generate --force

# =============================================================================
# 7. Migration（本地 DB）
# =============================================================================
info "執行 migration（本地資料庫，非生產）..."
php artisan migrate --force
info "Migration 完成"

# =============================================================================
# 8. 前端 npm install
# =============================================================================
cd "$FRONTEND"
info "安裝前端相依套件..."
npm install
info "前端套件安裝完成"

# =============================================================================
# 完成
# =============================================================================
echo ""
echo -e "${GREEN}=============================================="
echo "  AllTrue 本地開發環境設定完成！"
echo "==============================================${RESET}"
echo ""
echo "啟動開發伺服器："
echo ""
echo "  後端 API："
echo "    cd backend && php artisan serve"
echo "    → http://localhost:8000"
echo ""
echo "  前端 dev server："
echo "    cd frontend && npm run dev"
echo "    → http://localhost:5173"
echo ""
echo "注意事項："
echo "  ⚠️  PHPUnit 測試只能跑在 GitHub Actions，絕不在本機執行"
echo "  ⚠️  部署到 Pi：merge PR → SSH 進 Pi → git pull → npm run deploy"
echo ""
