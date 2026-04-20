# AllTrue 系統資安加強防護指南 — 防 Mirai 類攻擊

> **文件性質**：AI 可自動執行的操作手冊 + 事件回應報告  
> **報告日期**：2026-04-20  
> **機密等級**：CONFIDENTIAL  
> **評估者**：AI Agent (Cursor / Claude)  
> **目標系統**：Raspberry Pi 5 (ARM64) — AllTrue 教務管理系統  
> **參考標準**：OWASP TG v4.2、NIST SP 800-115、CVSS v3.1

---

## 執行摘要 (Executive Summary)

本次評估針對 AllTrue 教務系統（Raspberry Pi 部署）進行漏洞盤點與事件回應分析。

**整體風險評級：CRITICAL**

系統於 **2026-04-18** 遭受真實入侵：攻擊者透過 SSH 弱密碼登入後植入 Mirai 變種 ARM binary（`.UoQsjidhe2CBXLdnWKmw9Yx4`，1.56MB），該惡意程式被 nightly backup 的 `git add -A` 捲入 Git 歷史。惡意程式已移除，但 **系統仍有多個暴露面需立即修補**。

共發現 **12 項** 問題：
- Critical：3 項
- High：5 項
- Medium：3 項
- Informational：1 項

最高優先修補項目為**防火牆未啟用導致 MQTT/Samba/Ollama/n8n 全面暴露**（CVSS 9.8），攻擊者可直接利用 MQTT 作為 C&C 通道或經 Samba 橫向移動。

建議於 **2026-04-20 當日** 完成所有 Critical/High 項目修補。

---

## 入侵事件時間線 (Incident Timeline)

| 時間 (UTC+8) | 事件 | 證據來源 |
|-------------|------|---------|
| Apr 17 16:22 | `.vnc/passwd` 被建立 | `git log --diff-filter=A -- .vnc/passwd` |
| Apr 18 07:04 | IP `31.56.209.39` SSH 登入成功（5分鐘） | `last -30`（Pfcloud/荷蘭 bulletproof hosting） |
| Apr 18 14:40 | 惡意程式 `.UoQsjidhe2CBXLdnWKmw9Yx4` 出現在 nightly commit | `git show de03daf --stat` |
| Apr 18 16:35 | IP `31.56.209.39` 再次 SSH 登入（5分鐘） | `last -30` |
| Apr 20 16:07 | 手動移除惡意程式 | `git show 67545c9` |
| Apr 20 16:12 | 加入 `.gitignore` 防禦 pattern | `git show fd5e589` |
| Apr 20 16:36 | Push 修復到 GitHub + 修補備份腳本 | 本次操作（`afc38ff`） |

**根因分析 (5 Whys)**：
1. 為何惡意程式進入 Git？→ `git-sync.sh` 使用 `git add -A` 無檢查
2. 為何攻擊者能登入？→ SSH 允許密碼認證 + 無 IP 限制
3. 為何 SSH 暴露？→ 防火牆未啟用（UFW inactive）
4. 為何未偵測到入侵？→ 無即時 SSH 登入告警機制
5. 為何多餘服務暴露？→ 開發環境設定直接用於生產

**根本原因**：從開發直接轉為生產部署，未執行上線前安全加固（hardening），防火牆未啟用。

---

## 範圍與方法論

### 測試範圍
| 項目 | 說明 |
|------|------|
| 目標 | Raspberry Pi 5 (ARM64)，hostname: `pi.lifenet.com.tw` |
| 排除範圍 | 無 |
| 測試類型 | White-box（有完整原始碼與系統存取權） |
| 環境 | Production |

### 方法論
- OWASP Testing Guide v4.2（網頁應用程式）
- NIST SP 800-115（主機/網路層掃描）
- CVSS v3.1（漏洞評分）
- STRIDE（威脅建模）

---

## 詳細發現 (Detailed Findings)

---

### [FIND-001] 防火牆未啟用 — 多服務直接對外暴露

| 欄位 | 內容 |
|------|------|
| **嚴重程度** | Critical |
| **CVSS v3.1 分數** | 9.8 |
| **CVSS 向量** | `CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H` |
| **CWE 編號** | CWE-284：存取控制不當 |
| **CVE 編號** | N/A（設定問題） |
| **受影響組件** | Pi host — ports 1883, 8883, 445, 139, 11434, 5678, 9993 |
| **發現工具** | `ss -tlnp` 手動檢查 |

