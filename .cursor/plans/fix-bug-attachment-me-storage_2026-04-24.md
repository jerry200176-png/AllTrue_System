# Bug Fix Plan — Bug 回報截圖上傳 500 + `/api/v1/me` 500

**版本**：1.0 | **狀態**：草稿 | **日期**：2026-04-24

---

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 環境配置 + 程式碼防護缺失 |
| 根因摘要 | `deploy.yml` 從未執行 `php artisan storage:link`，導致 `public/storage → storage/app/public` symlink 可能遺失；同時 `BugReportService::attachUploadedFiles()` 與 `AuthController::toAvatarUrl()` 中的 `Storage::disk('public')` 呼叫沒有包 try-catch，Flysystem `Local` adapter 在無法初始化 root 目錄時拋出 `LogicException`，直接變成未捕捉的 500 |
| 錯誤行為 | 1) 使用者附截圖提交 bug 回報時 → 500；2) 有頭像的使用者開啟頁面（`/api/v1/me`）→ 500 |
| 預期行為 | 1) 截圖上傳失敗時應返回 201（bug 本體仍建立）並在 response 帶 `attachment_errors` 警告；2) `/api/v1/me` 在 avatar storage 不可存取時仍正常返回用戶資料（avatar_url 為 null） |
| 影響範圍 | director / teacher 角色；`POST /api/v1/bugs`（附檔時）；`GET /api/v1/me`（有 AvatarUrl 的使用者） |
| B1 偵查來源 | 本計畫整合 B1 內容（使用者確認截圖才 500，console 同時見 `/api/v1/me` 500） |

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | Bug 回報截圖上傳 + 使用者資料讀取 |
| 版本 | backend-hotfix-storage |
| 狀態 | 草稿 |
| 嚴重度 | P1（核心功能受損） |
| 目標角色 | director、teacher |
| 關聯 Bug | bug-attachment-500, me-avatar-500 |

---

## 2. 業務背景與影響

- 使用者附截圖回報 bug 時收到「提交失敗：server error」，無法完整回報問題
- 有上傳過頭像的使用者開啟頁面時 `/api/v1/me` 也回傳 500，可能造成頁面功能異常
- **修復後預期行為**：截圖上傳失敗時 bug 本體仍成功建立並返回 201，前端顯示「截圖上傳失敗，但回報已提交」；`/api/v1/me` 在 storage 不可存取時仍正常返回使用者資料

---

## 3. 範圍

### In Scope
- `deploy.yml`：加入 `php artisan storage:link --force` 與 `chmod -R 775 storage bootstrap/cache`
- `BugReportService::attachUploadedFiles()`：加入 try-catch，storage 失敗時記錄 log 並跳過附件（不影響 bug 本體建立）
- `AuthController::toAvatarUrl()`：將 `Storage::disk('public')->exists()` 包入 try-catch，失敗時返回不帶版本號的 URL（而非 500）
- `BugReportController::store()`：response 加入 `attachment_errors` 欄位（附件失敗時）

### Out of Scope
- 不修改 `BugReportAttachment` model / migration
- 不修改 storage disk 配置（`config/filesystems.php`）
- 不修改 `/api/v1/me` 其他欄位的資料邏輯
- 不處理頭像上傳失敗（`/api/v1/me/avatar`）的 retry 機制
- 不修改前端 BugReportLauncher.vue 錯誤顯示邏輯

---

## 4. RACI

| 工作項目 | R | A |
|---|---|---|
| deploy.yml 修改 | AI Agent | AI Agent |
| BugReportService try-catch | AI Agent | AI Agent |
| AuthController try-catch | AI Agent | AI Agent |
| Regression Test | AI Agent | AI Agent |
| PR / CI 驗證 | AI Agent | AI Agent |
| 上線後 health check | AI Agent | AI Agent |

人類：I（觀察結果，不介入）

---

## 4b. Dependencies

- 無前置 PR 或 migration 依賴
- `deploy.yml` 改動需 merge 到 main 才會觸發 deploy（自動執行 `storage:link --force`，當下修復 Pi 上的 symlink）

---

## 5. Acceptance Criteria

### AC-001：截圖上傳 storage 失敗時，bug 本體仍建立成功
- AC-001-a：director/teacher 帶有效圖片附件提交 bug 回報，即使 `Storage::disk('public')` 拋出例外，`POST /api/v1/bugs` 仍返回 HTTP 201，body 含 `id`、`status: 'new'`
- AC-001-b：response body 包含 `attachment_errors: 1`（或 > 0），表示附件上傳失敗
- AC-001-c：`bug_reports` 資料表確實建立了一筆新記錄
- AC-001-d（反向）：`bug_report_attachments` 資料表不建立任何記錄（因 storage 失敗）

