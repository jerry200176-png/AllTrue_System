# 部署指南：daan.lifenet.com.tw

本系統採用 **純 Apache + PHP + MySQL**，無 Node.js 執行時、無 Docker。詳見 [APACHE-SETUP.md](APACHE-SETUP.md)。

---

## 錯誤：API 回傳 `<?php use...` 而非 JSON

當瀏覽器請求 `/api/v1/branches` 時收到 PHP 原始碼，代表 **Web 伺服器未正確將請求導向 Laravel**。

### 若使用 PHP 內建伺服器 (php -S)

**必須使用 router 腳本**，否則 `/api/*` 會回傳 404 或錯誤：

```bash
php -S 0.0.0.0:8080 -t public public/router.php
```

**錯誤範例**（會導致 API 失敗）：
```bash
php -S 0.0.0.0:8080 -t public   # 缺少 router
```

### 若使用 Apache / Nginx

**Document root 必須指向 `backend/public`**，不是 `backend` 或專案根目錄：

```
正確：/path/to/admin/backend/public
錯誤：/path/to/admin/backend
錯誤：/path/to/admin
```

### Apache 範例

```apache
<VirtualHost *:80>
    ServerName daan.lifenet.com.tw
    DocumentRoot /path/to/admin/backend/public

    <Directory /path/to/admin/backend/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

- `AllowOverride All` 必須開啟，`.htaccess` 才能生效
- `mod_rewrite` 必須啟用

### Nginx 範例

```nginx
server {
    listen 80;
    server_name daan.lifenet.com.tw;
    root /path/to/admin/backend/public;
    index index.html index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 驗證

部署後依序測試：

```bash
# 1. 健康檢查（若成功表示 Laravel 路由正常）
curl -s https://daan.lifenet.com.tw/api/health
# 預期：{"ok":true,"message":"Laravel routing OK"}

# 2. 分校列表
curl -s https://daan.lifenet.com.tw/api/v1/branches
# 預期：JSON 陣列，例如 [{"id":1,"name":"大安分校","code":"daan"}]
```

若回傳 `<?php` 開頭內容，表示設定仍不正確。

---

## 部署前端（EPERM 權限問題）

若 `npm run deploy` 出現 EPERM：

**方式一：一次性修正權限（推薦）**
```bash
./scripts/fix-deploy-permissions.sh
cd frontend && npm run deploy
```

**方式二：每次用 sudo 複製**
```bash
cd frontend && ./scripts/deploy-sudo.sh
```

**方式三：打包後在伺服器解壓（網路磁碟 / 無 sudo 時）**
```bash
cd frontend && npm run deploy:pack
# 產生 frontend/deploy.tar.gz 後，在專案根目錄執行：
sudo tar -xzf frontend/deploy.tar.gz -C backend/public
```

---

## 500 Internal Server Error（branches / campuses）

若 `/api/v1/branches` 回傳 500，通常是資料庫問題：

1. **執行 migration**：
   ```bash
   cd backend && php artisan migrate
   ```

2. **確認 Campus 表存在**：若使用 PostgreSQL，表名為 `Campus`（大寫）

3. **檢查 Laravel 日誌**：`backend/storage/logs/laravel.log`

---

## 401 Unauthorized 登入失敗

若 branches 正常但登入回傳 401：

1. **確認帳號密碼**：預設 Super Admin 為 `admin@admin.com` / `admin123`
2. **建立 Super Admin**：訪問 `/api/create-admin` 建立預設管理員
3. **檢查資料庫**：確認 `users` 表存在且可連線
