# 重新佈署到樹莓派 5 (Linux)

以下兩種方式擇一使用。

---

## 方式一：在樹莓派上直接佈署（推薦）

專案程式碼已在樹莓派上（例如 `/var/www/admin` 或你放專案的路徑）。

### 1. 進入專案目錄

```bash
cd /path/to/admin
```

（請把 `/path/to/admin` 換成實際路徑，例如 `~/admin` 或 `/var/www/alltrue-admin`。）

### 2. 執行佈署腳本

```bash
chmod +x scripts/deploy-to-pi.sh
./scripts/deploy-to-pi.sh
```

腳本會依序：

- 建置前端（`cd frontend && npm run build`）
- 將 `frontend/dist` 複製到 `backend/public`（覆蓋 `index.html` 與 `assets/`）
- 執行 `php artisan optimize:clear` 清除 Laravel 快取
- 設定靜態檔讀取權限

### 3. 若使用 Apache / Nginx

佈署後通常**不用重啟**服務，因為只是覆蓋檔案。若仍有快取問題，可再執行：

```bash
cd backend && php artisan optimize:clear
```

或（若你有放）在瀏覽器開啟一次：  
`http://你的樹莓派IP/reset_cache.php`  
（該腳本執行後會自我刪除。）

---

## 方式二：在本機建置，再上傳到樹莓派

適合樹莓派上沒裝 Node.js，或想在本機 (Windows) 建置的情況。

### 1. 在本機建置並打包

在 **Windows**（專案目錄）：

```bash
cd frontend
npm run build
npm run deploy:pack
```

會產生 `frontend/deploy.tar.gz`（內含 `index.html` 與 `assets/`）。

### 2. 上傳到樹莓派

用 SCP、SFTP 或 rsync 把檔案傳到樹莓派，例如：

```bash
scp frontend/deploy.tar.gz pi@樹莓派IP:/path/to/admin/frontend/
```

（`pi` 與路徑請改成你的使用者與專案路徑。）

### 3. 在樹莓派上解壓並清快取

SSH 登入樹莓派後：

```bash
cd /path/to/admin
sudo tar -xzf frontend/deploy.tar.gz -C backend/public
cd backend && php artisan optimize:clear
```

（若不需要 sudo 寫入 `backend/public`，可省略 `sudo`。）

---

## 環境需求（樹莓派）

- **PHP** 8.1+（含 mbstring, pdo_mysql, tokenizer, xml, ctype, json）
- **Composer**（後端依賴）
- **Node.js** 18+（僅在「方式一」於 Pi 上建置時需要）
- 網頁伺服器：**Apache** 或 **Nginx**，document root 指向 `backend/public`

---

## Log 管理（2026-04-16 新增）

### Log Rotation

`laravel.log` 已改為 **daily rotation**（保留 14 天）。部署後無需額外設定。

查看當日 log：
```bash
tail -30 backend/storage/logs/laravel-$(date +%Y-%m-%d).log
```

### Tmpfs 緩衝（選擇性）

在正式節點可啟用 log 記憶體緩衝以降低 I/O：

```bash
sudo bash scripts/infra/setup-log-tmpfs.sh
```

回滾：
```bash
sudo bash scripts/infra/rollback-log-tmpfs.sh
```

詳見 `docs/OPERATIONS_RUNBOOK.md` §L。

### 儲存介質檢查

確認節點未使用 SD 卡作為根檔案系統：
```bash
bash scripts/infra/storage-inventory.sh
```

---

## 常見問題

- **前端沒更新**：強制重新整理（Ctrl+Shift+R 或 Cmd+Shift+R），或清除瀏覽器快取。
- **500 錯誤**：檢查 `backend/.env`、`storage/logs/laravel-$(date +%Y-%m-%d).log`，並確認 `backend/storage` 與 `backend/bootstrap/cache` 可寫。
- **權限錯誤**：靜態檔建議可讀即可，例如 `chmod -R a+r backend/public/assets`。
