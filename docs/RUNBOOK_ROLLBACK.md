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
| deploy 後 health/identity/smoke 失敗，summary 寫「Automatic rollback ran」（exit 10） | **不用動**——`deploy.yml` 已自動回滾到上一 commit 並重驗 | §2 |
| summary 寫 exit 11／12／13 或 SSH 斷線 | **要動**——沒有自動回滾（或回滾沒驗過），壞版可能仍在線上；立刻查 `/api/health`、`deployment.json`，照 §3 | §2、§3 |
| 自動回滾沒救起來 / 已過一陣子才發現 | 開 **revert PR**（`git revert <hash>` → PR → merge → 自動重佈） | §3a |
| 站全掛、等不及 CI | 走 §3b **緊急部署判斷**（仍只用 current-main target SHA） | §3b |
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
3. 部署後由 `scripts/post-deploy-verify.py gate` 做**有上限的重試驗證**（約 1 分鐘）：`/api/health`、`/api/v1/health` 回 200，且 `deployment.json` 的 `backend_sha` = 目標 SHA（前端有變動時 `frontend_sha` 也要相符），通過後才跑 `post-merge-smoke.sh`
4. **驗證或 smoke 失敗 → 自動回滾**（exit 10）：`git reset --hard $PREV_COMMIT` → 前端重 build → `php artisan optimize` → FPM refresh → health check + 以重試驗證 `deployment.json` = `PREV_COMMIT`（前端有變動時含 frontend SHA），若是驗證/smoke 觸發的回滾再對舊版重跑同一份 smoke；回滾本身沒驗過 → exit 11，需人工
5. **部署範圍含 migration、本次跑過 migration、或驗證器自己出錯無法判斷時，不自動回滾**（exit 12）：run 標紅並發 `::error::`，production 留在目標版，**`deploy.yml` 已不再自動執行 `migrate:rollback`**（任何階段）；照 §3 人工處理（migration 前的 `mysqldump` 在 `/home/admin/backups/emergency/`）。migration 步驟之後、驗證之前的步驟（前端 build、manifest、optimize、DB probe、FPM）失敗時，只要部署範圍含 migration（或 git 無法判斷）也一樣不回滾（exit 13，runtime 狀態未知）。migration 本身失敗（D1）仍照舊回滾 code
   - **例外（Founder 2026-10-14（三））**：範圍內的 migration **全部**是新增檔、且只加表／可為 null 或有預設值的欄位／一般索引時（以**目前 production 版本**裡的 `autonomy_gate.additive_migration_paths` 判斷，不用目標版自己的），舊 code 在新 schema 上仍可運作，所以照第 4 點自動回滾 code（exit 10／11），新增的表／欄位留在 DB，仍**不**執行 `migrate:rollback`。判斷不了（git 失敗、舊版沒有分類器、任何一個 migration 不合格）一律當成不合格，照上面不回滾。 這次執行到的 pending migration 若有不是本範圍新增的（例如前一次部署遺留），也照上面不回滾。回滾後要修 migration，一律**新增**一個 migration 檔修正，不要改已經跑過的那個檔（Laravel 依檔名記錄，改了也不會重跑）。
   - **部署後觀察期（gap #3，2026-10-21）**：驗證 + smoke 通過後，同一個 `gate` 再觀察 **20 分鐘**：每 30 秒查 health + `deployment.json` 身分，**連續 4 次**失敗即判定異常；若 secret `SENTRY_WATCH`（`<org>/<project> <唯讀 token>`）有設定，每 5 分鐘比對 Sentry error 數與部署前一小時基準（≥20 筆且速率 > 5 × max(基準, 0.2/分)）即判定異常。異常走**和驗證失敗完全相同**的回滾／hold 判斷（第 4、5 點），不另開回滾路徑。Sentry 未設定或查詢失敗只略過、不回滾，step summary 以 allowlist outcome 區分未設定、設定格式錯誤、baseline 不可得與觀察中查詢降級，只有完整成功才寫 passed。Deploy job 因此 timeout 55 分鐘，且觀察期間佔用 production side-effect concurrency（下一個 deploy 排隊等）。
6. Runner 另外從 GitHub 端重查 `/api/health`、`/api/v1/health` 與 `deployment.json`（唯讀）；失敗時 step summary 寫明是哪一種結果（10/11/12/SSH 斷線），並由 `deploy-incident` job 對 failure／cancelled 開或續寫一張「Production deploy incident (deploy.yml)」issue（只含 SHA／run／rc 與固定 outcome）。遺失 rc、取消／timeout、checkout 失敗一律明確記為 UNKNOWN，不推定 rollback 成功。SSH stdout 的單一固定 JSON marker 僅傳有限 watch／Sentry outcome；缺失、重複或格式錯誤不得記為 passed。
   - **Draft／hold（未完成的 P1）**：main `60507093` 尚無 `deploy_freeze_reason`；本 PR 的 incident 記錄不會實施 automatic activation freeze。#4359 未合併，不能把它當成已啟用的保護。這個狹窄修補不變更 activation，須保留 hold 至 freeze 邊界獨立解決及 T3 核准。

→ 多數情況**你什麼都不用做**。到 Actions 看該 deploy run log 確認「✅ Rollback 成功」即可。
若 log 出現「Rollback 後 health check 仍失敗 — 需要人工介入」→ 進 §3。

---

## 3. 手動回滾 SOP

### 3a. 程式碼回滾（正規路徑，首選）

適用：壞版已 merge 進 main、但站還活著（或自動回滾已把 Pi 拉回舊版，但 main 仍是壞的）。

```bash
# 在安全 task worktree（見 docs/governance/WORKTREE_POLICY.md）
git fetch origin main && git checkout -b fix/rollback-<slug> origin/main
git revert --no-edit <壞掉的 merge commit hash>     # squash merge 為一般 commit，免 -m
# 若 revert 出衝突 → 手動解 → git revert --continue
git push -u origin HEAD
gh pr create --title "revert: 回滾 <壞功能>（hotfix）" --body "Closes/Refs #<issue>"
```

→ CI 綠 → merge → `deploy.yml` 自動把 production 重佈到 revert 後的良好狀態。
這條路徑可被 `scripts/rollback-readiness.sh` 的 CHECK 3 預先驗證（最新 commit 是否可乾淨 revert）。

### 3b. 緊急部署判斷（站全掛、等不及 CI）

先走 revert PR；若 Founder 已核准手動 activation，仍只能以目前 `main` 的
target SHA 走 `deploy.yml`，不可重跑歷史 SHA：

```bash
# 取得目前 main 與 deploy 證據
gh api repos/:owner/:repo/git/ref/heads/main --jq '.object.sha'
gh run list --workflow="Deploy to Pi" --limit 10
# 只有 current-main target SHA + 正規 activation confirmation 才可 dispatch
```

若連 GitHub Actions 都不可用，停在升級/復原程序，不以 SSH 直接改 production
程式碼，也不宣稱已部署；恢復後仍須補 PR/CI 與 deployment evidence。

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
