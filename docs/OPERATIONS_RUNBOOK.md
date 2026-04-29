# Operations Runbook (SOP + Lessons Learned)

This runbook captures the practical SOP to keep AllTrue stable during development and deployment.

## A. Development SOP（2026-04-24 起：WSL2 本地開發）

> ⛔ 禁止 SSH 到 Pi 直接改程式碼。所有改動必須在 WSL2 `~/alltrue` 進行。

1. **開始前同步**（在 WSL2 終端機）：
   ```bash
   git checkout main && git pull origin main
   ```
2. **開 feature branch**：
   ```bash
   git checkout -b feat/或fix/功能名稱
   ```
3. **實作改動**，用 Cursor（WSL2 模式）編輯。
4. **Push 並開 PR**：
   ```bash
   git add . && git commit -m "feat/fix: 說明"
   git push origin feat/功能名稱
   # → GitHub 開 PR → 等 CI 通過 → merge
   ```
5. **PR merge 後**：`deploy.yml` 自動部署到 Pi，無需手動操作。
6. **驗證**：
   ```bash
   curl -sk https://daan.lifenet.com.tw/api/v1/health | python3 -m json.tool
   ```

6. **老師端（2026-04-12 起）**：預設首頁為 **教學工作台**（`teacher-home`）。部署含前端變更後，建議抽樣：**老師登入** → 工作台載入、跨分校本週課表、點「出勤／評量」導頁、側欄出缺勤**紅點**（當日有待點名 `scheduled` 堂次時）是否正常。

## B. GitHub SOP

- Main collaboration target is `main`.
- All feature/fix work must use short-lived branches (`feat/*`, `fix/*`, `chore/*`, `hotfix/*`).
- Backup branches are **not** for normal merge.
- If PR contains huge unrelated diffs or historical artifacts: close it, do not merge.

### B0. Dependabot PR Merge SOP

**觸發時機**：Dependabot 開 PR（npm / composer / GitHub Actions 版本更新）

**前置條件（缺一不可）**
1. `curl https://daan.lifenet.com.tw/api/v1/health` → `{"status":"ok"}`
2. `gh run list --workflow=deploy.yml --limit 3` → 最近 3 次全 success（代表備份正常）
3. `git log --oneline -3` → main 與 production 一致

**執行步驟**
```bash
# 1. 確認 PR 只改依賴版本，不碰 production 邏輯
gh pr view <PR_NUMBER> --json files -q '.files[].path'

# 2. Merge（PHPUnit fail 若只因 Dependabot 沒有 DB secret 屬正常）
gh pr merge <PR_NUMBER> --squash --delete-branch

# 3. 有 conflict → 讓 Dependabot 自動 rebase
gh pr comment <PR_NUMBER> --body "@dependabot rebase"
# rebase 完成後重跑步驟 2

# 4. 需要 workflow scope 時先執行
gh auth refresh -h github.com -s workflow
```

**⚠️ Dependabot PR 合併前仍須看 required checks**：目前 PR checks 主要由 WSL2 self-hosted runner 執行，不再以「Dependabot 無法讀 GitHub Secrets」作為 PHPUnit fail 的預設理由。若依賴更新失敗，先看 log；只有確認改動純依賴且失敗原因與專案無關時，才可依 Dependabot SOP 處理。

### B1. Branch Hygiene

**Policy**
- PR merged = branch deleted (local + remote).
- Branch lifetime target: 1-3 days.
- `backup-*` 分支：**只用於還原，不合併，不主動清除**（max 1-2 個）。
- Protect `main`（required checks + admin enforcement + no force-push/delete；單人 repo 暫不強制 approval，有第二位 maintainer 後再升級為 1 approval）。

**Automation（每週一至五 08:00 自動 dry-run）**  
GitHub Action `.github/workflows/branch-hygiene.yml` 每日跑報告，結果寫入 Actions Job Summary。

手動執行：
```bash
./scripts/branch-hygiene.sh            # 查看清單（不刪）
./scripts/branch-hygiene.sh --apply    # 刪除已合併分支（保留 backup-*）
```

**GitHub repo 設定（需手動開啟）**
- ✅ Settings → General → Auto-delete head branches after merge
- ✅ Settings → Branches → Branch protection rules on `main`

### B2. GitHub Actions Minutes Conservation SOP

**目的**：降低 GitHub-hosted runner minutes 消耗，同時維持「PR 綠燈才 merge、merge 後自動部署、部署後 health check」的安全底線。

