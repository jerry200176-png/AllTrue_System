---
owner: platform
review_cycle: quarterly
last_reviewed: 2026-06-07
---

# RUNBOOK — Rollback（回滾標準作業程序）

> **REFERENCE ONLY — NO DECISION OR EXECUTION AUTHORITY.**  
> Execution helper for FINAL_ACTION steps. Decision: INCIDENT stack (I3). Execution: `deploy.yml` (I1).
> 對應自動化：`.github/workflows/rollback-readiness.yml` + `scripts/rollback-readiness.sh`（非破壞性就緒度檢查，#733）。
>
> 前置認知：本系統部署是 **git-commit 為基礎**（非 Docker image）。
> Pi `/home/admin` 跟隨 `origin/main`；回滾 = 把 main 回到良好 commit，再讓 **`deploy.yml`**（`Deploy to Pi`）重佈。

---

## 0. 一頁速查（出事先看這格）

| 情境 | 最快動作 | 章節 |
|---|---|---|
| deploy 後 health/smoke 自己失敗 | **不用動**——`deploy.yml` 已自動回滾到上一 commit | §2 |
| 自動回滾沒救起來 / 已過一陣子才發現 | 開 **revert PR**（`git revert <hash>` → PR → merge → 自動重佈） | §3a |
| 站全掛、等不及 CI | 走 §3b **緊急 Pi 重佈**（re-run 上一個成功 deploy） | §3b |
| 資料被寫壞 / migration 有破壞性 | §3c **DB 回滾 + 還原備份**（先備份再動） | §3c |

⛔ 紅線：**禁止**直接 SSH 進 Pi 改程式碼（事故 B/C/E）。回滾一律走 git + **`deploy.yml`**。

---

## 1. 何時該回滾（判準）

立即回滾（不必先找根因）：

- `GET /api/v1/health` 非 `ok`，或全站 5xx
- 核心功能壞：RFID 刷卡 `POST /api/v1/swipe-rfid`、主任登入、今日排課、繳費提醒
- 資料正在被錯誤寫入（扣堂/繳費金額異常）→ 回滾 + §3c 評估資料修復

先修不回滾：單一非核心頁面樣式跑版、文案錯字 → 走正常 fix PR。

---

## 2. 自動回滾（`deploy.yml` 內建）

每次 **`Deploy to Pi`**（`.github/workflows/deploy.yml`）SSH 部署會：

1. 部署前記錄 `PREV_COMMIT=$(git rev-parse HEAD)`（回滾錨點）
2. migration 前先 `mysqldump` 到 `/home/admin/backups/emergency/`
3. 部署後跑 health check + `post-merge-smoke.sh`
4. **任一失敗 → 自動回滾**：`git reset --hard $PREV_COMMIT` → 前端重 build → `php artisan optimize` → 若本次跑過 migration 則 `migrate:rollback --step=1 --force` → 二次 health check

→ 多數情況**你什麼都不用做**。到 Actions 看該 deploy run log 確認「✅ Rollback 成功」即可。
若 log 出現「Rollback 後 health check 仍失敗 — 需要人工介入」→ 進 §3。

---

## 3. 手動回滾 SOP

### 3a. 程式碼回滾（正規路徑，首選）

適用：壞版已 merge 進 main、但站還活著（或自動回滾已把 Pi 拉回舊版，但 main 仍是壞的）。

```bash
# 在 WSL2 ~/alltrue
git fetch origin main && git checkout -b fix/rollback-<slug> origin/main
git revert --no-edit <壞掉的 merge commit hash>     # squash merge 為一般 commit，免 -m
# 若 revert 出衝突 → 手動解 → git revert --continue
git push -u origin HEAD
gh pr create --title "revert: 回滾 <壞功能>（hotfix）" --body "Closes/Refs #<issue>"
```

→ CI 綠 → merge → `deploy.yml` 自動把 production 重佈到 revert 後的良好狀態。
這條路徑可被 `scripts/rollback-readiness.sh` 的 CHECK 3 預先驗證（最新 commit 是否可乾淨 revert）。

### 3b. 緊急 Pi 重佈（站全掛、等不及 CI）

優先用「重跑上一個成功的 deploy run」而非手動 SSH：

```bash
# 找上一個 deployable 成功的 commit / run
gh run list --workflow="Deploy to Pi" --limit 10
# 對上一個成功的 commit 重新觸發部署（最安全：把 main 指到該 commit 走正規流程）
```

