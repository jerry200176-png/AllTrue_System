---
name: B1 代點名代課可見性復發
overview: 代課老師接手的堂次仍出現在原老師的「待補點名（代點名）」清單；A+B 修正（commit 01160fc）已被 nightly auto-backup commit 532872a 連帶測試檔與 migration 一起 revert。本計畫重新還原 A+B 修正、補 migration 與測試檔，並在 git-sync.sh 加上 controller/migration/tests 變動守衛，避免再次被覆蓋。
todos:
  - id: dev-restore-controllers
    content: "[DEV] 還原 ClassSessionController::index（teacher 分支 + teacher_id query 分支）與 AttendanceController::endedSessions 的堂次級守衛（依 commit 01160fc 內容）"
    status: completed
  - id: dev-restore-migration
    content: "[DEV] 重建 migration 2026_04_21_000001_add_schedules_composite_index.php（複合索引 idx_sched_course_date_time_status），執行 php artisan migrate"
    status: completed
  - id: dev-guard-git-sync
    content: "[DEV] 修改 scripts/git-sync.sh：偵測到 backend/app/Http/Controllers / backend/database/migrations / backend/tests 的刪除或大幅 revert 時，預設拒絕 commit，需顯式 --allow-code-revert 旗標才放行"
    status: completed
  - id: test-restore-suite
    content: "[TEST] 重建 ClassSessionsTeacherVisibilityAfterSubstituteTest.php（4 cases）與 AttendanceEndedSessionsSubstituteTest.php（4 cases），對齊 commit 01160fc 的測試內容"
    status: completed
  - id: test-revert-proof
    content: "[TEST] Revert-proof：git stash 後 8 個 case 至少有 2 個 failure（ClassSessions 至少 1 個、EndedSessions 至少 1 個）"
    status: completed
  - id: review-security
    content: "[REVIEW] 資安靜態審查：teacher 角色邊界、auth_teacher_id 來源、whereExists 子查詢無 SQL injection 風險"
    status: completed
  - id: review-code
    content: "[REVIEW] Code Review：逐條對照 FR-001~FR-007、確認 git-sync.sh 守衛邏輯不誤判正常 refactor"
    status: completed
  - id: docs-changelog
    content: "[DOCS] 更新 CHANGELOG.md（記錄 incident + restore + git-sync hardening）與 AI_REGRESSION_LESSONS.md（新增 nightly auto-backup 覆蓋已 commit 修正的教訓）"
    status: completed
  - id: ops-deploy
    content: "[OPS] migrate + cache clear + health check + 抽樣驗證（以原老師 token 呼叫 ended-sessions 確認被代課堂不在清單）"
    status: in_progress
isProject: false
---

# Bug Fix Plan — B1 代點名（補點名）代課可見性復發

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | **P0**（資料正確性 bug；原老師可能誤點別人代課的堂，導致出缺勤紀錄錯亂） |
| 根因類型 | 流程錯誤（已 commit 修正被自動化備份覆蓋）+ Query 條件錯誤（恢復後狀態） |
| 根因摘要 | A+B 修正（commit `01160fc`）已正確還原 `AttendanceController::endedSessions` 的「堂次級 whereExists/whereNotExists 守衛」與 `ClassSessionController::index` 兩處 `whereNull('sub_sched.teacher_id')` 條件，但 18:41 nightly auto-backup commit `532872a chore(nightly): auto backup 2026-04-21_1840 — post-incident recovery` 把 working tree 上「不含 A+B 的舊狀態」整批 commit 進去，等同 revert 兩個 controller 的修改、刪除測試檔、刪除 migration 檔。 |
| 錯誤行為 | 原老師（契約 TeacherID）登入後，`GET /api/v1/attendance/ended-sessions` 仍回傳已給代課老師的堂次（ContractClassIds 課程級超集合命中、缺堂次級過濾）；同時 director 帶 `?teacher_id=T1` 查衝堂時被代課堂仍誤判為 T1 的衝堂 |
| 預期行為 | 該堂存在有效代課記錄（`schedules.status='scheduled'` 且 `original_schedule_id IS NOT NULL`）時，僅代課老師看得到；無代課記錄時才由契約老師看到。`teacher_id` query 過濾遵循同一語意 |
| 影響範圍 | 角色：teacher（補點名 UI）、director / super_admin（衝堂檢查 query）。API：`/api/v1/attendance/ended-sessions`、`/api/v1/class-sessions?teacher_id=*`。資料：所有有 `schedules` 代課記錄的課程當天堂次 |
| B1 偵查來源 | 本計畫整合 B1 偵查內容；對應 [a+b_代課可見性修正_d2f91b5e.plan.md](.cursor/plans/a+b_代課可見性修正_d2f91b5e.plan.md) 的 FR 與測試設計 |

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 代點名代課可見性復發修復（B1 Restore + Backup Hardening） |
| 版本 | v1.0 |
| 狀態 | 待實作 |
| 嚴重度 | **P0** |
| 目標角色 | 老師（teacher）、主任（director）、super_admin、AI Agent |
| 關聯 Bug | A+B（commit `01160fc` 已修但被 `532872a` revert）、本案為 incident recovery |