**核心規則**
1. **PR 舊 run 自動取消**：CI / PHPStan workflow 必須使用 `concurrency`，同一 PR 新 commit 進來時取消舊 run，只驗最新 commit。
2. **Docs-only 不跑重 CI**：只改 `docs/**`、`.cursor/**`、Markdown 或計畫文件時，只跑 Presubmit；跳過 PHPUnit/MySQL、Vite build、PHPStan。
3. **Frontend-only 不跑後端測試**：只改 `frontend/**` 時跑 Vite build，不跑 PHPUnit/MySQL 與 PHPStan。
4. **Backend-only 不跑前端 build**：只改 `backend/**` 或 Composer 依賴時跑 PHPUnit/MySQL 與 PHPStan，不跑 Vite build。
5. **Workflow 改動保守全跑**：修改 `.github/workflows/**` 時，CI 必須保守跑完整前後端檢查，避免 path filter 失手。
6. **Docs-only merge 不部署**：`deploy.yml` 必須先偵測 main 最新 commit 是否含 `backend/**`、`frontend/**`、Composer 或 deploy workflow 變動；沒有 deployable diff 就跳過 production deploy。
7. **Production deploy 不取消**：部署 workflow 使用 `concurrency: production-deploy` 且 `cancel-in-progress: false`，避免中途取消造成半部署。
8. **禁止用 production Pi 省 CI minutes**：不得把 `/home/admin` production Pi 註冊為 PHPUnit/self-hosted test runner；也不得為省 minutes 在 Pi 上跑 `php artisan test` / `phpunit`。
9. **低風險 docs 小修先累積**：README footer 日期、錯字、單一連結、排版等不影響系統行為的小修，先保留在本地 docs batch；不要單獨開 PR 觸發 Actions。
10. **同類 docs 一次送出**：README 展示、FAQ、INDEX、Runbook、角色手冊等同日低風險文件修正，合併成一個 `chore/*` docs PR。
11. **避免混合 deployable diff**：純 docs batch 不混入 `backend/**`、`frontend/**`、`scripts/**`、`.github/workflows/**`，避免觸發重 CI 或 production deploy。
12. **Actions minutes 用完仍不可在 Pi 跑測試**：若 production bug 必須先救且 deploy workflow 無法使用，只能走 `docs/DEPLOYMENT.md` 的緊急手動前端部署路徑；完成後仍要補 PR/CI，並在 `CHANGELOG` + `AI_REGRESSION_LESSONS` 記錄本次例外。
13. **WSL2 self-hosted runner 只跑 CI**：`wsl2-jerry-alltrue` labels = `self-hosted, Linux, X64, wsl-ci, alltrue-ci`，只可用於 `ci.yml` / `presubmit.yml` / `codeql.yml`。`deploy.yml` 必須保留 GitHub-hosted runner，不可在個人電腦 runner 上持有 production deploy secrets 或執行部署。

**Token Conservation SOP**
- 先讀 `docs/INDEX.md`，再按任務讀對應章節；不要全讀大型文件或完整 transcript。
- 先用 `rg` / MemPalace 定位，再用 `ReadFile offset/limit` 讀小片段。
- 回答小問題先直接回答；只有要改程式、改流程或高風險操作時才展開完整 Phase。
- 重複 SOP 優先寫進既有文件或腳本，之後只引用路徑，不在每次對話重貼長流程。

**第一階段目標**
- docs-only PR：Actions job minutes 目標 `< 0.5 min`
- frontend-only PR：只消耗 Vite build + Presubmit
- backend-only PR：只消耗 PHPUnit/PHPStan + Presubmit
- 多次 push 同 PR：只保留最新 run

**第二階段才評估（若月用量仍過高）**
- 將 coverage 報告改為 nightly，PR 只跑 PHPUnit 無 coverage
- 將低頻維運排程降頻或移到外部監控
- 拆分 fast tests / full regression tests
- 已啟用獨立、非 production 的 WSL2 self-hosted runner；若月用量仍過高，再評估 fast/full test 分流或第二台 runner（不可與 Pi production 共用）。

### B3. Workflow Maturity Gates（AI + 大廠式工作流）

**目標**：讓任務在正確的流程重量中完成，避免小事浪費 Actions，也避免高風險改動被當成小修。

| Gate | 問題 | 未通過時 |
|---|---|---|
| Risk Tier | 這是 T0/T1/T2/T3 哪一級？ | 不進 DEV；先由 `[ORCH]` 補分級 |
| Contract | API/DB/UI/權限邊界是否清楚？ | 不開多 agent；先補 ARCH artifact |
| Ownership | 誰是 `[INT]`，誰負責最後整合？ | 不平行拆 PR |
| Safety | 是否碰 auth、PII、RFID、webhook、堂數/繳費、migration、備份/CI/CD？ | 加 SEC/OPS/DBA gate |
| CI Budget | 是否 docs-only？是否混入 deployable diff？ | 拆出 docs batch 或改成小 PR |
| Memory | 完成後要寫回哪裡？ | PR 說明列出 CHANGELOG / TECH_DEBT / AI_REGRESSION / SYSTEM_TECH_GUIDE |

**Tier 對應**
- T0：docs-only / 規則文字 / README 展示。只跑 docs 檢查，不混 `backend/**`、`frontend/**`、`scripts/**`、`.github/**`。
- T1：低風險單點程式碼。小 PR、局部測試或 build。
- T2：跨模組產品流程。需要 PLAN/ARCH、artifact handoff、Integration Owner。
- T3：安全、資料、部署、備份、權限、高風險金流/堂數。必須加 SEC/OPS/DBA，使用者批准後才做。

**Post-merge learning loop**
1. Merge 後確認 CI/deploy/health 或 docs-only skip 結果。
2. 將「有效做法」寫入 `AGENTS.md` / `OPERATIONS_RUNBOOK.md` / `SYSTEM_TECH_GUIDE.md`。
3. 將「踩坑與防再犯」寫入 `docs/AI_REGRESSION_LESSONS.md`。
4. 將「本次不修但會影響未來」寫入 `docs/TECH_DEBT.md`。
5. 只保存 distilled causality，不複製完整對話推理，避免記憶污染。

## C. Incident lessons (must remember)

From previous incidents:
- Mixed/unrelated git histories caused confusion and wrong merge targets.
- Laravel cache/config drift caused app to read outdated paths/config.
- Missing `server.php` or `public/.htaccess` broke API routing/runtime.
- Wrong DB credentials in `backend/.env` caused full API failure.
- Wrong file ownership in frontend build dirs caused deploy/build failures.
- `GET /api/v1/alerts/tuition` 曾被誤改成只回傳「未繳費」或只查堂數制，會漏掉「剩餘 <= 2 堂（含 0）」與**月結制**將近繳費日提醒。**完整規則務必維持與** `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` **一致**（改 API 前先讀該檔）。