#### 描述
UFW 防火牆未啟用（status: inactive），所有 listen 的服務（MQTT、Samba、Ollama、n8n、ZeroTier）均可從外部直接存取。MQTT port 1883 是 Mirai botnet C&C 常用通道。

#### 影響
攻擊者可利用 MQTT 作為 C&C 通道、透過 Samba（`wide links=yes`）讀取任意檔案（含 `.env` 密碼）、透過 n8n 觸發自動化 workflow。

#### 重現步驟
```bash
ss -tlnp | grep -v '127.0.0.1\|::1'
# 輸出顯示 1883/8883/445/139/11434/5678/9993 全部對 0.0.0.0 開放
```

#### 修補建議
**短期（當日）**：啟用 UFW，只開放 80/443/22

```bash
sudo ufw --force enable
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 80/tcp   comment 'HTTP'
sudo ufw allow 443/tcp  comment 'HTTPS'
sudo ufw allow 22/tcp   comment 'SSH'
sudo ufw deny 1883/tcp  comment 'MQTT block - Mirai C&C'
sudo ufw deny 8883/tcp  comment 'MQTT-TLS block'
sudo ufw deny 5432/tcp  comment 'PostgreSQL block'
sudo ufw deny 11434/tcp comment 'Ollama block'
sudo ufw deny 5678/tcp  comment 'n8n block'
sudo ufw deny 445/tcp   comment 'Samba block'
sudo ufw deny 139/tcp   comment 'NetBIOS block'
sudo ufw reload
```

**驗證**：`sudo ufw status numbered` — 所有危險 port 顯示 DENY

**長期（治本）**：
- 不需要的服務直接停用：`sudo systemctl disable --now mosquitto smbd nmbd`
- Ollama 綁定 localhost：`/etc/systemd/system/ollama.service` 加 `Environment="OLLAMA_HOST=127.0.0.1"`
- n8n 綁定 localhost 或加認證

---

### [FIND-002] SSH 弱密碼認證 — 已被實際利用

| 欄位 | 內容 |
|------|------|
| **嚴重程度** | Critical |
| **CVSS v3.1 分數** | 9.1 |
| **CVSS 向量** | `CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:N` |
| **CWE 編號** | CWE-521：弱密碼需求 |
| **CVE 編號** | N/A |
| **受影響組件** | `sshd` port 22 |
| **發現工具** | `last -30`（IP `31.56.209.39`） |

#### 描述
SSH 允許密碼認證登入，攻擊者從荷蘭 bulletproof hosting IP 成功登入，植入 Mirai 變種 binary。

#### 影響
攻擊者取得 shell 權限，可植入惡意程式、讀取資料庫密碼、橫向移動到其他服務。

#### 修補建議
**短期（當日）**：

```bash
# 1. 變更所有使用者密碼
passwd admin
passwd jeng

# 2. 禁止 SSH 密碼登入（僅允許金鑰）
sudo sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sudo sed -i 's/^#\?ChallengeResponseAuthentication.*/ChallengeResponseAuthentication no/' /etc/ssh/sshd_config

# 3. 啟用 fail2ban SSH jail
sudo tee /etc/fail2ban/jail.local <<'EOF'
[sshd]
enabled = true
port = ssh
filter = sshd
logpath = /var/log/auth.log
maxretry = 3
findtime = 600
bantime = 3600
EOF
sudo systemctl restart fail2ban

# 4. 重啟 SSH
sudo systemctl restart sshd
```

**驗證**：`sudo sshd -T | grep passwordauthentication` — 應回 `passwordauthentication no`

**長期**：換用非標準 SSH port（如 2222），UFW 對應調整。

---

### [FIND-003] 備份腳本盲目 `git add -A` — 惡意程式被推上 GitHub

| 欄位 | 內容 |
|------|------|
| **嚴重程度** | Critical |
| **CVSS v3.1 分數** | 9.0 |
| **CVSS 向量** | `CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:C/C:H/I:H/A:N` |
| **CWE 編號** | CWE-829：不受信任搜尋路徑的功能包含 |
| **CVE 編號** | N/A |
| **受影響組件** | `scripts/git-sync.sh` line 30 |
| **發現工具** | `git log --diff-filter=A -- .UoQsjidhe2CBXLdnWKmw9Yx4` |