---

## 2. 業務背景與影響

**修復前的錯誤行為：**

- 老師打開「待補點名（已結束節次）」會看到自己**已經請別人代課**的堂次，誤點之後系統會把代課老師應得的鐘點計入原老師
- 主任在 `CourseEditForm` 為某老師排新課時，被代課時段仍被視為原老師的 busy slot，**衝堂提示誤報**，無法正常排課
- 代課老師若代過某課程的某一堂，可能在補點名清單中看到該課程**其他非代課時段**的已結束堂次（因 `subClassIds` 是課程級超集合）

**修復後預期行為：**

- 原老師（契約 TeacherID）的 `/api/v1/attendance/ended-sessions` 回傳清單**完全不包含**已代課堂次
- 代課老師**只能**看到自己被指派的特定堂次（同課程其他時段不應出現）
- `/api/v1/class-sessions?teacher_id=T1` 不再把 T1 被代課的堂次計入 T1 的衝堂集合
- nightly auto-backup 不再會覆蓋已 commit 的 controller / migration / tests 變更

---

## 3. 範圍

**In Scope：**

- 還原 `ClassSessionController::index` 的兩處 `(sub_sched.teacher_id IS NULL AND sc.TeacherID = ?) OR sub_sched.teacher_id = ?` 邏輯（teacher 分支 + teacher_id query 分支）
- 還原 `AttendanceController::endedSessions` 的「拆出 `$sessionsBuilder` + 追加堂次級 whereExists / whereNotExists」守衛
- 重建 migration `2026_04_21_000001_add_schedules_composite_index.php`（複合索引 `idx_sched_course_date_time_status`），實際在 DB 上建立索引
- 重建兩個 PHPUnit 測試檔（`ClassSessionsTeacherVisibilityAfterSubstituteTest.php`、`AttendanceEndedSessionsSubstituteTest.php`），對齊 commit `01160fc` 的內容
- 修改 `scripts/git-sync.sh`：對 `backend/app/Http/Controllers/`、`backend/database/migrations/`、`backend/tests/` 三個路徑做「刪除/revert 守衛」，預設拒絕 commit，需 `ALLOW_CODE_REVERT=1` 環境變數放行
- 更新 `docs/CHANGELOG.md` 與 `docs/AI_REGRESSION_LESSONS.md`

**Out of Scope：**

- 重寫 `endedSessions` 的兩階段查詢為單階段 JOIN（候選 B；風險較高，留待後續 tech-debt 計畫）
- 整個 nightly-backup pipeline 架構重設計（本案只做 git-sync 層級的 minimum guard）
- 前端 `AttendancePage.vue` / `CourseEditForm.vue` 的 UI 變更（純後端可見性收緊）
- `director-accounts`、`learning-records`、`subject-units` 等其他控制器或路徑
- 修改 cron 排程或 `nightly-backup.sh` 主流程
- `ClassSessionController` 中 `role === 'teacher'` 的 B2 分支（已上線，本次延用）

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（實作） | `[DEV]` Agent | R / A |
| AI Agent（測試） | `[TEST]` Agent | R / A |
| AI Agent（審查） | `[REVIEW]` Agent | R / A |
| AI Agent（文件） | `[DOCS]` Agent | R / A |
| AI Agent（部署） | `[OPS]` Agent | R / A |
| 人類 | CTO | I |

