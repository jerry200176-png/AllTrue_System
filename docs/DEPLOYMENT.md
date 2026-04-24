# 部署指南

本系統有兩種部署模式：**裸機 Apache**（生產）與 **Docker Compose**（開發/展示）。

---

## 1. 生產環境：Apache + PHP（daan.lifenet.com.tw）

純 Apache + PHP + MySQL，無 Node.js 執行時、無 Docker。詳見 [APACHE-SETUP.md](APACHE-SETUP.md)。

### 必要條件

- PHP 8.1+（mbstring, pdo_mysql, tokenizer, xml, ctype, json）
- Composer
- Apache 或 Nginx — **Document root 必須指向 `backend/public`**
- `AllowOverride All` + `mod_rewrite` 啟用

### 部署前端到生產

**正常流程（全自動）**：WSL2 push → PR merge → `deploy.yml` 自動執行，無需手動操作。

**緊急手動部署**（CI 掛掉時才用，需 SSH 到 Pi）：
```bash
cd /home/admin
git pull origin main
cd frontend && npm run deploy
# ⛔ 禁止用 optimize:clear，改用：
cd /home/admin/backend && php artisan optimize
```

若 `npm run deploy` 出現 EPERM，先執行 `./scripts/fix-deploy-permissions.sh`。

### 驗證

```bash
curl -s https://daan.lifenet.com.tw/api/health
curl -s https://daan.lifenet.com.tw/api/v1/branches
```

若 API 回傳 `<?php` 原始碼 → Document root 未指向 `backend/public` 或 `mod_rewrite` 未啟用。

---

## 2. 開發/展示：Docker Compose + Cloudflare Tunnel

### .env 設定（`backend/.env`）

| 變數 | 說明 | 範例 |
|------|------|------|
| `APP_URL` | 對外 HTTPS 網址 | `https://xxx.trycloudflare.com` |
| `SESSION_SECURE_COOKIE` | HTTPS 時設 `true` | `true` |
| `SANCTUM_STATEFUL_DOMAINS` | 含 Cloudflare 網址 | `localhost,127.0.0.1,xxx.trycloudflare.com` |
| `DB_HOST` | Docker 內用 `postgres` | `postgres` |

前端 `frontend/.env`：`VITE_API_BASE=https://你的網址/api/v1`，再 `npm run build`。

### 一鍵啟動

```bash
docker-compose up -d --build \
  && docker-compose exec app php artisan migrate --force \
  && docker-compose exec app php artisan db:seed --force
```

資料持久化於 `./data/postgres`，重啟後保留。

### Cloudflare Tunnel（免費對外網址）

```bash
cloudflared tunnel --url http://localhost:8080
```

取得的 `https://隨機名.trycloudflare.com` 即為對外網址。記得將網址更新到 `APP_URL` 與 `SANCTUM_STATEFUL_DOMAINS`，然後 `docker-compose restart app`。

---

## 3. 樹莓派專用注意事項

- 環境需求：PHP 8.1+、Composer、Node.js 18+（僅本機建置時）
- Log 已改為 daily rotation（14 天保留），部署後無需額外設定
- 選擇性啟用 tmpfs 緩衝：`sudo bash scripts/infra/setup-log-tmpfs.sh`（詳見 `OPERATIONS_RUNBOOK.md` §L）
- 確認非 SD 卡根檔案系統：`bash scripts/infra/storage-inventory.sh`

---

## 4. 常見問題

### 500 Internal Server Error

1. 查日誌：`tail -30 backend/storage/logs/laravel-$(date +%Y-%m-%d).log`
2. 清 bootstrap cache：`rm -f backend/bootstrap/cache/{services,packages,config}.php`
3. 確認 `.env` DB 連線、`LOG_CHANNEL=stack`
4. 確認 `storage/` 與 `bootstrap/cache/` 可寫

### 401 Unauthorized

1. 預設 Super Admin：`admin@admin.com`（密碼請查 DB 或重設）
2. 建立管理員：`/api/create-admin`（僅限 local 環境）

### 前端沒更新

強制重新整理（Ctrl+Shift+R），或確認已執行 `npm run deploy` 並清快取。

---

## 5. Log 管理

查看當日 log：
```bash
tail -30 backend/storage/logs/laravel-$(date +%Y-%m-%d).log
```

回滾 tmpfs：`sudo bash scripts/infra/rollback-log-tmpfs.sh`
