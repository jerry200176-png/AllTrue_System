---
name: Mirai Security Hardening
overview: 撰寫 /home/admin/docs/SECURITY_HARDENING_MIRAI.md，內容是 AI 可逐步執行的完整資安加強手冊，確保凌晨 1 點備份正常、不再有 Mirai 類漏洞，每個步驟都有驗證指令。
todos:
  - id: write-hardening-guide
    content: 撰寫 /home/admin/docs/SECURITY_HARDENING_MIRAI.md，包含 5 大執行區塊，每個修補項目有「執行指令 + 驗證指令」，AI 直接照順序執行不需人工確認
    status: completed
isProject: false
---

# Mirai 防護資安加強指南計畫

## 輸出目標

`/home/admin/docs/SECURITY_HARDENING_MIRAI.md`

性質：**AI 可自動執行的操作手冊**，每個步驟格式固定為：
1. 說明（為什麼需要做）
2. 執行指令（可直接複製貼上）
3. 驗證指令（確認成功的判斷標準）

---

## 已確認的入侵事件與漏洞（調查結果）

### 入侵時間線（已發生）

- **Apr 17 16:22** — `.vnc/passwd` 被建立（來源不明，VNC 密碼新增）
- **Apr 18 07:04 & 16:35** — IP `31.56.209.39`（Pfcloud / 荷蘭 bulletproof hosting）從 SSH 成功登入，每次僅 5 分鐘（典型植入行為）
- **Apr 18 14:40** — 惡意程式 `.UoQsjidhe2CBXLdnWKmw9Yx4`（1.56MB ARM ELF binary，Mirai 變種）被 `git add -A` 捲進 nightly backup commit
- **Apr 20 16:07** — 手動移除惡意程式並 commit（本機已修復）
- **現在** — GitHub remote 仍有惡意程式 commit `de03daf`，fix 尚未 push（本機領先 2 commits）

### 備份腳本的根本問題

[scripts/nightly-backup.sh](scripts/nightly-backup.sh) 透過 [scripts/git-sync.sh](scripts/git-sync.sh) 執行 `git add -A`，
會把工作目錄所有新增檔案（包含攻擊者植入的 binary）一併 commit 推上 GitHub。
`.gitignore` 雖已加入隨機長名稱 pattern，但屬被動防禦，需在 `git add` 前加主動掃描。

### 目前仍暴露的服務（`ss -tlnp` 實測結果）

| Port | 服務 | 風險 | 說明 |
|------|------|------|------|
| 1883 | Mosquitto MQTT（無加密） | Critical | Mirai C&C 常用通道，對 0.0.0.0 完全開放 |
| 8883 | Mosquitto MQTT/TLS | High | 同上（加密但仍對外） |
| 445/139 | Samba | High | `wide links=yes`，允許 symlink 橫向移動 |
| 11434 | Ollama AI server | High | 無認證，對所有 interface 開放 |
| 5678 | n8n workflow | High | 可觸發任意 workflow，對外開放 |
| 5432 | PostgreSQL | High | docker-compose.yml 綁定 host port（Docker 啟動時暴露） |
| 22 | SSH | Medium | 可疑 IP 曾成功登入，fail2ban 存在但 jail 設定未確認 |

---

## 5 大執行區塊（AI 依序執行）

### 區塊 A — 緊急修復（今天必做）

**A1. Push 安全修復 commit 到 GitHub**
- 問題：GitHub 仍有惡意 commit `de03daf`（1.56MB ARM binary）
- 執行：`cd /home/admin && git push origin HEAD:jerry-sync-main`
- 驗證：`git log --oneline origin/jerry-sync-main | head -3` — 應看到 `fd5e589 security: gitignore`

**A2. 確認惡意程式不在 filesystem**
- 執行：`find /home/admin -maxdepth 5 -executable ! -name "*.sh" ! -name "*.py" ! -path "*/.git/*" ! -path "*/node_modules/*" -newer /home/admin/backend/.env 2>/dev/null`
- 驗證：輸出應為空，若有輸出立即停止並回報

**A3. 稽核 SSH authorized_keys**
- 執行：`cat /home/admin/.ssh/authorized_keys; cat /home/jeng/.ssh/authorized_keys 2>/dev/null`
- 驗證：每一行都應是你認識的 key，不認識的立即刪除
- 輔助：`last -30 | grep -v reboot | awk '{print $3}' | sort -u` — 列出近期登入 IP

---

### 區塊 B — 防火牆（UFW）強化

目標：只對外開放 80、443、22，其餘全部封鎖

```bash
# Step B1: 確認現狀
sudo ufw status verbose

# Step B2: 設定預設規則
sudo ufw --force enable
sudo ufw default deny incoming
sudo ufw default allow outgoing

# Step B3: 開放必要 port
sudo ufw allow 80/tcp   comment 'HTTP'
sudo ufw allow 443/tcp  comment 'HTTPS'
sudo ufw allow 22/tcp   comment 'SSH'

# Step B4: 封鎖危險 port
sudo ufw deny 1883/tcp  comment 'MQTT block - Mirai C&C'
sudo ufw deny 8883/tcp  comment 'MQTT-TLS block'
sudo ufw deny 5432/tcp  comment 'PostgreSQL block'
sudo ufw deny 11434/tcp comment 'Ollama block'
sudo ufw deny 5678/tcp  comment 'n8n block'
sudo ufw deny 445/tcp   comment 'Samba block'
sudo ufw deny 139/tcp   comment 'NetBIOS block'
sudo ufw reload
```