---

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置 commit | `01160fc fix(substitute): A+B 代課可見性修正` 仍存在於 git 歷史，可作為內容來源 | 已確認（`git show 01160fc` 可讀） |
| 外部 PR | 無 | — |
| DB Migration | `2026_04_21_000001_add_schedules_composite_index.php` 需重建檔案並重跑（migrate:status 已確認 history 與索引皆不在 DB） | 待執行 |
| 環境前提 | `schedules` 表存在（migration `2026_03_13_000013` 已 run）；MariaDB 10.11 | 已確認 |
| Backup script 路徑 | `/home/admin/scripts/git-sync.sh` 存在且為 nightly-backup 唯一 commit 入口 | 已確認 |

---

## 5. Acceptance Criteria

### AC-001：原老師補點名清單排除被代課堂

- AC-001-a：原老師 T1 為某課程契約老師，T2 在 D 日代課；以 T1 token 呼叫 `GET /api/v1/attendance/ended-sessions?branch_id=X&start_date=D&end_date=D`，回傳 `data` 不包含該堂 `class_session_id`
- AC-001-b：同條件下，以 T2 token 呼叫，回傳 `data` 包含該堂 `class_session_id` 且 `teacher_id` 等於 T2

### AC-002：代課老師清單不溢出

- AC-002-a：T2 只代某課程 D 日該堂；同課程 D+1 日仍由 T1 上課（無代課）。以 T2 token 呼叫覆蓋 D 與 D+1 的範圍，`data` 不包含 D+1 那堂
- AC-002-b：原老師 T1 在無代課的其他契約堂仍正常出現在 T1 的回傳清單

### AC-003：衝堂查詢遵循代課語意

- AC-003-a：以 director token 呼叫 `GET /api/v1/class-sessions?teacher_id=T1&start=D&end=D`，被代課堂不在回傳清單
- AC-003-b：同條件下將 `teacher_id` 改為 T2，被代課堂出現於回傳，`teacher_id` 正確顯示為 T2
- AC-003-c：T1 在 D 日無代課的其他堂次仍正常出現

### AC-004：複合索引存在於 DB

- AC-004-a：`SHOW INDEX FROM schedules` 結果含 `Key_name = idx_sched_course_date_time_status`
- AC-004-b：`migrate:status` 顯示 `2026_04_21_000001_add_schedules_composite_index` 為 `Yes`

### AC-005：git-sync 守衛阻擋 controller/tests/migrations 退化

- AC-005-a：在 working tree 偽造一個刪除 `backend/tests/Feature/AttendanceEndedSessionsSubstituteTest.php` 的狀態，呼叫 `git-sync.sh "test"`，腳本 exit code 非 0 且訊息提到 `CODE_REVERT_GUARD`
- AC-005-b：同條件下設 `ALLOW_CODE_REVERT=1` 後再呼叫，腳本正常 commit
- AC-005-c：純資料/前端產出（如 `frontend/dist_build/**`、`backups/**`、`storage/**`）變動不觸發守衛

---

## 6. 功能需求 FR

