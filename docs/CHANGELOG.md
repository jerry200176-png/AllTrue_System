# AllTrue Changelog

> 格式：每條一行，分類 Added / Fixed / Changed / Security / Ops  
> 細節查 PR 說明或 `.cursor/plans/`  
> **舊記錄（2026-04-19 以前）**：[CHANGELOG_ARCHIVE_2026-04.md](CHANGELOG_ARCHIVE_2026-04.md)

---

## 2026-04-24（深夜）

### Fixed
- 補課流程三連修：(A) `ScheduleController::store` 補建補課時同步 `ClassSession::firstOrCreate`，出勤與評量頁面可見補課堂次；(B) `submitQuickAttend` 補上 `StudentID` 防 422 靜默失敗；(C) 老師快速點名加日期選擇器（最多回溯 14 天）；出勤頁加日期篩選器，管理員可查詢過去紀錄（見 `fix/makeup-attendance-flow`，bugfix plan `bugfix_makeup_class_session_missing_2026-04-24.md`）

---

## 2026-04-24（晚上）

### Fixed
- Bug 回報：附截圖時回傳 500 → 修 `deploy.yml` 補 `storage:link --force` + `chmod 775`；`BugReportService::attachUploadedFiles` / `AuthController::toAvatarUrl` 加 try-catch 讓存檔失敗降級（不中斷主流程），回傳 201 + `attachment_errors` 欄位（RC-1，見 `fix/bug-attachment-storage-500`）
- 家長入口評量：科目名稱顯示不一致（有的顯示 `English`、有的顯示 `英文課`）→ `ParentPortalController::resolveSubjectName` 邏輯修正：(1) 有課程科目且非 '課程' 時優先用課程名稱；(2) 課程無科目時對 `LearningRecord.Subject` 原始值套 `mapSubjectLabel`；(3) `mapSubjectLabel` 補中文別名（`英文課→英文`、`數學課→數學` 等）（PR #39 — 見 R11）

---

## 2026-04-24（下午）

### Fixed
- 家長入口：登入驗證改讀 `parent_phone`（UI「家長手機」欄），舊 `Phone` 欄 fallback 相容（PR #38 — 見 R10）

---

## 2026-04-24

### Security / Ops
- CI/CD: 移除 `ci.yml` 中的明碼 DB 密碼，改用 GitHub Secret `CI_DB_PASSWORD`（FR-007）
- CI/CD: `deploy.yml` 移除 `StrictHostKeyChecking=no`（`ssh-keyscan` 已在 Setup SSH 建立 known_hosts）（FR-008）

### Fixed
- CI/CD: `deploy.yml` 將 `php artisan optimize:clear` 改為 `config:cache && route:cache`，消除部署時短暫快取空白期（FR-004）

### Added
- CI/CD: `deploy.yml` 新增自動 rollback 機制—health check 失敗時自動執行 `git reset --hard`、前端重 build、快取重建、migration rollback（若有），並二次確認 health check（FR-005/006）
- CI/CD: `scripts/git-sync.sh` 加入 main branch 守門，在 main 執行時直接 abort 並提示建 feature branch（FR-009）
- CI/CD: `scripts/git-sync.sh` push 後自動執行 `gh pr create --fill --draft`（FR-010）

### Changed
- Docs: `README.md` GitHub 同步工作流程更新為 feature branch → PR → CI → merge 流程，移除過期的 `jerry-sync-main` 說明（FR-011）

