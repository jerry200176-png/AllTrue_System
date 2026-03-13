# 部署說明（Cloudflare Tunnel + Docker）

本文件說明如何透過 **手機熱點 + Cloudflare Tunnel** 將後端對外開放，並使用 Docker Compose 一鍵完成環境啟動、資料庫遷移與 Seed。

---

## 1. 環境優化（.env 設定）

部署前請先設定 `backend/.env`（可從 `backend/.env.production.example` 複製後修改）：

| 變數 | 說明 | 範例 |
|------|------|------|
| `APP_URL` | Cloudflare Tunnel 對外網址，**必須為 https** | `https://xxx.trycloudflare.com` |
| `SESSION_SECURE_COOKIE` | 外網 HTTPS 時設為 `true`，避免登入掉線 | `true` |
| `SESSION_DOMAIN` | 同源即可留空 | 留空或 `null` |
| `SANCTUM_STATEFUL_DOMAINS` | 前端 SPA 送 cookie 的網域，需包含 Cloudflare 網址 | `localhost,127.0.0.1,xxx.trycloudflare.com` |

資料庫（Docker Compose 情境）：

- `DB_HOST=postgres`
- `DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD` 與 `docker-compose.yml` 或 `.env` 中的變數一致。

前端若需對接後端 API，請將 `frontend/.env` 的 `VITE_API_BASE` 設為 `https://你的Cloudflare網址/api/v1`，再執行 `npm run build`。

---

## 2. 一鍵交付指令

在專案根目錄執行以下指令，會依序：啟動服務、執行資料庫遷移、灌入 Seed。

**Bash / CMD（Linux、macOS、Windows CMD）：**
```bash
docker-compose up -d --build && docker-compose exec app php artisan migrate --force && docker-compose exec app php artisan db:seed --force
```

**PowerShell（Windows）：** 請用分號 `;` 取代 `&&`，或分三行執行：
```powershell
docker-compose up -d --build; docker-compose exec app php artisan migrate --force; docker-compose exec app php artisan db:seed --force
```

步驟說明：

1. **docker-compose up -d --build**：建置並啟動 app、nginx、postgres，背景執行。
2. **docker-compose exec app php artisan migrate --force**：在 app 容器內執行遷移（production 需 `--force`）。
3. **docker-compose exec app php artisan db:seed --force**：在 app 容器內執行 Seed。若出現 `DatabaseSeeder does not exist`，請先在容器內執行 `composer dump-autoload` 後再執行 seed。

請先確認 `backend/.env` 已依上方「環境優化」設定完成，再執行本指令。

---

## 3. 資料持久化

PostgreSQL 資料已掛載至本機目錄 `./data/postgres`，重啟或 `docker-compose down` 後資料仍會保留。首次執行時若 `./data/postgres` 為空，會建立全新資料庫並由 migrate + seed 寫入資料。

---

## 4. 讓其他人連線（Cloudflare Tunnel）

本機服務只聽 `localhost:8080`，外網無法直接連線。用 **Cloudflare Tunnel** 可免費取得一個對外的 https 網址，讓其他人透過瀏覽器連進來。

### 步驟一：確認本機服務已啟動

在專案根目錄執行一鍵指令（見上方），確認本機可開：

- **http://localhost:8080**（後端 API + 若有把前端 build 放進 public 則整站）

### 步驟二：安裝並啟動 Cloudflare Tunnel

1. **下載 cloudflared**  
   - Windows: https://developers.cloudflare.com/cloudflare-one/connections/connect-apps/install-and-setup/installation/  
   - 或使用 winget：`winget install --id Cloudflare.cloudflared`  
   - macOS: `brew install cloudflared`

2. **啟動 Tunnel（免登入、隨機網址）**  
   在終端機執行：
   ```bash
   cloudflared tunnel --url http://localhost:8080
   ```
   畫面上會出現一行類似：
   ```text
   https://隨機名稱.trycloudflare.com
   ```
   這就是**對外網址**，其他人用瀏覽器開這個網址即可連到你的本機 8080。

3. **保持此終端機不要關閉**，關閉後 Tunnel 就斷線，別人就連不到。

### 步驟三：後端 .env 改為 Tunnel 網址

1. 把步驟二得到的網址（例如 `https://abc123.trycloudflare.com`）填進 `backend/.env`：
   ```env
   APP_URL=https://abc123.trycloudflare.com
   SESSION_SECURE_COOKIE=true
   SANCTUM_STATEFUL_DOMAINS=localhost,127.0.0.1,abc123.trycloudflare.com
   ```
   （把 `abc123` 換成你實際的隨機名稱）

2. 重啟 app 讓設定生效：
   ```powershell
   docker-compose restart app
   ```

### 步驟四：前端要給別人用時（選用）

- 若前端是**單獨開發機**（`npm run dev`）：  
  別人連的是你本機，你只要把 **Tunnel 網址** 給他們，他們開的是你後端（例如 Laravel 回傳的頁面或 API）。若你的「網站」是前後端分離，前端 dev 只在你電腦跑，則別人無法直接用到前端的 dev server。

- 若你要讓別人用到 **Vue 前端畫面**：  
  1. 在 `frontend/.env` 設 `VITE_API_BASE=https://你的Tunnel網址/api/v1`  
  2. 執行 `npm run build`  
  3. 把 `frontend/dist` 裡建好的檔案放到後端 `backend/public`（或 nginx 指到的目錄），讓同一個 Tunnel 網址同時提供前後端。  
  這樣別人只要開 **一個網址**（Tunnel 的 https）就能用完整網站。

### 步驟五：把網址給其他人

把 **https://隨機名稱.trycloudflare.com** 傳給要測試的人（Line、Email 等），他們用瀏覽器開啟即可，無需 VPN 或連你電腦。

**注意：**

- 每次重新執行 `cloudflared tunnel --url http://localhost:8080`，網址可能會變（trycloudflare.com 隨機），若變了要再更新 `APP_URL`、`SANCTUM_STATEFUL_DOMAINS` 並重啟 app。
- 你電腦關機或斷網，Tunnel 就斷線，別人會連不到。
- 手機熱點：若你是用手機分享網路給電腦，只要電腦能上網，Tunnel 就可用，別人照樣用上述 https 網址連線，無需知道你用的是熱點。

---

## 5. 若出現 500 Server Error（localhost:8080）

1. **確認日誌與目錄**
   - 後端日誌：`backend/storage/logs/laravel.log`
   - 若出現「Log [] is not defined」：請在 `backend/.env` 加上或修正為 `LOG_CHANNEL=stack`
   - 若出現「bootstrap/cache directory must be present and writable」：在專案根目錄執行（PowerShell）：
     ```powershell
     New-Item -ItemType Directory -Force -Path backend\bootstrap\cache; New-Item -ItemType Directory -Force -Path backend\storage\logs; icacls backend\bootstrap\cache /grant Everyone:F; icacls backend\storage /grant Everyone:F
     ```
     若使用 Docker，可改在容器內修復權限後重啟：
     ```powershell
     docker-compose exec app chmod -R 775 /var/www/bootstrap/cache /var/www/storage
     docker-compose restart app
     ```

2. **清除快取後再試**
   - 本機：在 `backend` 目錄執行 `php artisan config:clear`
   - Docker：`docker-compose exec app php artisan config:clear`