#### 描述
`git-sync.sh` 的 `git add -A` 會將工作目錄所有未追蹤檔案加入 commit，包含攻擊者植入的 binary。惡意程式因此被推至 GitHub。

#### 影響
惡意程式進入版本控制歷史，任何 clone 此 repo 的人都會下載到 Mirai binary。

#### 修補狀態
**已修補（2026-04-20）**：在 `git add -A` 前加入 `_abort_if_suspicious()` 安全掃描函式，偵測到隱藏長名稱檔案或 ELF/ARM binary 時 exit 99 中止備份。

修補檔案：`scripts/git-sync.sh`

**驗證**：
```bash
# 建立假惡意程式測試
cd /home/admin
touch .test_fake_malware_binary_12345 && chmod +x .test_fake_malware_binary_12345
bash scripts/git-sync.sh "test" 2>&1
# 預期：[SECURITY-ABORT] ... exit 99
rm .test_fake_malware_binary_12345
```

---

### [FIND-004] `/api/fix-db` 未授權 DDL 端點

| 欄位 | 內容 |
|------|------|
| **嚴重程度** | High |
| **CVSS v3.1 分數** | 8.6 |
| **CVSS 向量** | `CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:N` |
| **CWE 編號** | CWE-306：關鍵功能缺少認證 |
| **CVE 編號** | N/A |
| **受影響組件** | `backend/routes/api.php` line 40 |
| **發現工具** | 程式碼審查 |

#### 描述
`GET /api/fix-db` 無任何身份驗證，可執行 `ALTER TABLE`、建立資料表，並回傳資料庫 schema 資訊。

#### 修補狀態
**已修補（2026-04-20）**：以 `if (app()->environment('local'))` 包住，生產環境不註冊此路由。

**驗證**：`curl -s -o /dev/null -w "%{http_code}" http://localhost/api/fix-db` — 應回 `404`

---

### [FIND-005] CORS 全域開放 `*`

| 欄位 | 內容 |
|------|------|
| **嚴重程度** | High |
| **CVSS v3.1 分數** | 7.5 |
| **CVSS 向量** | `CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:N/A:N` |
| **CWE 編號** | CWE-942：過度寬鬆的跨域白名單 |
| **CVE 編號** | N/A |
| **受影響組件** | `backend/config/cors.php` |
| **發現工具** | 程式碼審查 |

#### 描述
`allowed_origins => ['*']` 允許任何網域的瀏覽器發送跨域請求到 API。

#### 修補狀態
**已修補（2026-04-20）**：改為 `[env('APP_URL'), env('FRONTEND_URL'), 'http://localhost:5173']`。

**驗證**：
```bash
curl -H "Origin: https://evil.com" -I http://localhost/api/v1/branches 2>/dev/null | grep -i access-control-allow-origin
# 預期：無輸出（不回傳 ACAO header）
```

---

### [FIND-006] 無全域 API Rate Limit

| 欄位 | 內容 |
|------|------|
| **嚴重程度** | High |
| **CVSS v3.1 分數** | 7.5 |
| **CVSS 向量** | `CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:N/A:H` |
| **CWE 編號** | CWE-770：資源分配無限制 |
| **CVE 編號** | N/A |
| **受影響組件** | `backend/app/Http/Kernel.php` |
| **發現工具** | 程式碼審查 |

#### 描述
API middleware group 無全域 throttle，僅 login 與 parent/login 有個別限制（30/10min 和 5/10min）。攻擊者可對任意 API 端點暴力發送請求。

#### 修補狀態
**已修補（2026-04-20）**：在 api group 加入 `throttle:200,1`（每 IP 每分鐘 200 次）。

---

### [FIND-007] PostgreSQL 對外暴露（Docker Compose）

| 欄位 | 內容 |
|------|------|
| **嚴重程度** | High |
| **CVSS v3.1 分數** | 7.2 |
| **CVSS 向量** | `CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:N/A:N` |
| **CWE 編號** | CWE-668：資源暴露至錯誤範圍 |
| **CVE 編號** | N/A |
| **受影響組件** | `docker-compose.yml` line 46 |
| **發現工具** | 設定審查 |