| # | 需求 | 可測試條件 |
|---|---|---|
| FR-001 | `ClassSessionController::index` 在 `role === 'teacher'` 分支採 `(sub_sched.teacher_id IS NULL AND sc.TeacherID = ?) OR sub_sched.teacher_id = ?` 語意 | PHPUnit AC-003 對應 case 通過 |
| FR-002 | `ClassSessionController::index` 在 `teacher_id` query 參數分支採同 FR-001 語意 | PHPUnit AC-003-a / AC-003-b / AC-003-c 通過 |
| FR-003 | `AttendanceController::endedSessions` 在 `role === 'teacher'` 時，對每一個候選 ClassSession 額外做堂次級 whereExists（命中代課老師） / whereNotExists（無代課則回到契約老師）兩岔過濾 | PHPUnit AC-001 / AC-002 通過 |
| FR-004 | FR-003 的兩岔過濾以 `student_course_id + schedule_date + start_time(SUBSTRING 1,5)` 三鍵對齊 ClassSession | 對齊欄位驗證在測試 setup 中以 ClassSession.StartTime='16:00:00' / schedules.start_time='16:00' 形式覆蓋 |
| FR-005 | `schedules` 表新增複合索引 `idx_sched_course_date_time_status`，欄位順序 `(student_course_id, schedule_date, start_time, status, original_schedule_id)`；migration file 與 migration history 同步存在 | PHPUnit AC-004 通過；`SHOW INDEX FROM schedules` 與 `migrate:status` 雙重驗證 |
| FR-006 | `scripts/git-sync.sh` 加入「程式碼路徑退化守衛」：偵測 staged changes 對 `backend/app/Http/Controllers/`、`backend/database/migrations/`、`backend/tests/` 任一路徑造成 **檔案刪除** 或 **單一檔案淨刪除行數 ≥ 30 行** 時，預設拒絕 commit；環境變數 `ALLOW_CODE_REVERT=1` 可繞過 | AC-005 三條 case 通過 |
| FR-007 | 守衛拒絕時 exit code 非 0、stderr 含關鍵字 `CODE_REVERT_GUARD`，並把觸發的檔案列表寫入 `backups/code-revert-guard.log` 供事後排查 | AC-005-a 與 log 檔內容驗證 |

---

## 7. 非功能需求 NFR

| 面向 | 指標 | 降級策略 |
|---|---|---|
| 效能 — A 路徑 | `GET /api/v1/class-sessions?teacher_id=X` P95 < 500 ms（與修前相當） | 本次修法為 LEFT JOIN 上加欄位比較，無新子查詢 |
| 效能 — B 路徑 | `GET /api/v1/attendance/ended-sessions` P95 < 800 ms（預設 7 天 / 50 筆） | 複合索引 FR-005 為主要保障；schedules 目前 335 筆，whereExists 子查詢預期 < 1 ms |
| 索引 | whereExists 子查詢過濾欄位需被複合索引覆蓋 | 若 migration 失敗，查詢仍正確但較慢；可單獨手動建索引而不影響邏輯 |
| 向後相容 | director / super_admin 角色路徑回傳結果不變；FR-006 守衛不影響純資料/前端產出 commit | AC-005-c 反向驗證 |
| Backup 守衛延遲 | `git-sync.sh` 守衛邏輯整體新增 ≤ 200 ms（單純 git diff --cached --numstat 解析） | 若守衛崩潰，原 commit 流程不受阻（守衛應 fail-open 於自身錯誤，但 fail-closed 於偵測到退化） |
| 資料正確性 | false-negative（漏顯示合法堂次）比 false-positive 更嚴重 | 測試 AC-001-b、AC-002-b、AC-003-c 專門覆蓋不過度排除 |

---

## 8. 技術方向

**涉及檔案：**

- `backend/app/Http/Controllers/ClassSessionController.php`：兩處 `where(...)` group 還原，按 commit `01160fc` 的 `whereNull('sub_sched.teacher_id')` 邏輯
- `backend/app/Http/Controllers/AttendanceController.php` 的 `endedSessions` 方法：拆出 `$sessionsBuilder` 後追加 `outer where group → whereExists 命中代課老師 OR (whereNotExists 無代課 AND whereExists 契約老師等於 self)`
- `backend/database/migrations/2026_04_21_000001_add_schedules_composite_index.php`：新建 migration，up 用 `Schema::table` 加 `idx_sched_course_date_time_status` 五欄複合索引，down 對應 dropIndex
- `backend/tests/Feature/ClassSessionsTeacherVisibilityAfterSubstituteTest.php`：依 commit `01160fc` 重建 4 個 case
- `backend/tests/Feature/AttendanceEndedSessionsSubstituteTest.php`：依 commit `01160fc` 重建 4 個 case
- `scripts/git-sync.sh`：在 `git add -A` 之後、`git commit` 之前插入「FR-006 程式碼路徑退化守衛」段落
- `docs/CHANGELOG.md`、`docs/AI_REGRESSION_LESSONS.md`：文件追加

