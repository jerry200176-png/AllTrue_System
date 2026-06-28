# 資安總覽

---

## 1. Mirai 入侵事件摘要（2026-04-18）

**整體風險：CRITICAL** — 攻擊者透過 SSH 弱密碼登入（IP `31.56.209.39`，荷蘭 bulletproof hosting），植入 Mirai 變種 ARM binary，被 nightly `git add -A` 捲入 Git。

### 時間線

| 時間 | 事件 |
|------|------|
| Apr 17 16:22 | `.vnc/passwd` 被建立 |
| Apr 18 07:04 | SSH 登入成功（5 分鐘）|
| Apr 18 14:40 | 惡意程式出現在 nightly commit |
| Apr 20 16:07 | 手動移除惡意程式 |
| Apr 20 16:36 | Push 修復 + 修補備份腳本 |

**根本原因**：開發環境直接轉為生產，未執行安全加固，防火牆未啟用。

---

## 2. 發現與修補狀態

### 已完成（AI 自動修補 2026-04-20）

| 項目 | CVSS | 狀態 |
|------|------|------|
| git-sync.sh 加入 pre-sync binary 掃描 | 9.0 | DONE |
| `/api/fix-db` 加 env guard（生產 404）| 8.6 | DONE |
| CORS 收緊至明確 origin | 7.5 | DONE |
| 全域 API Rate Limit `throttle:200,1` | 7.5 | DONE |
| Docker Compose 移除 PostgreSQL host port | 7.2 | DONE |

### 需 sudo 權限（手動執行）

**1. [FIND-001] 啟用 UFW 防火牆**（CVSS 9.8）
```bash
sudo ufw --force enable
sudo ufw default deny incoming && sudo ufw default allow outgoing
sudo ufw allow 80/tcp && sudo ufw allow 443/tcp && sudo ufw allow 22/tcp
sudo ufw deny 1883/tcp && sudo ufw deny 8883/tcp && sudo ufw deny 5432/tcp
sudo ufw deny 11434/tcp && sudo ufw deny 5678/tcp && sudo ufw deny 445/tcp && sudo ufw deny 139/tcp
sudo ufw reload && sudo ufw status numbered
```

**2. [FIND-002] SSH 強化**（CVSS 9.1）
```bash
passwd admin && passwd jeng
sudo sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sudo systemctl restart sshd
# 驗證：sudo sshd -T | grep passwordauthentication
```

**3. [FIND-010] 啟用 fail2ban**
```bash
sudo tee /etc/fail2ban/jail.local <<'EOF'
[sshd]
enabled = true
port = ssh
maxretry = 3
bantime = 3600
EOF
sudo systemctl restart fail2ban
```

**4. [FIND-011] 移除 VNC 殘留**
```bash
rm -rf /home/admin/.vnc && echo ".vnc/" >> /home/admin/.gitignore
```

**5. [FIND-008] 停用不需要的服務**
```bash
sudo systemctl disable --now smbd nmbd mosquitto
# Ollama 綁 localhost：
sudo mkdir -p /etc/systemd/system/ollama.service.d
echo -e '[Service]\nEnvironment="OLLAMA_HOST=127.0.0.1"' | sudo tee /etc/systemd/system/ollama.service.d/override.conf
sudo systemctl daemon-reload && sudo systemctl restart ollama
```

**6. [FIND-009] Nginx 安全 header**：在 `docker/nginx.conf` server block 加入 HTTPS redirect、X-Frame-Options DENY、HSTS 等。

---

## 3. 驗證清單

```
[x] find 掃描無可疑 binary
[x] /api/fix-db 回傳 404
[x] CORS 拒絕未授權 Origin
[x] 全域 Rate Limit 已啟用
[x] Docker PostgreSQL host port 已移除
[x] git-sync.sh pre-sync 掃描正常
[ ] UFW 防火牆已啟用（需 sudo）
[ ] SSH 密碼登入已禁用（需 sudo）
[ ] fail2ban jail 已啟用（需 sudo）
[ ] VNC passwd 已移除（需 sudo）
[ ] Samba/MQTT 停用（需 sudo）
[ ] Nginx 安全 header 已加入
```

---

## 4. Log 基礎設施資安

- log 策略變更需 `sudo`（root），tmpfs 設 `mode=0770,uid=www-data,gid=adm`
- SQL error log 發現含 PII（手機號碼、姓名 3 筆）→ 建議 Exception Handler mask 敏感參數
- 建議收緊權限：`sudo chown www-data:adm backend/storage/logs/*.log && sudo chmod 640 backend/storage/logs/*.log`