### AC-002：storage 正常時，截圖上傳正常完成
- AC-002-a：storage 正常時，帶有效圖片提交後 `bug_report_attachments` 建立對應記錄，detail API 返回非空 `attachments` 陣列

### AC-003：`/api/v1/me` 在 storage 不可存取時不返回 500
- AC-003-a：有 AvatarUrl 的使用者呼叫 `GET /api/v1/me`，即使 `Storage::disk('public')` 拋出例外，仍返回 HTTP 200，body 含完整使用者欄位
- AC-003-b：`avatar_url` 欄位為 null 或不帶版本號的字串（不拋出 500）

### AC-004：deploy.yml 包含 storage:link
- AC-004-a：`deploy.yml` 在 composer install 後執行 `php artisan storage:link --force`
- AC-004-b：`deploy.yml` 執行 `chmod -R 775 storage bootstrap/cache`

---

## 6. 功能需求 FR

| 編號 | 描述 |
|---|---|
| FR-001 | 修復後，`BugReportService::attachUploadedFiles()` 在 `$file->store()` 拋出任何 `Throwable` 時，應 log 警告並返回失敗的附件數量（int），不向上傳播例外 |
| FR-002 | 修復後，`BugReportController::store()` 應在 response 加入 `attachment_errors` 欄位（int，0 或失敗附件數），HTTP 狀態碼維持 201 |
| FR-003 | 修復後，`AuthController::toAvatarUrl()` 在 `Storage::disk('public')->exists()` 拋出例外時，應 catch 並返回不帶 `?v=` 的 URL 或 null，HTTP 狀態碼不受影響 |
| FR-004 | 修復後，`deploy.yml` 在每次部署時執行 `php artisan storage:link --force` 與 `chmod -R 775 storage bootstrap/cache`，確保 Pi 上 symlink 存在且 web server 可寫入 |

---

## 7. 非功能需求 NFR

不適用（純邏輯/配置修復，無效能影響；storage:link 和 chmod 指令執行時間 < 1 秒，不影響部署 SLA）

---

## 8. 技術方向

### 涉及檔案與方法

| 檔案 | 方法 | 改動說明 |
|---|---|---|
| `.github/workflows/deploy.yml` | 部署 shell script | 在 `[2/7] Composer dependencies` 之後加入 `php artisan storage:link --force` 和 `chmod -R 775 storage bootstrap/cache` |
| `backend/app/Services/BugReportService.php` | `attachUploadedFiles()` | 將 `$file->store()` 和 `BugReportAttachment::create()` 包入 try-catch，catch 時 log `warning` 並累計 `$failCount`；返回值從 `void` 改為 `int`（失敗附件數） |
| `backend/app/Http/Controllers/BugReportController.php` | `store()` | 接收 `attachUploadedFiles()` 返回值，加入 `attachment_errors` 至 response |
| `backend/app/Http/Controllers/AuthController.php` | `toAvatarUrl()` | 將 `Storage::disk('public')->exists($diskPath)` 整個 if block 包入 try-catch，catch 時 log `warning` 並 fall through 返回不帶版本號的 URL |

### 架構取捨
- **選擇 graceful degradation（不中斷主流程）而非 fail-fast**：bug 本體建立是核心功能，附件是附加功能。storage 故障時保留 bug 回報遠比完全失敗更有價值。
- **不改 BugReportAttachment model 的 `$fillable`**：`created_at` 已由 MySQL `useCurrent()` 自動填入，無需修改。

---

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-24 | `attachUploadedFiles()` 返回值從 void 改為 int（失敗數） | 拋出自定義例外 | controller 只需知道失敗數以填 response，不需要中斷流程 |
| 2026-04-24 | deploy.yml 加 `chmod -R 775` 而非 `chown www-data` | `chown -R www-data:www-data` | deploy 以 admin 帳號執行，可能無法 chown 到 www-data；`chmod 775` 確保 group write 即可（admin 與 www-data 同 group）|
| 2026-04-24 | 使用 `storage:link --force` 而非先 `rm public/storage` | 兩步驟刪除再建立 | `--force` 在 Laravel 8+ 支援，更安全且原子性更高 |

---

## 9. 資安與存取控制

不適用（本 bug fix 不涉及 auth / PII / 權限邊界，只修改 storage 錯誤處理邏輯）

---

## 10. QA 驗收

### Happy Path
- [ ] 帶有效 PNG 截圖 + 有效 token 提交 bug → 201，`id` > 0，storage 正常時 `attachment_errors: 0`
- [ ] 無附件 + 有效 token 提交 bug → 201，`attachment_errors: 0`
- [ ] GET /api/v1/me（有頭像的使用者）→ 200，`avatar_url` 非空