**2026-04-10 事故教訓（分校選單 / 學生資料消失 / 全 API 500）**：
- `Campus.id` 在 DB 中不是從 1 連續排列（興隆=17, 新店=9, 大安=15, 木柵=16…），**任何修改分校 ID 前必須先查 DB**。
- `backend/public/branches.json` 是 API 失敗時的備援，其 ID 必須與 `Campus` 表一致，否則查詢帶錯 `CampusID` 讓資料看起來消失。
- `「資料消失」通常不是真的被刪除`：先查 DB 筆數 (`SELECT COUNT(*) FROM Student`)，再確認前端 `branch_id` 與真實 `Campus.id` 對應是否正確。
- **全 API 500 的第一步永遠是清 bootstrap cache**（見下方 H 節）。

**2026-04-10 事故教訓（剩餘堂數／月結繳費提醒消失）**：
- `GET /api/v1/alerts/tuition` 的契約不可縮減；**完整規則以** `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` **為準**，並與 `docs/AI_REGRESSION_LESSONS.md` 對照。
- 堂數制須含：`unpaid`、**`low_sessions`（`RemainingSessions <= 2`，含 0 堂）**；**不可只查 `ScheduleMode=count` 而漏掉月結 `date` + `settlement_day`**。
- `frontend/src/pages/DirectorDashboard.vue` 的「繳費提醒」依賴上述 API；縮減契約會讓畫面誤顯「無需催繳」。
- 修改 `AlertController` 或 `NotificationSyncService` 的提醒條件後，務必跑 `tests/Feature/TuitionAlertsApiTest.php` 並手動抽樣月結／堂數制。

## D. Recovery SOP (website looks old / API broken)

1. Confirm current code branch and latest commit:
   - `git status`
   - `git log -1 --oneline`
2. Check app routing essentials:
   - `backend/server.php`
   - `backend/public/.htaccess`
3. Clear Laravel cached files:
   - `backend/bootstrap/cache/*.php` (config/services/packages)
4. Verify DB credentials in `backend/.env`.
5. Confirm `deploy.yml` success and `backend/public/version.json` freshness.
6. If deploy workflow is broken and this is an incident, use the emergency manual frontend deploy path in `docs/DEPLOYMENT.md`.
7. Re-test web + API endpoints.

## E. Pre-merge checklist

- PR target branch is correct (`main`)
- No accidental artifacts (`dist`, cache, local binaries, temp files)
- Frontend changes have been deployed（PR merge 後 deploy.yml 自動處理）
- No critical regressions in login, students, scheduling, attendance, finance pages

## F. High-risk areas

- Session deduction / remaining sessions logic
- Subject-unit statistics output format
- Campus/branch data isolation
- Attendance and class-session relationship integrity

## G. SOP：分校設定異常（選單多 / 資料消失）

> 適用症狀：分校選單顯示超過 8 間、切換分校後學生/老師/課程變空白

1. **先確認 DB 真實 Campus.id**（最重要，勿臆測）：
   ```bash
   mysql -h 127.0.0.1 -u admin -padmin123 AllTrue \
     -e "SELECT id, name FROM Campus ORDER BY id; \
         SELECT CampusID, COUNT(*) as cnt FROM Student GROUP BY CampusID;"
   ```

2. **確認 `branches.json` ID 與 DB 一致**：
   ```bash
   cat backend/public/branches.json
   ```
   若有出入，直接依 DB 結果修正；正確格式（8 間）：
   ```json
   [
     {"id": 17, "name": "興隆分校", "code": "xinglong"},
     {"id": 9,  "name": "新店分校", "code": "xindian"},
     {"id": 15, "name": "大安分校", "code": "daan"},
     {"id": 16, "name": "木柵分校", "code": "muzha"},
     {"id": 2,  "name": "東湖分校", "code": "donghu"},
     {"id": 3,  "name": "大直分校", "code": "dazhi"},
     {"id": 4,  "name": "汐止分校", "code": "xizhi"},
     {"id": 1,  "name": "內湖分校", "code": "neihu"}
   ]
   ```

3. **確認 `useBranches.js` 的 `DEFAULT_BRANCHES` ID 與上方一致**：
   ```
   frontend/src/lib/useBranches.js  →  const DEFAULT_BRANCHES = [...]
   ```

4. **走 PR → CI → deploy.yml 自動部署**；若是事故且 deploy workflow 無法使用，才走 `docs/DEPLOYMENT.md` 的緊急手動部署流程。

5. **請使用者強制重新整理瀏覽器**（Ctrl+Shift+R / Cmd+Shift+R）。

---

## H. SOP：全 API 500 緊急恢復

> 適用症狀：所有 `/api/v1/*` 都回傳 HTTP 500，包含 `/branches`、`/auth/login`

**Step 1：查 Laravel 錯誤日誌**
```bash
tail -30 backend/storage/logs/laravel.log
```
常見關鍵字：
- `Class "..." not found` → 快取問題，執行 Step 2
- `SQLSTATE` / `Connection refused` → DB 連線問題，確認 `.env` 與 MySQL 狀態
- `No application encryption key` → `.env` APP_KEY 遺失

**Step 2：清 bootstrap cache（最常見修復）**
```bash
rm -f backend/bootstrap/cache/services.php \
       backend/bootstrap/cache/packages.php \
       backend/bootstrap/cache/config.php
```

**Step 3：驗證恢復**
```bash
curl -sk https://daan.lifenet.com.tw/api/v1/branches
# 預期：HTTP 200，回傳 JSON 陣列
```

**Step 4：若仍 500，依序檢查**
```bash
# DB 是否可連
mysql -h 127.0.0.1 -u admin -padmin123 AllTrue -e "SELECT 1"

# PHP-FPM 是否存活
ps aux | grep php-fpm | grep -v grep

# Apache 是否存活
sudo systemctl status apache2

# .env 是否存在
ls -la backend/.env
```

---

## I. CI/CD 自動部署設定（2026-04-24）

### 架構

```
WSL2 feature branch → git push → PR → CI pass → merge main → deploy.yml → Pi
```

### GitHub Secrets（必須存在）