**架構取捨：**

- 採「直接 cherry-pick commit `01160fc` 內容」而非重新設計：因為該 commit 的 controller 變動已通過完整測試與部署驗證，重新設計反而引入新風險
- git-sync 守衛採「黑名單路徑 + 行數閾值」雙條件而非單一 hash 比對：純路徑黑名單會誤擋正常 refactor；單一行數會擋到正常大改；兩條件交集（路徑 ∩ 大幅刪除）才觸發
- 守衛採用環境變數 `ALLOW_CODE_REVERT=1` 而非 CLI 旗標：方便未來在「合法的批次 revert」場景（例如 release rollback）直接 export 後執行
- migration 不採 `if exists` 條件：若 DB 上殘留同名索引，migration 應失敗以暴露不一致狀態（避免 silent skip）

**不做的事：**

- 不重寫 `endedSessions` 的 classIds 兩階段超集合計算（保留現行架構，僅補堂次級守衛）
- 不修改 `nightly-backup.sh` 的主流程（cron / dump / tag 邏輯不動）
- 不增加新 API endpoint
- 不改 director / super_admin 路徑

---

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-21 | 直接還原 commit `01160fc` 的 controller 邏輯，不重新設計 | 重寫為單階段 JOIN、用 view 取代子查詢 | 該邏輯已通過 8 個測試 case 與部署驗證；最小變更原則 |
| 2026-04-21 | git-sync 守衛採「路徑黑名單 ∩ 大幅刪除」雙條件 | 純路徑黑名單、純 commit-hash 監視 | 雙條件交集準確度最高，誤擋率低 |
| 2026-04-21 | 守衛 fail-closed（拒絕 commit）而非 fail-open（仍 commit + 警告） | 警告即 commit、走 PR review | 既然問題就是「commit 把修正吃掉」，必須阻斷在 commit 之前 |
| 2026-04-21 | 用 `ALLOW_CODE_REVERT=1` 環境變數放行而非 CLI flag | `--allow-code-revert` 旗標 | 環境變數可在 release rollback 等場景一次 export 多個工具，CLI flag 需逐次傳入 |
| 2026-04-21 | migration 不加 `IF EXISTS` 防呆 | 加 IF EXISTS 自動跳過 | 暴露不一致狀態勝過 silent skip；DB 目前確認無此索引 |

---

## 9. 資安與存取控制

**角色邊界：**

- FR-001 ~ FR-004 僅影響 `role === 'teacher'` 與帶 `teacher_id` query 的 director 分支；不變更 director / super_admin 預設可見範圍
- `auth_teacher_id` 來源仍由 `AttachAuthUser` middleware 從 token 解析，不接受前端傳值
- FR-006 / FR-007 守衛在 commit 階段運作，與 application runtime 無直接關聯，但仍須驗證守衛本身不會因外部不可信輸入（例如惡意檔名）造成命令注入

**STRIDE 快評：**

| 威脅 | 評估 |
|---|---|
| Spoofing | 不變：teacher_id 仍由 token 解析，前端無法偽造 |
| Tampering | 不變：whereExists 為純 read；git-sync 守衛只讀 git diff，不寫入 |
| Repudiation | 改善：FR-007 寫入 `backups/code-revert-guard.log`，未來覆蓋事件可追溯 |
| Information Disclosure | 改善：FR-001 ~ FR-004 收緊原老師可見範圍，降低代課堂資料外洩 |
| Denial of Service | 低度新增：whereExists 子查詢；FR-005 複合索引緩解。git-sync 守衛新增 < 200 ms 可忽略 |
| Elevation of Privilege | 不變：本案只收緊可見性，不新增寫入路徑或角色 |

**PII：** 本修正不處理、不新增任何個資欄位；守衛 log 僅含檔案路徑名（不含內容）。

---

## 10. QA 驗收

### Happy Path