### Edge Cases
- [ ] storage 拋出例外時提交帶附件的 bug → 201，`attachment_errors: 1`，`bug_reports` 有記錄，`bug_report_attachments` 無記錄
- [ ] storage 拋出例外時 GET /api/v1/me → 200，`avatar_url` 為 null 或不帶 `?v=` 參數
- [ ] 附件超過 5MB → 422（驗證層攔截，不觸發 storage）

### Error Cases
- [ ] 未帶 token 提交 bug → 401
- [ ] branch_id 不合法 → 403

### Revert-proof 驗證
- [ ] `git stash` 後重跑新增的 test，各新增 case 至少 1 failure（確認測試真正覆蓋了 bug，而非誤綠）

---

## 11. 上線與維運

### 部署步驟
1. PR merge to main → `deploy.yml` 自動觸發
2. deploy.yml 新步驟執行 `php artisan storage:link --force`（修復 Pi 上 symlink）
3. deploy.yml 新步驟執行 `chmod -R 775 storage bootstrap/cache`（修復權限）
4. `php artisan optimize`（route/config cache）
5. Health check → `curl -sk https://daan.lifenet.com.tw/api/v1/health` 確認 `{"status":"ok"}`
6. 手動驗收：帶截圖提交一筆 bug 回報，確認 201

### 有無 migration
無 migration（純程式碼 + deploy 腳本修改）

### Observability
- storage 失敗時 `Log::warning('[BugReport] attachment storage failed: ...')` 和 `Log::warning('[toAvatarUrl] storage disk not accessible: ...')` 可在 `storage/logs/laravel-2026-04-24.log` 看到

### 回滾方案
- `git revert <hash> --no-commit && git commit` → PR merge → deploy
- 預估回滾時間：< 5 分鐘

---

## 12. 優先級

| 級別 | 執行 Agent |
|---|---|
| P1 — 當天修復 | `[DEV]` → `[TEST]` → `[REVIEW]` → `[DOCS]` → `[OPS]` |

---

## 13. 風險 / 假設 / 開放問題

> **WebSearch 查詢完成**（2026-04-24）：業界解法確認為 `php artisan storage:link --force` + `chmod -R 775 storage`

| 項目 | 說明 |
|---|---|
| 風險 R1 | `chmod 775` 前提：Pi 上 `admin` 與 web server 同 group。若不同 group，chmod 可能仍無法解決寫入問題，需進一步加 `chown`（需 sudo） |
| 風險 R2 | `storage:link --force` 需要 Pi 上 `public/storage` 不是一般目錄（若曾被 mkdir 建立為真實目錄，`--force` 會覆蓋） |
| 假設 A1 | Pi 上 `storage/app/public` 目錄存在（僅是 symlink 遺失或權限不足） |
| 開放問題 Q1 | `/api/v1/me` 的 500 是否只影響有 AvatarUrl 的使用者？需部署後確認（若 `me` 仍偶發 500，需進一步偵查其他 helper 方法）|

---

## 14. Definition of Done

- [ ] FR-001（try-catch in attachUploadedFiles）：`git diff backend/app/Services/BugReportService.php` 含 `try {` + `catch (\Throwable` + `Log::warning`
- [ ] FR-002（attachment_errors in response）：`git diff backend/app/Http/Controllers/BugReportController.php` 含 `attachment_errors`
- [ ] FR-003（toAvatarUrl try-catch）：`git diff backend/app/Http/Controllers/AuthController.php` 含 try-catch 包住 `Storage::disk('public')->exists`
- [ ] FR-004（deploy.yml storage:link）：`git diff .github/workflows/deploy.yml` 含 `storage:link --force` 與 `chmod -R 775`
- [ ] Regression Tests 全綠：`gh run view <run_id>` 回傳 `success`，`Tests: N, Errors: 0`
- [ ] Revert-proof：`git stash && <test command>` 各新增 case 至少 1 failure
- [ ] CHANGELOG：`git diff docs/CHANGELOG.md` 含 `2026-04-24` 新增條目
- [ ] Health check：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `{"status":"ok",...}` HTTP 200

---

## Todos

| 類別 | 工作 | Agent |
|---|---|---|
| 後端修復（Service / Controller / deploy） | FR-001 ~ FR-004 | `[DEV]` |
| Regression Tests | 新增 attachment storage fail test + me avatar fail test | `[TEST]` |
| Revert-proof 驗證 | git stash 後確認新增 test fail | `[TEST]` |
| Code Review | 逐條對照 FR | `[REVIEW]` |
| CHANGELOG + AI_REGRESSION_LESSONS | 更新文件 | `[DOCS]` |
| 部署 | PR merge → auto-deploy → health check → 手動帶截圖驗收 | `[OPS]` |