| Secret | 說明 | ⚠️ 格式規則 |
|---|---|---|
| `PI_SSH_KEY` | deploy key 私鑰（base64 單行）| 值 = `base64 -w0 rpi_actions_deploy`，不含換行 |
| `PI_SSH_USER` | Pi SSH 帳號 | **只填 `admin`，禁止含 `@hostname`** |
| `PI_SSH_HOST` | Pi 主機名稱 | **只填 `pi.lifenet.com.tw`，禁止含 `user@`** |
| `PI_USER` | pi-health.yml 用帳號 | 同 PI_SSH_USER，只填 `admin` |
| `PI_HOST` | pi-health.yml 用主機名 | 同 PI_SSH_HOST，只填 `pi.lifenet.com.tw` |
| `CI_DB_PASSWORD` | 舊 GitHub-hosted MySQL service 測試密碼；目前 WSL2 self-hosted CI 改讀 `backend/phpunit.xml` 測試 DB 設定 | 若重新啟用 GitHub-hosted MySQL service 才需要 |

> ⛔ 格式錯誤後果：`PI_USER=admin@pi.lifenet.com.tw` → sshd 收到 username=`admin@admin` → Invalid user（2026-04-26 事故，見 R18）

### Pi authorized_keys

`/home/admin/.ssh/authorized_keys` 含以下兩把 key（**不可刪除**）：
- `rsa-key-20230629`（個人管理 key）
- `github-actions-deploy` — **原始 deploy key**，指紋 `SHA256:B/tQBHTLo7xlWnSmheXHe17PoxTrUUhknxte8cKP95g`

**原始 deploy key pair 位置**（Pi 本機）：
- 私鑰：`/home/admin/.ssh/rpi_actions_deploy`（⛔ 不可刪，是 PI_SSH_KEY 的來源）
- 公鑰：`/home/admin/.ssh/rpi_actions_deploy.pub`

**換 key SOP**（若需輪換）：
1. 在 Pi 產新 key：`ssh-keygen -t ed25519 -f ~/.ssh/rpi_actions_deploy -C "github-actions-deploy"`
2. 把公鑰加進 `authorized_keys`：`cat ~/.ssh/rpi_actions_deploy.pub >> ~/.ssh/authorized_keys`
3. 更新 GitHub Secret：`base64 -w0 ~/.ssh/rpi_actions_deploy | gh secret set PI_SSH_KEY`
4. 用 pi-health.yml 驗證連線成功後，才移除舊公鑰

### ✅ 部署通道修復記錄（2026-04-24 完成）

| 問題 | 根因 | 修法 |
|---|---|---|
| `Permission denied (publickey)` | `/home/admin` 權限 775，SSH StrictModes 拒絕 | `StrictModes no` 加入 sshd_config + `systemctl restart sshd` |
| GitHub Actions IP 被 fail2ban 封鎖 | 多次失敗 SSH 觸發 fail2ban | 解封 9 個 IP + 永久白名單 GitHub Actions IP 範圍（`jail.local`） |
| `Class Collision not found` → health 500 | `--no-dev` 無法乾淨移除舊 vendor dev 套件 | 移除 `composer install` 的 `--no-dev` flag |
| `git pull` divergent branches 卡住 | Pi 有 nightly auto-commit | 改為 `git fetch origin main && git reset --hard origin/main` |

**首次成功**：2026-04-24 14:17 TWN，`push → CI → deploy → health ok` 全流程驗證通過。

> 事故防再犯規則：`AI_REGRESSION_LESSONS.md` R7（SSH）、R8（composer）、R9（git pull）

### 驗證部署成功

GitHub Actions → Deploy to Pi → 最新 run 顯示 `success`  
或：`curl -sk https://daan.lifenet.com.tw/api/v1/health | python3 -m json.tool`

---

## I. Reference docs

- `README.md`
- `docs/INDEX.md`
- `AGENTS.md`
- `docs/AI_REGRESSION_LESSONS.md`
- `docs/DANGEROUS_OPERATIONS.md`

---

## J. Pricing Contract（避免每堂費用再次誤算）

**2026-04-10 回歸教訓（課程費用）**：
- `price_per_session` / `rate_per_30min` 在課程管理預設語意是「每堂費用」，不是每小時費用。
- 只有在 API 請求明確傳入 `rate_unit=hour` 時，才可使用時薪計價。
- 不可依 `day_time_slots` 是否有 `duration_minutes` 自動推斷成時薪，否則會把堂費再次乘上時數，造成總費用膨脹。

**開發檢查點（改排課/收費前必看）**：
1. 若是堂數制：`Charge = 每堂費用 × SessionCount`。
2. 若是時薪制（需明確 `rate_unit=hour`）：`Charge = 時薪 × 各堂時數總和`。
3. PR 必須包含至少一個 regression test，覆蓋「未傳 `rate_unit` 時維持堂費」情境。

---

## K. Attendance / Remaining Sessions / Subject Units SOP（2026-04-12 更新）

> 這段是**強制口徑**，後續人員與 AI 不可再混用邏輯。

### 1) 剩餘堂數唯一規則

- 堂數制課程：`RemainingSessions = SessionCount - UsedSessions`（`UsedSessions` 與 `SessionCount` 取 min cap）
- 月結制課程：`RemainingSessions` 恆 0，`UsedSessions` 依實際上課堂數累加
- **已上堂數（UsedSessions 口徑）**須與畫面上「已上」一致，取以下三者之**最大值**後再與購買堂數取 cap：
  1. 已扣點出缺勤：`StudentSingIn.SessionDeducted = 1`（DISTINCT 堂次）
  2. 排課堂次已結：`ClassSession.Status ∈ {completed, attended, late}`
  3. 無綁定堂次之 orphan 評量（歷史補登遺留）：`LearningRecord.Status=approved` 且 `ClassSessionID<=0`