若連 GitHub Actions 都不可用，才走 `docs/DEPLOYMENT.md` 緊急手動前端部署路徑；
完成後**仍要補 PR/CI**，並在 `CHANGELOG` + `AI_REGRESSION_LESSONS` 記錄此例外（見 §B2 規則 12）。
⛔ 不在 Pi 直接編輯程式碼；緊急重佈也只做 `git fetch + reset 到良好 commit + optimize`（等同 `deploy.yml` 動作）。

### 3c. DB / Migration 回滾（資料層，最謹慎）

⚠️ 任何動 production DB 的動作**先讀** `docs/DANGEROUS_OPERATIONS.md` 並先備份：

```bash
TS=$(date '+%Y-%m-%d_%H%M%S')
mysqldump -h 127.0.0.1 -u admin -p"$(grep DB_PASSWORD /home/admin/backend/.env | cut -d= -f2)" \
  --single-transaction AllTrue | gzip > /home/admin/backups/emergency/db_pre_rollback_${TS}.sql.gz
```

- **schema 回滾**：`php artisan migrate:rollback --step=N --force`（N = 本次部署新增的 migration 數）。
  前提：每筆 migration 都有 `down()`——由 readiness CHECK 2 保證。
- **資料還原**：若資料已被破壞，從 `deploy.yml` 部署前的 `db_pre_migration_*.sql.gz` 或 sixhour 備份還原
  （事故 C 即靠 sixhour 備份救回）。還原前務必先做上面的當前備份，避免覆蓋。

---

## 4. MTTR 量測（Mean Time To Restore）

對標 DORA「還原服務時間」。**單次事件 MTTR** = 偵測到異常 → 恢復 health `ok` 的時間。

資料來源（皆免額外建置）：

- **偵測時間**：UptimeRobot 告警時間 / Sentry 首次錯誤 / `deploy.yml` 中 health check 失敗的時間戳
- **恢復時間**：
  - 自動回滾：同一 `deploy.yml` run 內「Rollback 成功」的時間戳（通常 < 5 分鐘）
  - 手動回滾：revert PR 的 merge → deploy 成功時間戳
- **彙總**：`dora-metrics.yml`（每週）已輸出 DORA 四指標；回滾事件記一行到 `docs/CHANGELOG.md`（`ops:` 類）便於月度 review（§Y）。

目標：自動回滾路徑 MTTR **< 5 分鐘**；手動 revert 路徑 **< 30 分鐘**（含 CI）。

---

## 5. 回滾就緒度檢查（非破壞性，#733）

`scripts/rollback-readiness.sh`（由 `rollback-readiness.yml` 每月 / 手動 / 改 deploy.yml 或 migration 的 PR 時跑）：

| 檢查 | 驗什麼 | 失敗代表 |
|---|---|---|
| CHECK 1 | `deploy.yml` 自動回滾區塊完整 | 有人改壞了自動回滾 |
| CHECK 2 | 全部 migration 有 `down()` | 加了不可逆 migration，`migrate:rollback` 會半途死 |
| CHECK 3 | 最新 main commit 可乾淨 `git revert`（sandbox abort） | 程式碼回滾會撞衝突，需人工 |
| CHECK 4 | DB 備份還原驗證 workflow 存在 | 資料層回滾沒有安全網 |

本機隨時可跑：`bash scripts/rollback-readiness.sh`。

---

## 6. 回滾演練 SOP（drill，零 production 風險）

常態演練 = 跑 readiness workflow，不碰 production：

```bash
gh workflow run rollback-readiness.yml          # 跑 4 項就緒度檢查
gh run watch <run_id>
```

若要演練「真的回滾一次」：**只在受控時段、且確認當下無使用者高峰**，
對一個 no-op commit（例如 CHANGELOG 一行）走 §3a revert PR 流程，觀察 `deploy.yml`
是否正確重佈 + health 恢復，並記錄 MTTR。⛔ 不在 production 故意打壞來演練。

---

## 7. 紅線（違反 = 可能二次破壞）

- ⛔ 不直接 SSH 改 Pi 程式碼；回滾走 git + `deploy.yml`
- ⛔ 動 DB 前一定先 `mysqldump` 備份（事故 C 教訓）
- ⛔ 還原備份前先備份「當前」狀態，避免覆蓋掉可能還需要的資料
- ⛔ 緊急手動操作後一定補 PR/CI + 記錄，禁止讓 main 與 production 長期不一致