#### 描述
`ports: "5432:5432"` 將 PostgreSQL 綁定到 host，Docker 啟動時外部可直連資料庫。

#### 修補狀態
**已修補（2026-04-20）**：移除 `ports` 設定，PostgreSQL 僅在 Docker 內網 `alltrue` 可達。

---

### [FIND-008] Samba `wide links=yes` 允許路徑穿越

| 欄位 | 內容 |
|------|------|
| **嚴重程度** | High |
| **CVSS v3.1 分數** | 7.5 |
| **CVSS 向量** | `CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:N/A:N` |
| **CWE 編號** | CWE-22：路徑穿越 |
| **CVE 編號** | N/A |
| **受影響組件** | `/etc/samba/smb.conf` |
| **發現工具** | 設定審查 |

#### 描述
Samba 設定 `wide links = yes` + `unix extensions = no`，允許攻擊者透過 symlink 存取共享目錄以外的檔案（如 `/home/admin/backend/.env`）。

#### 修補建議
**短期（當日）**：UFW 已封鎖 445/139 port（FIND-001 修補）

**長期**：
```bash
# 若不需要 Samba，直接停用
sudo systemctl disable --now smbd nmbd

# 若仍需要，修正設定
sudo sed -i 's/wide links = yes/wide links = no/' /etc/samba/smb.conf
sudo sed -i 's/unix extensions = no/unix extensions = yes/' /etc/samba/smb.conf
sudo systemctl restart smbd
```

---

### [FIND-009] Nginx HTTPS redirect 未啟用

| 欄位 | 內容 |
|------|------|
| **嚴重程度** | Medium |
| **CVSS v3.1 分數** | 5.3 |
| **CVSS 向量** | `CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:N/A:N` |
| **CWE 編號** | CWE-319：敏感資訊明文傳輸 |
| **CVE 編號** | N/A |
| **受影響組件** | `docker/nginx.conf` line 5-8 |
| **發現工具** | 設定審查 |

#### 描述
Nginx 的 HTTPS 強制跳轉被 comment out，且缺少安全回應 header（HSTS、X-Frame-Options、X-Content-Type-Options）。

#### 修補建議
**短期**：修改 `docker/nginx.conf`，取消 HTTPS redirect comment 並加入安全 header。

```nginx
# 在 server block 最前面加入：
if ($http_x_forwarded_proto = "http") {
    return 301 https://$host$request_uri;
}

# 在 server block 內加入：
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

---

### [FIND-010] fail2ban jail 未確認啟用

| 欄位 | 內容 |
|------|------|
| **嚴重程度** | Medium |
| **CVSS v3.1 分數** | 5.3 |
| **CVSS 向量** | `CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:N/I:L/A:L` |
| **CWE 編號** | CWE-307：不當限制過多認證嘗試 |
| **CVE 編號** | N/A |
| **受影響組件** | `/etc/fail2ban/` |
| **發現工具** | 設定審查 |

#### 修補建議
```bash
# 檢查現有 jail
sudo fail2ban-client status

# 設定 SSH + HTTP jail
sudo tee /etc/fail2ban/jail.local <<'EOF'
[sshd]
enabled = true
port = ssh
maxretry = 3
findtime = 600
bantime = 3600

[apache-auth]
enabled = true
port = http,https
maxretry = 5
findtime = 600
bantime = 3600
EOF

sudo systemctl restart fail2ban
```

---

### [FIND-011] VNC 密碼檔殘留

| 欄位 | 內容 |
|------|------|
| **嚴重程度** | Medium |
| **CVSS v3.1 分數** | 4.3 |
| **CVSS 向量** | `CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:U/C:L/I:N/A:N` |
| **CWE 編號** | CWE-538：敏感資訊插入外部可存取的檔案 |
| **CVE 編號** | N/A |
| **受影響組件** | `/home/admin/.vnc/passwd` |
| **發現工具** | `git log --diff-filter=A -- .vnc/passwd` |

#### 修補建議
```bash
rm -rf /home/admin/.vnc
echo ".vnc/" >> /home/admin/.gitignore
```

---

### [FIND-012] 文件中暴露預設管理員密碼

| 欄位 | 內容 |
|------|------|
| **嚴重程度** | Informational |
| **CVSS v3.1 分數** | 3.7 |
| **CVSS 向量** | `CVSS:3.1/AV:N/AC:H/PR:N/UI:N/S:U/C:L/I:N/A:N` |
| **CWE 編號** | CWE-798：硬編碼憑證 |
| **CVE 編號** | N/A |
| **受影響組件** | `docs/APACHE-SETUP.md`、`docs/DEPLOYMENT.md` |
| **發現工具** | 文件審查 |

#### 描述
`admin@admin.com / admin123` 以明文寫在部署文件中。

#### 修補建議
將文件中的明文密碼替換為 `<請自行設定強密碼>` placeholder。

---

## 風險矩陣

```
         │ 低影響 │ 中影響 │ 高影響 │ 極高影響