- 另與 `SessionDeductionLedger`（attendance / retro_leave / status_adjust）取 max，避免漏帳
- **核准評量 = 點名的一種手段**：核准時透過 `ApprovalSessionSyncService::syncOnApprove` 建立 `StudentSignIn(Memo=lr_approve)`、更新 `ClassSession.Status=attended`、呼叫 `deductOnAttendance`，與手動點名走同一管線

### 2) 到班判定口徑

- 視為到班並扣堂：`present`, `late`
- 不扣堂：`absent`, `excused`, `leave`
- `late` 雖扣堂，但必須保留在出缺勤資料中供家長端查閱
- **核准評量視同 present 到班**

#### 2a) 曠改請假（`excused` + 既有 `ClassSessionID`）

- 老師／主任在出缺勤將某堂標為 **請假（`excused`）且對應既有堂次** 時：後端會寫入 **`schedules`（status=leave）** 並呼叫 **`CourseLeaveCascadeService::applyLeaveCascade`**，與課程管理／智慧排課的請假順延**同一套邏輯**；該筆 `StudentSingIn` 仍 **`SessionDeducted=0`**（該節不扣堂），但後續預排堂次可能前移並延長 `EndDate`。
- **禁止**在 `AttendanceController` 另寫一套順延而繞過 `CourseLeaveCascadeService`，以免與 `ScheduleController` 行為分歧。

### 3) 科目數（Subject Units）口徑

- 科目數只看評量審核結果（approved LearningRecord）與其加權規則
- 科目數與堂數是兩條獨立管線，不可互相回寫

### 4) 開發/改碼禁忌（給後續 AI）

- 核准評量時**必須**呼叫 `ApprovalSessionSyncService::syncOnApprove`，此為唯一的核准驅動扣堂入口；禁止在任何地方直接呼叫 `SessionDeductionService::deductForSession` 而不透過 `deductOnAttendance` 或 `syncOnApprove`
- 禁止**只**用 `SessionDeducted` 或**只**用 `approved LearningRecord count` 單一來源當 `UsedSessions`（必須與 `ClassSession` 已完成狀態一併對齊；實作見 `SessionDeductionService::batchObservedUsedSessions` / `recomputeCounters`）。無綁定堂次之 orphan 評量仍可依日期計入（補登遺留）。
- 若調整堂數計算，必須先檢查：
  - `AttendanceController`（手動點名）
  - `SwipeRfidController`（刷卡）
  - `SessionDeductionService`
  - `ApprovalSessionSyncService`（核准驅動扣堂）
  - `StudentClassController::index`（課程列表展示）

#### 4a) 老師 RFID 衝突歷史補登

修復前若同一 RFID 同時綁到學生與老師分校卡，老師刷卡可能被寫進 `StudentSingIn`，老師打卡區看不到。

1. 先 dry-run，不寫資料：
   ```bash
   php artisan teacher-signin:recover-rfid-collisions --date=YYYY-MM-DD --teacher-id=<User.id>
   ```
2. 確認候選列的老師、分校、時間正確，且已完成 production 備份後才可 apply：
   ```bash
   php artisan teacher-signin:recover-rfid-collisions --date=YYYY-MM-DD --teacher-id=<User.id> --apply
   ```
3. 此工具只新增 `TeacherSingIn`，不刪除、不作廢原始 `StudentSingIn`；原始學生列是否要 void 必須另案人工審核。

### 5) 課程管理堂次警示排查 SOP

營運/客服回報「排程列數與購買堂數不一致」時：

1. **確認課程 ID**（請回報者提供學生姓名 + 科目，從後台查 `StudentClass.ID`）
2. **查後端堂次分佈**：
   ```sql
   SELECT Status, COUNT(*) AS cnt
   FROM ClassSession
   WHERE StudentClassID = ?
   GROUP BY Status;
   ```
3. **計算有效堂次**：加總非 `cancelled/leave/leave_adjusted/excused` 的 `cnt`
4. **比對購買堂數**：`SELECT SessionCount FROM StudentClass WHERE ID = ?`
5. **判定**：
   - 有效 = 購買 → 前端未更新或快取，執行前端 deploy 並請使用者清瀏覽器快取
   - 有效 > 購買 → 真異常，檢查是否多排；聯繫工程修正
   - 有效 < 購買 → 若有 leave 列＝正常（待補課）；無 leave 列＝缺堂，檢查 `extendSessionsIfNeeded` 是否遺漏
6. **回報格式**：course_id / branch_id / 有效堂次 / 購買堂數 / 各狀態計數

**批次稽核查詢**（dry-run，唯讀）：列出所有「有效堂次 != 購買堂數」的進行中課程

```sql
SELECT
  sc.ID AS course_id,
  s.CampusID AS branch_id,
  sc.SessionCount AS purchased,
  COUNT(CASE WHEN cs.Status NOT IN ('cancelled','leave','leave_adjusted','excused') THEN 1 END) AS effective,
  COUNT(CASE WHEN cs.Status = 'leave' THEN 1 END) AS leaves,
  COUNT(CASE WHEN cs.Status = 'leave_adjusted' THEN 1 END) AS leave_adj,
  COUNT(CASE WHEN cs.Status = 'cancelled' THEN 1 END) AS cancelled,
  COUNT(CASE WHEN cs.Status = 'excused' THEN 1 END) AS excused,
  COUNT(*) AS total_rows
FROM StudentClass sc
JOIN Student s ON s.id = sc.StudentID
LEFT JOIN ClassSession cs ON cs.StudentClassID = sc.ID
WHERE sc.Stop = 0
  AND sc.SessionCount > 0
GROUP BY sc.ID, s.CampusID, sc.SessionCount
HAVING effective != purchased
ORDER BY branch_id, course_id;
```

---

## L. 效能優化上線操作（mobile-learning-lag-fix）

