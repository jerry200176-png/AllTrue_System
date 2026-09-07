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
> Pi `/home/admin` 跟隨 `origin/main`；`deploy.yml` 設有嚴格的 **exact-main 閘門**（`target_sha == current main SHA`）。
> 因此：**不支援**直接用歷史 SHA 跑 `workflow_dispatch` 或 re-run 過去的 deploy run。
> 生產部署完成後的回滾（Post-success rollback）= **建立 revert commit 推進 main → main CI 通過 → Founder 批准之 exact-main `deploy.yml` 部署**。

---

## 0. 一頁速查（出事先看這格）

| 情境 | 最快動作 | 章節 |
|---|---|---|
| deploy 執行中 health/smoke 失敗 | **不用動**——`deploy.yml` 內建自動回滾（重設回 `PREV_COMMIT`） | §2 |
| deploy 成功後才發現異常 / 壞版已上線 | 開 **revert PR** → merge 到 main → main CI 綠 → Founder 批准 exact-main `deploy.yml` | §3a |
| 欲輸入歷史 SHA 或 re-run 舊 deploy？ | **不支援**——`deploy.yml` 嚴格限制 `target_sha == current main`，歷史 SHA dispatch 會 fail-closed | §3b |
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

### 3a. 程式碼回滾（支援之正規路徑，首選）

適用：壞版已 merge 進 main 並完成部署，需要將 production 程式碼回退到先前的良好狀態。

> **核心機制**：因為 `deploy.yml` 強制驗證 `target_sha == current main SHA`，且 GitHub Ruleset 禁止 force-push，回滾必須透過建立「revert commit」前進 main，走正規部署路徑重佈。

步驟：
1. **建立 revert 分支與 commit**（在安全 task worktree 內操作，見 `docs/governance/WORKTREE_POLICY.md`）：
```bash
git fetch origin main && git checkout -b fix/rollback-<slug> origin/main
git revert --no-edit <壞掉的 merge commit hash>     # squash merge 為一般 commit，免 -m
# 若 revert 出衝突 → 手動解衝突 → git revert --continue
git push -u origin HEAD
gh pr create --title "revert: 回滾 <壞功能>（hotfix）" --body "Closes/Refs #<issue>"
```
這條路徑可被 `scripts/rollback-readiness.sh` 的 CHECK 3 預先驗證（最新 commit 是否可乾淨 revert）。

2. **Merge 到 main 並等待 main CI**：
   - 通過 PR CI checks 後，依照合規流程 squash-merge 回 `main`。
   - 取得 merge 後在 `main` 上的全新 commit SHA（記為 `$REVERT_SHA`）。
   - 等待 `main` 上的 `CI — PHPUnit Tests` 成功跑完（`deploy.yml` 要求 target SHA 必須有成功的 main CI 記錄）。

3. **觸發 Production 部署（Founder-approved exact-main deployment）**：
   - 若為 T1（auto-deploy 模式）：`deploy.yml` 會在 main CI 成功後由 `workflow_run` 自動觸發並部署 `$REVERT_SHA`。
   - 若為 T2/T3 或處於 `merged-awaiting-activation`：由 Founder 透過 `workflow_dispatch` 執行 exact-main 啟動：
```bash
gh workflow run deploy.yml \
  --ref main \
  -f phase=application-deploy \
  -f target_sha="$REVERT_SHA" \
  -f confirm="ACTIVATE_PRODUCTION:$REVERT_SHA"
```

4. **驗證回滾結果**：
```bash
curl -fsS https://daan.lifenet.com.tw/api/v1/health
curl -fsS https://daan.lifenet.com.tw/version.json
```
確認 `version.json` 的 `build_sha` 為 `$REVERT_SHA`，且 health 為 `ok`。

### 3b. 為什麼不能「re-run 舊 deploy」或「workflow_dispatch 歷史 SHA」？

許多工程師在出事時直覺想「找上一個成功的 GitHub Actions run 點 re-run」或「在 `deploy.yml` 的 `target_sha` 輸入上一版的 commit hash」。**這兩者在 AllTrue 系統中都會被嚴格阻擋並失敗：**

1. **`resolve-target` 閘門攔截**：
   `deploy.yml` 的 `resolve-target` 步驟會呼叫 GitHub API 比對目前 `main` 的最新 commit：
   ```bash
   MAIN_SHA="$(gh api "/repos/${REPO}/git/ref/heads/main" --jq '.object.sha')"
   if [[ "$TARGET_SHA" != "$MAIN_SHA" ]]; then
     echo "::error::Manual activation must target the current main SHA; requested ${TARGET_SHA}, current ${MAIN_SHA}."
     exit 1
   fi
   ```
   若輸入歷史 SHA，workflow 在前 30 秒內就會直接 `exit 1` 失敗，根本不會進入部署階段。

2. **`Final exact-main gate before production executor` 攔截**：
   即使 re-run 過去的 workflow_run，在進入 production executor 前仍會再次查驗 GitHub API，確認目標仍是當前 main HEAD；若 main 已推進，舊 run 會被判定為 stale target 立即中斷。

3. **Pi 部署腳本遠端防線**：
   Pi 上的 deploy 執行器亦會檢驗 `origin/main == TARGET_SHA`。若 main 在 activation 後前進，部署立刻中止。

**為何維持此一嚴格限制（不放寬 exact-main gate）：**
- 防止 production 與 git 歷史分岔（lineage drift）。
- 防止跳過 CI 測試直接覆蓋生產環境。
- 確保所有生產狀態都能在 `main` 完整追溯，且受 GitHub ruleset 保護。

因此：**發生線上事故時，唯一支援的標準程式碼回滾路徑就是 §3a（revert commit → merge to main → main CI 綠 → Founder 批准 exact-main deploy）**。

若連 GitHub Actions 基礎設施本身完全癱瘓不可用：
才得在 Founder 明確授權下參考 `docs/DEPLOYMENT.md` 與 `docs/DANGEROUS_OPERATIONS.md` 走極端離線維運程序，且恢復後仍**必須立即補齊 PR/CI 與 CHANGELOG 記錄**（見 `OPERATIONS_RUNBOOK.md` §B2 規則 12）。⛔ 嚴禁在 Pi 上直接編輯程式碼。

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
