# AllTrue 教務管理系統 - Apache + PHP + MySQL 部署

本系統採用 **純 Apache + PHP + MySQL**，無 Node.js 執行時、無 Docker。

## 需求

- **Apache** 2.4+（含 mod_rewrite、mod_php 或 php-fpm）
- **PHP** 8.1+（mbstring, pdo_mysql, tokenizer, xml, ctype, json）
- **MySQL** 5.7+ 或 MariaDB 10.3+
- **Composer**（建置時）
- **Node.js**（僅建置前端時需要，執行時不需要）

## 快速開始

### 1. 安裝依賴

```bash
# 後端
cd backend && composer install --no-dev --optimize-autoloader

# 前端（需 Node.js，建置後可移除）
cd frontend && npm install && npm run deploy
```

### 2. 設定環境

```bash
cd backend
cp .env.example .env
php artisan key:generate
# 編輯 .env 設定 DB_* (MySQL)
php artisan migrate
```

### 3. Apache 設定

```bash
# 複製設定檔，並將 /path/to/admin 改為實際路徑
sudo cp apache/alltrue.conf /etc/apache2/sites-available/
sudo sed -i 's|/path/to/admin|'$(pwd)'|g' /etc/apache2/sites-available/alltrue.conf

# 啟用
sudo a2enmod rewrite
sudo a2ensite alltrue
sudo systemctl restart apache2
```

### 4. 建立管理員

訪問 `https://你的網域/api/create-admin` 建立 Super Admin（admin@admin.com / admin123）

## 開發模式

```bash
# 終端 1：Laravel 後端
cd backend && php artisan serve --port=8000

# 終端 2：前端（Vite 會 proxy /api 到 8000）
cd frontend && npm run dev
```

或使用 `./dev.sh` 一次啟動兩者。

## 目錄結構

```
admin/
├── apache/alltrue.conf    # Apache VirtualHost
├── backend/                # Laravel (PHP)
│   └── public/             # DocumentRoot（含前端 build）
├── frontend/               # Vue 原始碼（建置後複製到 backend/public）
└── docs/
```

## 注意事項

- **DocumentRoot** 必須指向 `backend/public`
- 前端建置後會複製到 `backend/public`（index.html、assets/）
- 建置前端需要 Node.js，但 **執行時不需要**