─────────┼────────┼────────┼────────┼──────────
高可能性  │        │        │        │ FIND-001, 002
中可能性  │        │        │ FIND-008│ FIND-003, 004
低可能性  │ FIND-012│FIND-009│ FIND-005│ FIND-007
         │        │FIND-010│ FIND-006│
         │        │FIND-011│        │
```

---

## 修補優先計畫

### 已完成（2026-04-20 AI 自動修補）

| 項目 | 狀態 |
|------|------|
| [FIND-003] git-sync.sh 加入 pre-sync binary 掃描 | DONE |
| [FIND-004] `/api/fix-db` 加 env guard | DONE |
| [FIND-005] CORS 收緊至明確 origin | DONE |
| [FIND-006] 全域 API Rate Limit `throttle:200,1` | DONE |
| [FIND-007] Docker Compose 移除 PostgreSQL host port | DONE |
| GitHub push 修復 commit | DONE |

### 需要 sudo 權限（手動執行）

#### 立即處理（當日）

**1. [FIND-001] 啟用 UFW 防火牆**
```bash
sudo ufw --force enable
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 80/tcp   comment 'HTTP'
sudo ufw allow 443/tcp  comment 'HTTPS'
sudo ufw allow 22/tcp   comment 'SSH'
sudo ufw deny 1883/tcp  comment 'MQTT block'
sudo ufw deny 8883/tcp  comment 'MQTT-TLS block'
sudo ufw deny 5432/tcp  comment 'PostgreSQL block'
sudo ufw deny 11434/tcp comment 'Ollama block'
sudo ufw deny 5678/tcp  comment 'n8n block'
sudo ufw deny 445/tcp   comment 'Samba block'
sudo ufw deny 139/tcp   comment 'NetBIOS block'
sudo ufw reload
# 驗證
sudo ufw status numbered
```

**2. [FIND-002] SSH 強化**
```bash
# 變更密碼
passwd admin
passwd jeng

# 禁止密碼登入
sudo sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sudo sed -i 's/^#\?ChallengeResponseAuthentication.*/ChallengeResponseAuthentication no/' /etc/ssh/sshd_config
sudo systemctl restart sshd
# 驗證
sudo sshd -T | grep passwordauthentication
```

**3. [FIND-010] 啟用 fail2ban**
```bash
sudo tee /etc/fail2ban/jail.local <<'EOF'
[sshd]
enabled = true
port = ssh
maxretry = 3
findtime = 600
bantime = 3600

[apache-auth]
enabled = true
port = http,https
maxretry = 5
findtime = 600
bantime = 3600
EOF
sudo systemctl restart fail2ban
# 驗證
sudo fail2ban-client status
```

**4. [FIND-011] 移除 VNC 殘留**
```bash
rm -rf /home/admin/.vnc
echo ".vnc/" >> /home/admin/.gitignore
```

#### 短期處理（本週）

**5. [FIND-008] 停用不需要的服務**
```bash
# 若不需要 Samba
sudo systemctl disable --now smbd nmbd

# 若不需要 MQTT
sudo systemctl disable --now mosquitto

# Ollama 綁定 localhost
sudo mkdir -p /etc/systemd/system/ollama.service.d
sudo tee /etc/systemd/system/ollama.service.d/override.conf <<'EOF'
[Service]
Environment="OLLAMA_HOST=127.0.0.1"
EOF
sudo systemctl daemon-reload && sudo systemctl restart ollama
# 驗證
ss -tlnp | grep 11434
# 預期：僅 127.0.0.1:11434
```

**6. [FIND-009] Nginx 安全 header**

修改 `docker/nginx.conf`，在 `server` block 內加入：
```nginx
if ($http_x_forwarded_proto = "http") {
    return 301 https://$host$request_uri;
}
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