### 變更摘要

| 項目 | 新值 | 回退方式 |
|------|------|----------|
| badge 輪詢間隔 | 60s 統一，背景頁暫停 | `perfFlags.js` → `BADGE_POLL_INTERVAL: 25000` → rebuild |
| 評量頁 per_page | 50（含載入更多）| `.env` `PERF_LR_DEFAULT_PER_PAGE=200` |
| 學生/class-sessions per_page | 200 / 500 | `perfFlags.js` 改回舊值 → rebuild |
| 通知 sync | 每分校 5min throttle | `.env` `PERF_THROTTLE_NOTIF_SYNC=false` |
| 手機 backdrop-filter | 640px 以下停用 | 移除 `styles.css` 中 `MOBILE PERF RELIEF` 區塊 → rebuild |
| DB indexes | 4 組複合索引 | 需 DBA/OPS 批准後走 migration rollback 事故流程 |

### 後端回退（5 分鐘內）
本節是歷史效能任務的回退設計，不作為即時操作指令。實際回退請先讀
`docs/DANGEROUS_OPERATIONS.md`，再以 PR revert / feature flag / deploy workflow
處理；若涉及 production `.env` 或 migration rollback，需使用者明確批准與備份。

### SLO 門檻
| 端點 | P95 目標 | P99 上限 |
|------|----------|----------|
| `GET /api/v1/learning-records` | ≤ 1200ms | ≤ 2000ms |
| `GET /api/v1/notifications/unread-count` | ≤ 300ms | ≤ 600ms |
| `GET /api/v1/class-sessions` | ≤ 800ms | ≤ 1500ms |

### Go / No-Go
- **Go**：卡頓回報下降 ≥ 50%，無核心回歸
- **No-Go**：任一核心回歸，或 SLO 30 分鐘持續超標

---

## M. Log 管理與 Tmpfs 緩衝（2026-04-16）

### 1) 現況

- `laravel.log`：已改為 **daily rotation**（14 天保留），取代原本 `single`（永不輪轉）。
- `perf.log`：daily rotation，14 天保留（未改動）。
- 根檔案系統：NVMe SSD（`/dev/nvme0n1p2`），非 SD 卡。

### 2) Tmpfs 緩衝（選擇性啟用）

啟用高頻 log 記憶體緩衝，定時落盤，降低 I/O 負載：

```bash
sudo bash /home/admin/scripts/infra/setup-log-tmpfs.sh
```

- 掛載 128 MB tmpfs 於 `/var/log/alltrue-tmpfs`
- systemd timer 每 5 分鐘 flush 至 `backend/storage/logs/`
- 使用率 > 80% 自動降級為直接落盤

### 3) 回滾（< 5 分鐘）

```bash
sudo bash /home/admin/scripts/infra/rollback-log-tmpfs.sh
```

冪等操作：卸載 tmpfs → 停止 timer → 清理 fstab → 還原直寫。

### 4) 監控

- Health 端點：`GET /api/v1/health` → `log_pipeline` 區段
- systemd timer：`systemctl list-timers | grep alltrue`
- tmpfs 使用率：`df -h /var/log/alltrue-tmpfs`
- flush 日誌：`journalctl -u alltrue-log-flush.service`
- 告警紀錄：`journalctl -t alltrue-log`

### 5) 儲存介質盤點

```bash
bash /home/admin/scripts/infra/storage-inventory.sh
```

輸出根檔案系統來源、裝置型號與 SD 卡偵測結果。

### 6) 基線量測

```bash
bash /home/admin/scripts/infra/baseline-capture.sh
```

產出報告至 `docs/baselines/`，含 log 寫入量、API P95、記憶體狀態。

---

### 6) 上線前回歸檢查（必跑）

0. 新後端含出缺勤科目修正者：確認已跑 migration **`2026_04_12_200000_remap_orphaned_subject_ids`**（若環境有舊 Subject 主鍵殘留）；`GET /api/v1/attendance` 抽查 `subject_name` 非空列
1. 點名 `present` 後，`UsedSessions +1 / RemainingSessions -1`
2. 點名 `late` 後，`UsedSessions +1 / RemainingSessions -1`，且家長端可見「遲到」
3. 核准評量後：`RemainingSessions -1`（堂數制）、`UsedSessions +1`（月結制）、`ClassSession.Status=attended`、出缺勤不再列出待點名
4. 若已有獨立點名再核准：堂數不重複扣
5. 核准後再手動送出點名（POST attendance）：應回傳 409
6. 評量 rollback 後：堂數恢復、`ClassSession.Status=scheduled`（若無其他點名）；若有獨立點名，rollback 不影響獨立點名
7. 科目數統計隨評量審核變動，但不影響堂數

---

## N. LINE 課表回報推播設定（`staff_line_group_id`）（2026-04-18 新增）

### 背景

課表出入回報系統（`schedule-discrepancies`）在老師提交回報時，會自動推播 LINE 訊息至各分校的主任群組。推播使用 LINE Messaging API Push Message，需要：
1. 分校既有的 `messaging_channel_token`（LINE Bot Channel Access Token）
2. 新增的 `staff_line_group_id`（主任 LINE 群組的 Group ID）

Migration `2026_04_17_200001_add_staff_line_group_id_to_campus` 已新增此欄位至 `Campus` 資料表（nullable）。**未設定時，推播會靜默跳過**（不影響 API 回應與 in-app 功能）。

### 設定步驟

#### 1) 取得 LINE Group ID

1. 在 LINE 上為各分校主任建立（或使用既有）群組
2. 將 AllTrue LINE Bot 加入群組（需為群組成員）
3. 隨便在群組發一則訊息，Bot 會收到 Webhook 事件
4. 查看 `backend/storage/logs/laravel.log` 或 Webhook 日誌，找到：
   ```json
   { "type": "message", "source": { "type": "group", "groupId": "Cxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" } }
   ```
