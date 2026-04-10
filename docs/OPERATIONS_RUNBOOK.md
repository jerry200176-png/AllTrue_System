# Operations Runbook (SOP + Lessons Learned)

This runbook captures the practical SOP to keep AllTrue stable during development and deployment.

## A. Development SOP

1. Sync first:
   - `git checkout main`
   - `git pull`
2. Implement changes in focused commits.
3. If frontend changed, deploy build:
   - `cd frontend && npm run deploy`
4. Verify:
   - frontend opens
   - key API endpoint responds
5. Sync to GitHub:
   - `./scripts/git-sync.sh "feat/fix: message"`

## B. GitHub SOP

- Main collaboration target is `jerry-sync-main`.
- Feature branches should PR into `jerry-sync-main`.
- Backup branches are **not** for normal merge.
- If PR contains huge unrelated diffs or historical artifacts: close it, do not merge.

## C. Incident lessons (must remember)

From previous incidents:
- Mixed/unrelated git histories caused confusion and wrong merge targets.
- Laravel cache/config drift caused app to read outdated paths/config.
- Missing `server.php` or `public/.htaccess` broke API routing/runtime.
- Wrong DB credentials in `backend/.env` caused full API failure.
- Wrong file ownership in frontend build dirs caused deploy/build failures.

**2026-04-10 事故教訓（分校選單 / 學生資料消失 / 全 API 500）**：
- `Campus.id` 在 DB 中不是從 1 連續排列（興隆=17, 新店=9, 大安=15, 木柵=16…），**任何修改分校 ID 前必須先查 DB**。
- `backend/public/branches.json` 是 API 失敗時的備援，其 ID 必須與 `Campus` 表一致，否則查詢帶錯 `CampusID` 讓資料看起來消失。
- `「資料消失」通常不是真的被刪除`：先查 DB 筆數 (`SELECT COUNT(*) FROM Student`)，再確認前端 `branch_id` 與真實 `Campus.id` 對應是否正確。
- **全 API 500 的第一步永遠是清 bootstrap cache**（見下方 H 節）。

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
5. Rebuild frontend:
   - `cd frontend && npm run deploy`
6. Re-test web + API endpoints.

## E. Pre-merge checklist

- PR target branch is correct (`jerry-sync-main`)
- No accidental artifacts (`dist`, cache, local binaries, temp files)
- Frontend changes have been deployed
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

4. **重新 deploy 前端**：
   ```bash
   cd frontend && npm run deploy
   ```

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

## I. Reference docs

- `README.md`
- `AI_QUICKSTART.md`
- `docs/GITHUB_SYNC_WORKFLOW.md`
- `docs/INCIDENT_2026-04-10_GITHUB_AND_SITE_ROLLBACK.md`
- `docs/INCIDENT_2026-04-10_BRANCH_CAMPUS_500.md`（分校選單 / 學生消失 / 全 API 500）