- [ ] 原老師 GET ended-sessions：被代課堂不出現（AC-001-a）
- [ ] 代課老師 GET ended-sessions：被代課堂出現且 teacher_id 正確（AC-001-b）
- [ ] 代課老師同課程 D+1 非代課堂不出現（AC-002-a）
- [ ] 原老師其他契約堂仍出現（AC-002-b）
- [ ] director 帶 teacher_id=T1：被代課堂不出現（AC-003-a）
- [ ] director 帶 teacher_id=T2：被代課堂出現（AC-003-b）
- [ ] 複合索引存在於 DB（AC-004-a / AC-004-b）

### Edge Cases

- [ ] 同一課程同一日有兩筆代課記錄 → 取最新（依 `MAX(id)` / orderByDesc）
- [ ] 代課記錄 status 不是 `scheduled`（如 `cancelled`）→ 不命中代課分支，原老師重新可見
- [ ] schedules 表為空 → whereExists 回傳 false，正常走 whereNotExists + 契約老師分支
- [ ] super_admin 呼叫 ended-sessions → 走 else 分支，不受 whereExists 影響
- [ ] git-sync 守衛遇到「純資料/前端產出」變動（`backups/`、`frontend/dist_build/`、`storage/`）→ 不觸發（AC-005-c）

### Error Cases

- [ ] teacher role 無 teacher_id（auth_teacher_id 為 0）→ 回傳空列表，不 500
- [ ] git-sync 守衛遇到 controller 大幅刪除 → exit ≠ 0 且 stderr 含 `CODE_REVERT_GUARD`（AC-005-a）
- [ ] git-sync 守衛在 `ALLOW_CODE_REVERT=1` 下 → 正常 commit（AC-005-b）

### Revert-proof 驗證

- [ ] git stash 後重跑 `phpunit tests/Feature/ClassSessionsTeacherVisibilityAfterSubstituteTest.php` 與 `phpunit tests/Feature/AttendanceEndedSessionsSubstituteTest.php`，每檔至少 1 個 case failure（確認測試真正覆蓋 bug）
- [ ] 若 git stash 還原把 git-sync.sh 守衛拔掉，模擬 controller 大幅刪除的 dry-run（不真的 commit）應 exit 0；加回守衛後同 dry-run 應 exit ≠ 0（守衛行為驗證）

---

## 11. 上線與維運

**部署步驟：**

1. 執行 `php artisan migrate`（新增 schedules 複合索引）
2. 清 Laravel cache：`php artisan config:clear && php artisan route:clear && php artisan cache:clear`
3. 無前端變更，**不需** `npm run deploy`
4. 確認守衛：`bash -n /home/admin/scripts/git-sync.sh`（語法檢查）
5. health check：`curl -sk https://daan.lifenet.com.tw/api/v1/health` → `{"status":"ok",...}` HTTP 200
6. 抽樣驗證：以一位有代課記錄的原老師 token 呼叫 ended-sessions，確認被代課堂不在清單

**Feature Flag 策略：**

無 feature flag。本修正為「恢復應有狀態 + 守衛強化」，部署即全量生效。回退成本低（git revert 即可，且本案的核心是「避免被自動 revert」，恢復原行為等同於再復發 bug）。

**Observability：**

| 監控項目 | 指標 / log 關鍵字 | 告警閾值 | 負責 Agent |
|---|---|---|---|
| ended-sessions 回應時間 | `storage/logs/laravel.log` 慢查詢標記 | P95 > 2000 ms 連續 3 分鐘 | `[OPS]` |
| class-sessions teacher_id 回應時間 | Apache access log | P95 > 1000 ms | `[OPS]` |
| 代課堂次誤出現 | 上線後人工抽樣 1 位原老師 × 1 天 | 任何被代課堂出現 | `[OPS]` |
| git-sync 守衛觸發 | `backups/code-revert-guard.log` 新增 entry | 任何非預期觸發即查驗（每週掃描） | `[OPS]` |
| nightly-backup 成功率 | Telegram 通知 + `backups/nightly-backup.log` | 連續 2 天失敗即告警 | `[OPS]` |

**回滾：**