5. 複製 `groupId`（以 `C` 開頭，32 位英數字）

#### 2) 寫入資料庫

```bash
# 登入 MySQL（或使用 phpMyAdmin / Tinker）
cd /home/admin/backend

# 方法 A：Artisan Tinker
php artisan tinker
>>> DB::table('Campus')->where('id', <分校ID>)->update(['staff_line_group_id' => 'C你的GroupId'])

# 方法 B：直接 SQL
mysql -u root -p alltrue -e "UPDATE Campus SET staff_line_group_id='C你的GroupId' WHERE id=<分校ID>;"
```

#### 3) 驗證

```bash
# 確認欄位已寫入
php artisan tinker --execute="print_r(DB::table('Campus')->select('id','name','staff_line_group_id')->get()->toArray())"

# 觸發測試推播（在 Tinker 中）
php artisan tinker
>>> $d = \App\Models\ScheduleDiscrepancy::latest()->first();
>>> \App\Services\ScheduleDiscrepancyNotifier::notify($d);
```

#### 4) 未設定時的行為

若 `staff_line_group_id` 為空（或 `messaging_channel_token` 未設定），`ScheduleDiscrepancyNotifier` 會：
- 記錄 `INFO schedule_discrepancy.line_skip`（`reason: missing_group_id` 或 `missing_token`）至 `laravel.log`
- 靜默返回，**不影響 API 回應**（HTTP 200）
- in-app 儀表板仍正常顯示所有回報

#### 5) 排錯

| 症狀 | 檢查點 |
|---|---|
| LINE 收不到訊息（無 log） | 確認 `staff_line_group_id` 不為空；確認 Bot 在群組內 |
| log 出現 `line_4xx: status=403` | `messaging_channel_token` 已過期或沒有 push 權限，至 LINE Dev Console 確認 |
| log 出現 `line_4xx: status=400` | `groupId` 格式錯誤或 Bot 不在群組中 |
| log 出現 `line_failed` | 三次重試均失敗（5xx / 429），屬 LINE 服務端問題；in-app 仍正常，無需額外處理 |
| `test_submit_succeeds_even_without_line_config` 失敗 | 代表 Notifier 例外未被 try/catch 正確吸收，為嚴重回歸，立刻查 `ScheduleDiscrepancyNotifier` |

#### 6) 注意事項

- `staff_line_group_id` 是**分校級**設定，每個分校一個群組
- 若分校沒有 LINE Messaging API（`messaging_channel_token`），推播功能無效（已在 OQ-05 P1 列入下一 Sprint：建立 UI 設定頁讓主任自助填入）
- LINE Notify 已於 2025-03-31 下線，此系統使用 LINE Messaging API Push Message（不同 API，需 Bot Channel Token）
- LINE Push 失敗**不阻擋**課表出入回報的提交與處理流程


## O. Secret Rotation Policy（2026-04-25）

**原則：任何 secret 外洩跡象 → 當天輪換，不等。定期輪換每 90 天。**

### 1. Secret 清單

| Secret 名稱 | 位置 | 輪換方式 | 影響範圍 |
|---|---|---|---|
| `DB_PASSWORD` | Pi `backend/.env` + GitHub Secrets | MySQL FLUSH PRIVILEGES + .env 更新 + deploy | 後端 API 全部 |
| `APP_KEY` | Pi `backend/.env` | `php artisan key:generate` + .env 更新 + 重啟 | Session（全員登出）|
| `PI_SSH_KEY` | GitHub Secrets | `ssh-keygen` 新 keypair，更新 Pi `.authorized_keys` + GitHub Secrets | CI/CD 部署 |
| `LINE_CHANNEL_SECRET` | Campus table / `.env` | LINE Dev Console 重新簽發 + 更新 DB | LINE Webhook 驗簽 |
| `LINE_MESSAGING_TOKEN` | Campus table | LINE Dev Console 重新簽發 + 更新 DB | LINE Push 推播 |
| `SENTRY_DSN` | `.env.production` (前端 Vite inject) | Sentry 建新 DSN，舊 DSN 停用 | 前端錯誤報告 |

### 2. 輪換 SOP（以 DB_PASSWORD 為例）

DB password 輪換屬高風險操作。執行前需先讀 `docs/DANGEROUS_OPERATIONS.md`、
確認備份與回復方案，並由使用者明確批准；完成後以 `config:cache` / `route:cache`
重建快取並驗證 health，禁止用 debug 目的執行 `config:clear` / `cache:clear`。

### 3. 外洩應急（< 1 小時內完成）

1. 立即輪換（不用等排程）
2. 確認 Git log 是否有誤 commit（若有：`git filter-repo` 或 GitHub Support）
3. 稽核過去 7 天 access log 確認是否被濫用
4. 更新 `docs/AI_REGRESSION_LESSONS.md` 記錄事件

### 4. 提醒機制

每 90 天在 GitHub Issues 手動建立「Secret rotation reminder」milestone issue，指派給 `jerry200176-png`。

## P. 工程成熟度現況（2026-04-25 評估）

> 對標業界標準（中小型 SaaS，非 FAANG 規模），excluding staging 環境。
> 成熟度缺口與 GitHub issue 對照表見 `docs/ENGINEERING_MATURITY_GAPS.md`。本節保留長期基線與操作規則；issue 是執行追蹤來源。

### ✅ 已達標