### Ops（CI/CD 部署通道修復，同日下午）
- Pi: `StrictModes no` 加入 `/etc/ssh/sshd_config`（根因：`/home/admin` 為 `admin:www-data 775`，SSH 拒絕 public key — 見 R7）
- Pi: fail2ban unban 9 個 GitHub Actions runner IP + 永久白名單 GitHub Actions IP 範圍（`jail.local`）
- `deploy.yml`: `git pull` 改為 `git fetch origin main && git reset --hard origin/main`（根因：Pi 本地 nightly auto-commit 造成 divergent branches — 見 R9）
- `deploy.yml`: 移除 `composer install` 的 `--no-dev` flag（根因：Pi vendor 有舊 dev 安裝，--no-dev 造成 Collision class not found → health 500 — 見 R8）
- `deploy.yml`: `bootstrap/cache/packages.php` 部署前清除（搭配 R8 修復，已不需要，但保留作保險）
- **首次端對端自動部署成功**：`push → CI → deploy → health ok` 全流程驗證通過（2026-04-24 14:17 TWN）
- `deploy.yml`: `git diff` 偵測 `frontend/` 有無變動，無變動跳過 `npm run deploy`（docs/backend-only commit 不觸發用戶更新通知）
- `vite.config.js`: `version.json` 版本識別碼從 build 時間戳改為 git commit hash（相同 commit 多次 build 結果穩定）

---

## 2026-04-23

### Fixed (cont.)
- Tests: 7 個 time-sensitive tests 加入 `Carbon::setTestNow(today 10:00)` 修復午夜跨日 flaky（CI 22:00+ TWN 後 EndTime 變 "01:xx" 導致 session 窗口失敗）（PR #36）

### Fixed (cont.)
- Teacher attendance: 補卡後主表 `SignInDT`/`SignOutDT` 同步更新，前端不再顯示「未簽退」；`unclosed` 清單也正確排除已補卡記錄（PR #35）
- Teacher attendance: super_admin 傳入 `campus_id` 時現在會過濾至指定分校（不傳則維持看全部）（PR #35）

### Security / Fixed
- Teacher attendance: `index`/`unclosed`/`export`/`exportMonthly` 四個 API 加入 `campus_id` 參數隔離，修復多分校 director 可看到其他分校老師出勤記錄的越界問題（PR #34）
- Teacher attendance: `auth_campus_ids` 為空的非 super_admin 用戶改回 403，防止 bypass 全分校過濾（PR #34）

### Added
- Teacher attendance: `teacher-signin:close-orphans` 每日 00:05 自動補登前日未簽退記錄（SignOutDT=23:59, Status=adjusted, Memo=系統自動補登簽退）（PR #25）
- Migration: `TeacherSingIn.Memo varchar(512)` 補齊 migration 記錄（欄位本已存在 prod DB）（PR #25）

### Fixed
- Swipe: RFID 刷卡後同步 `ClassSession.Status`（attended/late），修復老師「待點名」計數虛高（PR #23）
- Swipe: `TeacherID=NULL` fallback 查詢，防止刷卡記錄從老師出缺勤視角消失（PR #23）
- Data patch: `StudentSingIn.id=945`（游家豫）`TeacherID` NULL→17（PR #23 前的歷史資料）
- Teacher attendance: 行政出勤狀態誤顯示「系統待確認」，backfill migration 修復（PR #19）
- TD-004: `findMatchingClass` 排除 `Status=leave` 的 ClassSession（PR #19）
- TD-006: 刷卡 60s debounce，防 RF bounce 秒速簽退（PR #19）
- TD-007: sign_in 前先查 ClassSession 是否已有記錄，重複刷回傳 `duplicate_ignored`（PR #19）
- TD-009: `backfillPresenceWindow` 加 EndTime null guard（PR #19）
- RFID: 同分校同卡不再靜默覆蓋，回傳 422（composite unique index）（PR #18）

### Added
- `AttendanceEffectsService`: ClassSession 狀態解析共用 Service（resolveSwipeStatus + applySessionStatus guard）（PR #23）
- 老師月報 XLSX 匯出：每位老師獨立 Sheet，左流水/右月曆格式（PR #19）
- `DELETE /attendance/{id}`: 軟刪除出缺勤記錄，自動沖回扣堂（PR #17）
- `POST /attendance/{id}/convert-to-attended`: 自修記錄轉到班（PR #17）
- TD-008: `CloseOrphanStudentSignIns` 每日 02:30 自動關閉孤兒記錄（PR #19）
- `docs/SYSTEM_TECH_GUIDE.md`: 後端技術實作索引（Identity/Swipe/ClassSession/Service 職責）

### Changed
- TD-011: `findMatchingClass` 窗口從 ±30min 改為 `(StartTime-30min) ≤ swipeAt ≤ EndTime`（PR #19）