- 程式碼：`git revert <new-commit-hash>`（PHP + shell 檔案）
- Migration：`php artisan migrate:rollback --step=1`（dropIndex，不影響資料）
- git-sync 守衛若誤擋正常 commit：`ALLOW_CODE_REVERT=1 ./scripts/git-sync.sh "..."` 立即繞過
- 預估回滾時間：< 5 分鐘

---

## 12. 優先級

| 優先級 | 項目 | 執行 Agent |
|---|---|---|
| **P0** | FR-001, FR-002, FR-003, FR-004：兩個 controller 還原 | `[DEV]` |
| **P0** | 8 個 PHPUnit case 全綠 | `[TEST]` |
| **P0** | FR-006, FR-007：git-sync.sh 守衛（避免再次被覆蓋） | `[DEV]` |
| P1 | FR-005：schedules 複合索引 migration | `[DEV]` |
| P1 | STRIDE 審查 | `[REVIEW]` |
| P1 | Code Review 逐條對照 FR | `[REVIEW]` |
| P2 | CHANGELOG / AI_REGRESSION_LESSONS 更新 | `[DOCS]` |
| P2 | 部署、health check、抽樣驗證 | `[OPS]` |

---

## 13. 風險 / 假設 / 開放問題

### 風險

| 風險 | 等級 | 業界標準解法（來源） | 本專案採行方式 |
|---|---|---|---|
| nightly auto-backup 再次把 controller 回退 | **高** | 設立 backup remote、post-commit hook 立即推送、`git push --force-with-lease` 而非 `--force`（DevopsRoles 2026、Aaron Brethorst 2026 — Git Rebase for the Terrified） | FR-006 守衛在 `git-sync.sh` 內阻斷退化 staging；nightly-backup.log 與 Telegram 已有失敗告警 |
| whereExists 複合索引未命中導致 P95 退化 | 中 | composite index leftmost prefix + ORDER BY 後置（itmarkerz.co.in 2026 — Database Performance for Enterprise Laravel） | FR-005 索引欄位順序 `(student_course_id, schedule_date, start_time, status, original_schedule_id)` 與查詢 WHERE 順序對齊 |
| MariaDB 10.11 對 EXISTS semi-join 的優化器選擇 | 低 | 監控 EXPLAIN，必要時 `optimizer_switch='semijoin=off'`（thelinuxcode 2026 — MySQL IN vs EXISTS） | 本機 MariaDB 10.11 與 MySQL 8.0 行為不同，目前資料量 335 筆無風險；列入 `[AI-RESOLVABLE]` 監控 |
| git-sync 守衛誤擋正常大規模 refactor | 中 | 路徑黑名單 + 行數閾值雙條件 + 環境變數 escape hatch（業界 pre-commit hook 標準模式） | FR-006 雙條件 + `ALLOW_CODE_REVERT=1` 放行 |
| 還原 commit `01160fc` 的測試檔內容可能與當前其他測試衝突（如 schema 變動） | 低 | 跑 `phpunit --filter Substitute` 與既有 suite 比對 failure 差異 | DoD 的 regression 比對項目專門驗證 |

### 假設

- `schedules.status = 'scheduled'` 且 `original_schedule_id IS NOT NULL` 仍是「有效代課」的唯一條件（與 commit `01160fc` 撰寫時相同；`SubstituteService` 自此未變更）
- `ClassSession.StartTime` 為 `HH:MM:SS` 格式、`schedules.start_time` 為 `HH:MM`，仍以 `SUBSTRING(ClassSession.StartTime, 1, 5)` 對齊（已驗證於原 A+B migration 與測試 setup）
- `git-sync.sh` 仍是 `nightly-backup.sh` 唯一的 commit 入口（已確認 `nightly-backup.sh` 第 132 行只呼叫此一腳本）
- nightly-backup cron 為 01:00；18:41 那次 commit 為手動觸發或由 incident recovery 流程觸發（不在本次範圍內追查觸發者）

### 開放問題