| 項目 | 實作方式 |
|---|---|
| CI/CD 自動部署 | GitHub Actions `deploy.yml` → Pi auto-deploy |
| PR template | `.github/pull_request_template.md` |
| CODEOWNERS | `.github/CODEOWNERS`（高風險模組自動 request review）|
| PHPStan 靜態分析 | `codeql.yml` → phpstan level 5 |
| API smoke test | `deploy.yml` 驗 health + branches + swipe-rfid |
| Coverage gate | CI 70% 門檻（warning），目標 80% |
| Branch protection | GitHub Pro 已啟用 main 保護：required checks + admin enforcement + 禁止 force push/delete；單人 repo 暫不強制 approval，避免審核死鎖 |
| Rate limiting | 所有公開端點（swipe-rfid 30/min, login 5/hr）|
| 前端錯誤監控 | Sentry（`@sentry/vue`）+ GitHub issue 自動建立 |
| Uptime 監控 | UptimeRobot 每 5 分鐘（主站 + /health）|
| Pi 健康 alerting | `pi-health.yml` 每 6h：磁碟/溫度/備份年齡 |
| 週期慢查詢報告 | `slow-query-report.yml` 每週一 |
| 6 小時自動備份 | Pi cron → `/home/admin/backups/sixhour/` |
| Secret rotation policy | OPERATIONS_RUNBOOK.md §O（90 天輪換）|
| commitlint | `commit-msg` hook 強制 Conventional Commits |
| pre-push 保護 | 禁止直接 push main |
| Dependabot | Actions + npm 依賴自動升版 PR |
| RFID + Auth 資安 | 事故 A-F 復盤 + ParentPortal 跨家庭修復（R18）|

### ✅ 2026-04-25 補充（大公司標準）

| 項目 | 實作方式 |
|---|---|
| 備份還原驗證 | `backup-restore-test.yml` 每月 1 日自動還原驗資料表筆數 |
| 備份 manifest | `gdrive-backup-sync.sh` 每次同步產生檔名 / 大小 / sha256 manifest 並同步到 Google Drive |
| Migration dry-run | CI `migrate --pretend` 在 merge 前捕捉 SQL 錯誤 |
| JSON 結構化 logging | `logging.json` channel（warning+），為 ELK/Loki 預留 |
| DORA metrics | `dora-metrics.yml` 每週一自動計算部署頻率/lead time/CFR |

### ⚠️ 刻意不做（P3，這個規模 overkill）

| 項目 | 原因 |
|---|---|
| Staging 環境 | Pi 單機，維護成本 > 效益（使用者明確排除）|
| 分散式追蹤（OpenTelemetry）| 單一服務，Sentry 已夠 |
| Log 聚合（ELK/Loki）| Pi 規模，`tail -f laravel.log` 夠用 |
| WAF | Nginx 基本防護 + rate limiting 已涵蓋 80% |
| DB read replica | 流量規模不需要 |
| CDN | 靜態資源小，Nginx 快取足夠 |
| Chaos engineering | 4 校區補習班，非必要 |
| Feature flags | 規模不需要 |

### 🟢 2026-04-27 補強完成（GitHub Pro + Drive 備份）

| 項目 | 說明 | 狀態 |
|---|---|---|
| Branch protection rules | `main` 已啟用 required status checks、admin enforcement、禁止 force push/delete、conversation resolution；單人 repo 暫不強制 approval，等有第二個 maintainer 再升級為 1 approval | Done |
| Code backup | GitHub Pro remote repository + protected `main` + PR history；禁止把 Pi local backup branch 當主備份來源 | Done |
| Data backup | Pi 本機 nightly + sixhour + Google Drive offsite；同步 manifest 記錄 sha256，月度 restore drill 驗證可還原 | Done |

### 🟡 仍需規劃（P1/P2，不在本次直接改 production）

| 項目 | 目前口徑 | 下一步 |
|---|---|---|
| RPO / RTO | 目前可承諾：RPO ≤ 6 小時（sixhour + Drive），RTO 目標 ≤ 30 分鐘（依近幾次還原經驗）| 每季演練一次「從 Drive 取備份 → 還原到 drill DB → 驗核心表 row count」 |
| MySQL PITR / binlog | 尚未啟用明確的 point-in-time recovery；若事故發生在兩次 sixhour 中間，仍可能損失數小時資料 | 登記 TD-015，另走 DBA/OPS 流程評估 binlog retention、磁碟壓力、restore SOP |
| Full server DR | 目前有 DB 備份與 GitHub code backup，但沒有完整記錄「全新 Pi 從零重建到可服務」耗時 | 每半年做一次 tabletop drill，驗證 secrets、rclone、nginx、PHP/MySQL、deploy key 都可重建 |

### Backup / Code Backup 不可破壞規則

- **Code source of truth**：GitHub protected `main` + PR history。Pi `/home/admin` working tree 是 deploy target，不是 code backup 來源。
- **DB backup minimum**：本地 nightly、sixhour、monthly + Google Drive offsite + manifest + monthly restore drill。
- **Restore drill target**：只能還原到 drill/test DB；不得用 production `AllTrue` 做演練。
- **事故恢復順序**：先確認 GitHub commit / backup manifest / health check，再做最小 rollback；禁止用舊工作樹覆蓋後直接 commit。
- **回報「備份正常」條件**：不是只看到 `.sql.gz` 存在，還要能確認 Drive 同步與最近一次 restore drill 成功。

### Branch Protection 稽核指令（每月或重大事故後）

```bash
gh api repos/jerry200176-png/AllTrue_System/branches/main/protection \
  --jq '{checks: .required_status_checks.contexts, reviews: .required_pull_request_reviews.required_approving_review_count, admins: .enforce_admins.enabled, force_pushes: .allow_force_pushes.enabled, deletions: .allow_deletions.enabled, conversations: .required_conversation_resolution.enabled}'
```

期望：
- `reviews` = null（單人 repo 暫不強制；有第二個 maintainer 後改為 1）
- `admins` = true
- `force_pushes` = false
- `deletions` = false
- `conversations` = true
- `checks` 至少包含 `Presubmit Checks`、`PHPUnit Feature & Unit Tests`、`Vite Frontend Build`、`PHPStan (php)`