### UI
- 教學工作台打卡狀態卡片：手機雙 chip 並排 + 彩色左邊框 + skeleton（PR #22）
- 出缺勤頁：自修記錄橘色 badge + 自修篩選器 + 刪除 Dialog + 轉換 Modal（PR #17）

---

## 2026-04-22

### Fixed
- `StudentsList`: 方案課程剩餘堂數 mapper 遺漏 `PackageID` 等欄位，顯示錯誤（§FRONTEND-005）
- 代課後調課：`schedules` 表未同步，代課老師顯示原老師（Issue #3）
- `directors/pending`: 排除被誤標為主任的教師帳號（Issue #6）
- `retroLeave`: 補請假重複 INSERT `StudentSignIn` 導致 500，改 `updateOrCreate`（Issue #2）

### Security
- Route throttle: `auth/register` 10/10min、`forgot-password` 5/60min、`swipe-rfid` 30/1min（SEC-002/003/006）
- 密碼最低長度全部入口統一 `min:8`（SEC-004）
- HTTP 安全標頭：HSTS / X-Frame-Options / nosniff / Referrer-Policy / Permissions-Policy（SEC-005）

### Ops
- 備份: KEEP=12（3 天）、nightly 統計告警、gdrive-sync sixhour 異地快照
- CI: `bootstrap.php` 加 `DB_DATABASE=AllTrue` 斷路器，防測試誤操作 production
- 月度還原演練 `monthly-restore-drill.sh`（每月 1 日 02:00，四表 row count 比對）

---

## 2026-04-21

### Fixed
- b7: 試聽容量誤判（`one_on_three` 被算入試聽名額）+ OPcache 陳舊導致調課失敗
- B1: 代點名代課可見性復發（nightly auto-backup 意外覆蓋修復 commit）
- C1: 代課後單堂顯示原老師（`start_time HH:MM:SS` 格式容錯，SUBSTRING 雙側比對）
- b5: 歷史堂數制課程 Charge 欄位錯誤（純資料修正，StudentClass ID=171，24000→12000）
- b3: 月結制課程無法進入歷史課程（`effectiveClosedReason` 月結分支補齊）
- b4: 月結制加購錯誤變堂數制（`renewMonthly` API + 前端分流）
- 繳費狀態切換未清除 `paid_at`（+ `SessionDeductionService` 移除誤清 `Paid=0` 邏輯）

### Added
- b6: 課表回報管理頁 30s 輪詢 + Nav Badge 每 60s 更新
- `opcache-reset` 部署端點（`X-Deploy-Secret` 驗證），`npm run deploy` 自動觸發
- git-sync `CODE_REVERT_GUARD`（controllers/migrations/tests 路徑淨刪除 ≥30 行時 exit 1）
- 備份失敗 EXIT Trap → Telegram 告警（nightly + sixhour）

---

## 2026-04-20

### Fixed
- ClassSession 時間異動未同步 `schedules` exception（`syncScheduleExceptionTime`，16 筆歷史 drift 修復）
- 排課例外在無 ClassSession 時靜默寫入孤兒記錄（`no_class_session` 422 防護）
- 代課假陽性衝堂：更換代課老師時舊 `scheduled` 列被計入（`exclude_schedule_id`）
- 試聽課型容量守衛誤判（trial 豁免分支，不影響正式課型）
- 學生備註清除仍顯示舊值（`isset` → `array_key_exists` + Supabase mirror 補同步）

### Added
- 代課老師容量標籤三態：有空 ✓ / 尚有容量 ⚠ / 已滿 ✗（後端 `remaining_capacity` 欄位）
- 多科共用方案建立時支援設定排課星期+時間（`createMultiSubject` 正式啟用）
- 方案管理頁：成員格排課狀態 tag + 健康度 badge + 不完整方案統計列
- `PUT /course-packages/{id}` 支援 `total_sessions`，全成員自動同步補排/取消

---

## 2026-04-19 以前

→ 見 [CHANGELOG_ARCHIVE_2026-04.md](CHANGELOG_ARCHIVE_2026-04.md)