- `[AI-RESOLVABLE]` 18:41 commit `532872a` 的觸發者：是 incident recovery 流程？是 agent 手動跑？需查 `backups/nightly-backup.log` 該時段 entry 與 shell history 釐清；若為 agent 手動，需另外寫規則禁止在 controller revert 狀態下手動跑 git-sync.sh
- `[AI-RESOLVABLE]` MariaDB EXPLAIN 是否真的命中新索引：`EXPLAIN SELECT ... whereExists` 預期 `key = idx_sched_course_date_time_status`；可在 OPS 階段驗證
- `[AI-RESOLVABLE]` 是否要把守衛延伸到 `frontend/src/`：目前未列入，因為前端變動後 `frontend/dist_build/` 也會大量變動，行數閾值容易誤判；待守衛上線一週後評估

---

## 14. Definition of Done

- [ ] FR-001 / FR-002（A 衝堂過濾）：驗證方式：`cd backend && ./vendor/bin/phpunit tests/Feature/ClassSessionsTeacherVisibilityAfterSubstituteTest.php` 回傳 `OK (4 tests, ≥ 15 assertions)`，0 failures
- [ ] FR-003 / FR-004（B 補點名過濾）：驗證方式：`cd backend && ./vendor/bin/phpunit tests/Feature/AttendanceEndedSessionsSubstituteTest.php` 回傳 `OK (4 tests, ≥ 10 assertions)`，0 failures
- [ ] Revert-proof：驗證方式：`git stash && cd backend && ./vendor/bin/phpunit tests/Feature/ClassSessionsTeacherVisibilityAfterSubstituteTest.php tests/Feature/AttendanceEndedSessionsSubstituteTest.php; git stash pop` 兩檔各至少 1 case failure
- [ ] FR-005（複合索引）：驗證方式：`cd backend && php artisan migrate:status | grep idx_sched_course_date_time_status` 回傳含此 migration record 的 `Yes` 一行；同時 `php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); foreach(Illuminate\Support\Facades\DB::select('SHOW INDEX FROM schedules') as \$r) echo \$r->Key_name.PHP_EOL;"` 輸出含 `idx_sched_course_date_time_status`
- [ ] FR-006（守衛拒絕模式）：驗證方式：`cd /home/admin && git stash && rm backend/tests/Feature/AttendanceEndedSessionsSubstituteTest.php && ./scripts/git-sync.sh "test guard" 2>&1 | grep -q CODE_REVERT_GUARD && echo PASS && git checkout backend/tests && git stash pop` 輸出 `PASS`
- [ ] FR-006（守衛繞過模式）：驗證方式：`cd /home/admin && git stash && rm backend/tests/Feature/AttendanceEndedSessionsSubstituteTest.php && ALLOW_CODE_REVERT=1 ./scripts/git-sync.sh --dry-run-only 2>&1 | grep -qv CODE_REVERT_GUARD && echo PASS; git checkout backend/tests && git stash pop` 輸出 `PASS`（註：dry-run 模式為腳本內部新增的 no-commit 路徑，避免污染 git 歷史）
- [ ] FR-007（log 寫入）：驗證方式：上述 FR-006 拒絕觸發後 `tail -n 5 /home/admin/backups/code-revert-guard.log` 含當次時間戳與檔名
- [ ] 既有 Substitute 相關測試不引入新 failure：驗證方式：`cd backend && ./vendor/bin/phpunit --filter Substitute 2>&1 | tail -5` failure 數 ≤ 修前 baseline
- [ ] STRIDE 審查無 HIGH 風險：驗證方式：`[REVIEW]` Agent 對照本計畫第 9 節輸出 markdown 報告，無 `HIGH` 字串
- [ ] CHANGELOG 更新：驗證方式：`git diff docs/CHANGELOG.md | grep -E '^\+.*2026-04-21'` 至少回傳 1 行新增條目
- [ ] AI_REGRESSION_LESSONS 更新：驗證方式：`grep -c "nightly auto-backup" docs/AI_REGRESSION_LESSONS.md` 回傳 ≥ 1
- [ ] Health check 通過：驗證方式：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `{"status":"ok",...}` HTTP 200
- [ ] 抽樣驗證：驗證方式：以一位有當日代課記錄的原老師 token 呼叫 `GET /api/v1/attendance/ended-sessions?branch_id=<X>&start_date=<D>&end_date=<D>` 回傳 `data` 不含該被代課堂的 `class_session_id`