---

## 5. 資安報告撰寫指南

撰寫新的資安評估報告時，遵循以下骨架（詳見 git 歷史中的 `SECURITY_REPORT_AI_GUIDE.md`）：

1. 封面頁（目標、日期、測試人員）
2. 執行摘要（整體評級 + 各嚴重度數量）
3. 範圍與方法論（OWASP TG v4.2、CVSS v3.1）
4. 詳細發現（每項含 CVSS 向量、CWE、PoC、修補建議）
5. 風險矩陣
6. 修補優先計畫
7. 附錄（工具版本、掃描日誌）

---

## 6. PII：DB dump 誤入 repo（2026-06-06 發現與決策）

**發現**：3 個含 production PII 的 SQL dump 被 commit 進 repo——`docs/AllTrue_backup.sql`、`AllTrue (3).sql`（root）、`backend/storage/backups/prd-e-20260418-232201.sql`，含 `Student`/`StudentClass`/`StudentSingIn`/`Teacher` 等真實資料（姓名、RFID、LineID）。非憑證（dump 內無 `User`/password 表），故無「rotate」必要。

**已處置**：三檔皆 `git rm` 出 HEAD；新增 `.gitignore`：`*.sql`（保留 `!scripts/*.sql` 查詢腳本）+ `backend/storage/backups/`，防再犯（GitHub「prevent repeats」步驟）。

**歷史清除決策（風險取捨，對齊 GitHub 官方指引）**：GitHub 官方說 history rewrite 屬「heavy-handed」，憑證類 rotate 後常不必重寫；資料類雖只能靠重寫移除，但需權衡成本。本 repo 為 **private + 單一 committer + 無 fork/他人 clone**，且 `git filter-repo` 需 **force-push main**——而 force-push main 是本專案 **#1 P0 事故（事故 A，曾造成 production downtime）**，`deploy.yml` 綁 main、branch protection + enforce_admins 已開。結論：**暫不重寫歷史**，接受 private repo 的殘留風險（從 HEAD 移除 + 防再犯 + 本決策留檔）。**觸發重評**：repo 轉 public、新增協作者、或對外開源前，必須先做 filter-repo 清史（規劃維護窗 + 使用者明確批准，暫時關 branch protection）。

**2026-06-28 Secret Exposure Audit（再評）**：HEAD 曾含 live `.env.monitor`（Telegram token）與 769 個 `.cursor/projects/` 檔；歷史含 GitHub PAT 與 SQL dump blobs。**Verdict: NOT SAFE public** until `scripts/security-filter-repo.sh` + rotation checklist [`SECURITY_CREDENTIAL_ROTATION.md`](SECURITY_CREDENTIAL_ROTATION.md) complete.

**2026-06-28 Publication Safety Finalization（完成）**：

| Step | Status | Evidence |
|------|--------|----------|
| PR #1023 merged (HEAD clean) | DONE | `cc0a24b` — no `.env.monitor`, no `.cursor/projects/` |
| Credential rotation CEO sign-off | DONE | [`SECURITY_CREDENTIAL_ROTATION.md`](SECURITY_CREDENTIAL_ROTATION.md) §CEO sign-off |
| `git filter-repo` + force-push all branches/tags | DONE | Maintenance window 2026-06-28; branch protection restored |
| Second pass: `scripts/raspberry-pi/nppBackup/**` removed | DONE | Campus token backup purged from history |
| `scripts/security-gitleaks-audit.sh` (allowlisted CI/test/archive only) | PASS | 0 non-allowlisted findings on full history |
| GitHub secret scanning + push protection | See below | Enabled before public toggle |

**Verdict after completion: SAFE TO PUBLISH** (assuming rotation effective and no new leaks post-purge).

**Maintenance window SOP (executed 2026-06-28):**

1. Saved branch protection → `/tmp/bp-restore-purge.json`
2. `git clone --mirror` → `scripts/security-filter-repo.sh` (+ nppBackup second pass)
3. `git push --force --all && git push --force --tags`
4. Restored branch protection (8 required checks, `enforce_admins: true`)
5. Fresh clone attack simulation + gitleaks gate before visibility toggle

**Do not re-run filter-repo casually** — force-push main is P0 incident class (see `.cursorrules` 事故 A).

---

*本文件版本：v1.0 | 參考：OWASP TG v4.2、NIST SP 800-115、CVSS v3.1*