驗證：`sudo ufw status numbered` — 1883/5432/11434/5678/445 全部出現 DENY

---

### 區塊 C — 備份腳本安全強化（修改 git-sync.sh）

在 [scripts/git-sync.sh](scripts/git-sync.sh) 的 `git add -A` **前**插入 pre-sync 安全掃描函式：

```bash
# 插入位置：git add -A 這行之前
_abort_if_suspicious() {
  local found=""
  while IFS= read -r f; do
    bname=$(basename "$f")
    # 條件1：隱藏檔 + 名稱長度 > 15（隨機名惡意程式）
    if [[ "$bname" == .* ]] && [ "${#bname}" -gt 15 ]; then
      found="$found\n  SUSPICIOUS_HIDDEN: $f"
    fi
    # 條件2：可執行的 ELF/ARM binary（非 .sh/.py）
    case "$f" in *.sh|*.py|*.php|*.js|*.ts|*.vue) continue ;; esac
    if [ -x "$f" ] && file "$f" 2>/dev/null | grep -qiE 'ELF|ARM|Mach-O'; then
      found="$found\n  SUSPICIOUS_BINARY: $f"
    fi
  done < <(git ls-files --others --exclude-standard)

  if [ -n "$found" ]; then
    echo "[SECURITY-ABORT] Suspicious files detected, backup HALTED:" >&2
    printf '%b\n' "$found" >&2
    echo "[SECURITY-ABORT] Remove these files before backup can proceed." >&2
    exit 99
  fi
}
_abort_if_suspicious
```

驗證方式：在 `/home/admin` 放一個假 binary `touch .test_malware && chmod +x .test_malware`，
跑 `bash scripts/git-sync.sh "test"` — 應看到 `SECURITY-ABORT` 並 exit 99，然後刪掉假檔案。

---

### 區塊 D — 應用程式修補

**D1. 移除 `/api/fix-db` 未授權 DDL 端點**
- 檔案：[backend/routes/api.php](backend/routes/api.php) line 40–70
- 動作：在 Route block 外層加 `if (app()->environment('local')) { ... }` 包住，或直接刪除
- 驗證：`curl -s -o /dev/null -w "%{http_code}" http://localhost/api/fix-db` — 應回 `404`

**D2. CORS 收緊**
- 檔案：[backend/config/cors.php](backend/config/cors.php)
- 動作：`'allowed_origins' => [env('FRONTEND_URL', 'https://daan.lifenet.com.tw')]`
- 驗證：`curl -H "Origin: https://evil.com" -I http://localhost/api/v1/branches | grep -i access-control` — 應無 `Access-Control-Allow-Origin`

**D3. 全域 API Rate Limit**
- 檔案：[backend/app/Http/Kernel.php](backend/app/Http/Kernel.php)
- 動作：`api` middleware group 加入 `\App\Http\Middleware\ThrottleRequestsByIp::class`（已有此 class）
- 驗證：快速打 200+ 次後應出現 HTTP 429

**D4. Docker Compose 移除 PostgreSQL host port**
- 檔案：[docker-compose.yml](docker-compose.yml) line 32
- 動作：移除 `ports: - "5432:5432"`（保留 Docker 內網，外部無法直連）
- 驗證：重啟後 `ss -tlnp | grep 5432` — 應無輸出

---

### 區塊 E — 備份凌晨 1 點正常化驗證

```bash
# E1: 手動試跑（安全）
sudo -u admin bash /home/admin/scripts/nightly-backup.sh

# E2: 確認 log 正常結束
tail -10 /home/admin/backups/nightly-backup.log
# 預期最後一行：[YYYY-MM-DD HH:MM:SS] === Nightly backup done ===

# E3: 確認 SQL 備份存在
ls -lh /home/admin/backups/alltrue_nightly_*.sql.gz | tail -3
# 預期：最新檔案 > 100KB

# E4: 確認 git push 成功
git log --oneline origin/jerry-sync-main | head -3
# 預期：最上方是剛才的 backup commit

# E5: 確認安全掃描正常（沒有誤報）
grep -i 'security-abort\|WARN\|ERROR' /home/admin/backups/nightly-backup.log | tail -5
# 預期：空，或只有無害的 WARN
```

---

## AI 執行順序

```
A（緊急） → B（防火牆） → C（備份腳本） → D（應用層） → E（驗證備份）
  15min        10min          15min             20min           5min
```

每區塊完成後執行驗證指令，確認輸出符合預期才進下一區塊。
若任何驗證失敗，**立即停止並回報錯誤內容**，不繼續往下執行。

---

## 最終驗證清單（全部完成才算收工）

```
[ ] GitHub jerry-sync-main 最新 commit 是 fd5e589（gitignore security fix）
[ ] find 掃描無可疑 binary
[ ] UFW: 1883/5432/11434/5678/445 全部 DENY
[ ] /api/fix-db 回傳 404
[ ] CORS 拒絕 evil.com Origin（無 Access-Control-Allow-Origin header）
[ ] git-sync.sh 加入掃描函式，測試假 binary 時正確 abort
[ ] 手動跑 nightly-backup.sh 完成，log 最後一行是 "Nightly backup done"
[ ] backups/ 有今天的 .sql.gz 且 > 100KB
[ ] git log 顯示今天的 backup commit 已在 jerry-sync-main
```