---

## 備份正常化驗證程序

凌晨 1 點 nightly backup 流程測試：

```bash
# 手動試跑
bash /home/admin/scripts/nightly-backup.sh

# 確認 log 正常結束
tail -10 /home/admin/backups/nightly-backup.log
# 預期最後一行：=== Nightly backup done ===

# 確認 SQL 備份存在且非空
ls -lh /home/admin/backups/alltrue_nightly_*.sql.gz | tail -3
# 預期：最新檔案 > 100KB

# 確認 git push 成功
git log --oneline origin/jerry-sync-main | head -3

# 確認安全掃描未誤報
grep -i 'security-abort' /home/admin/backups/nightly-backup.log | tail -5
# 預期：空
```

---

## 最終驗證清單

```
[x] GitHub jerry-sync-main 最新 commit 包含 security fix（fd5e589）
[x] find 掃描無可疑 binary
[x] /api/fix-db 回傳 404（env guard 已加）
[x] CORS 拒絕未授權 Origin
[x] 全域 Rate Limit 已啟用（throttle:200,1）
[x] Docker Compose PostgreSQL host port 已移除
[x] git-sync.sh 加入 pre-sync 掃描，測試假 binary 正確 abort (exit 99)
[x] 路由完整性測試通過（RouteRegistrationTest 8/8 pass）
[ ] UFW 防火牆已啟用（需 sudo）
[ ] SSH 密碼登入已禁用（需 sudo）
[ ] fail2ban jail 已啟用（需 sudo）
[ ] VNC passwd 已移除（需 sudo）
[ ] Samba/MQTT 停用（需 sudo）
[ ] Ollama 綁定 localhost（需 sudo）
[ ] Nginx 安全 header 已加入
[ ] 備份手動試跑成功
```

---

## 附錄 A — 工具版本

| 工具 | 用途 |
|------|------|
| `ss` (iproute2) | 開放端口掃描 |
| `last` (util-linux) | SSH 登入紀錄 |
| `file` (libmagic) | Binary 檔案類型偵測 |
| `whois` | IP 來源查詢 |
| `git log / show` | 入侵時間線重建 |

## 附錄 B — 掃描指令日誌

```
[2026-04-20 16:36:00] ss -tlnp | grep -v '127.0.0.1\|::1'  → 發現 7 個對外服務
[2026-04-20 16:36:05] last -30  → 發現 IP 31.56.209.39 於 Apr 18 登入
[2026-04-20 16:36:10] whois 31.56.209.39  → Pfcloud/SWISSNET（bulletproof hosting）
[2026-04-20 16:36:15] git log --diff-filter=A -- .UoQsjidhe*  → 惡意程式於 de03daf commit 加入
[2026-04-20 16:36:20] file .UoQsjidhe2CBXLdnWKmw9Yx4  → 已刪除，從 git history 確認為 ELF ARM binary 1.56MB
[2026-04-20 16:36:25] cat /etc/samba/smb.conf  → wide links=yes
[2026-04-20 16:36:30] cat /etc/mosquitto/mosquitto.conf  → 無認證限制
```

## 附錄 C — 術語表

| 術語 | 說明 |
|------|------|
| Mirai | 針對 IoT 裝置的 botnet 惡意程式，透過弱密碼掃描傳播，將設備變為 DDoS 攻擊節點 |
| CVSS | Common Vulnerability Scoring System，通用漏洞評分系統 |
| CWE | Common Weakness Enumeration，通用弱點列舉 |
| C&C | Command and Control，殭屍網路的指揮控制伺服器 |
| UFW | Uncomplicated Firewall，Ubuntu/Debian 系防火牆管理工具 |
| Bulletproof hosting | 不回應法律請求的匿名主機服務，常被攻擊者用作跳板 |
| HSTS | HTTP Strict Transport Security，強制瀏覽器使用 HTTPS |

---

*本報告版本：v1.0 | 參考標準：OWASP TG v4.2、NIST SP 800-115、CVSS v3.1*
