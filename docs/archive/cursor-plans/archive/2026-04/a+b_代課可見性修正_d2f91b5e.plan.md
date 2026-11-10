---
name: A+B 代課可見性修正
overview: 修正兩個與代課可見性相關的 bug：(A) ClassSessionController 的 teacher_id query 參數衝堂檢查邏輯，以及 (B) AttendanceController::endedSessions 補點名的堂次級精確過濾。兩處皆需 regression test。
todos:
  - id: feature-a
    content: "[FEATURE] 改 ClassSessionController::index teacher_id query 參數過濾邏輯（lines 169-175）"
    status: completed
  - id: feature-b
    content: "[FEATURE] 改 AttendanceController::endedSessions，拆出 builder 並追加堂次級 whereExists 過濾"
    status: completed
  - id: feature-index
    content: "[FEATURE] 新增 schedules 表複合索引 migration（student_course_id, schedule_date, start_time, status, original_schedule_id）"
    status: completed
  - id: test-a
    content: "[TEST] 在 ClassSessionsTeacherVisibilityAfterSubstituteTest 追加 teacher_id query 參數的兩個 case"
    status: completed
  - id: test-b
    content: "[TEST] 新建 AttendanceEndedSessionsSubstituteTest.php，4 個 case 覆蓋正反向"
    status: completed
  - id: test-run
    content: "[TEST] 跑兩個 test 檔確認全綠，確認 revert-proof"
    status: completed
  - id: review-security
    content: "[REVIEW] 資安靜態審查：STRIDE 六維度與角色邊界確認"
    status: completed
  - id: review-code
    content: "[REVIEW] Code Review：逐條對照 FR-001～FR-005"
    status: completed
  - id: docs
    content: "[DOCS] 更新 CHANGELOG.md 與 AI_REGRESSION_LESSONS.md"
    status: completed
  - id: ops
    content: "[OPS] 跑 migration、清 Laravel cache、確認 health check"
    status: completed
isProject: false
---

# PRD — 代課老師可見性修正 A+B

## 1. 文件資訊

| 欄位 | 內容 |
|------|------|
| 功能名稱 | 代課老師可見性修正（Substitute Teacher Visibility Fix A+B） |
| 版本 | v1.0 |
| 狀態 | 待實作 |
| 目標角色 | 老師（Teacher）、主任（Director）、AI Agent |
| 關聯 Bug | B1（根因分析）、B2（已修 ClassSessionController::index role=teacher 分支）|
| 撰寫日期 | 2026-04-21 |

---

## 2. 目標與業務背景

**業務痛點：**

代課制度的核心語意是「指定老師代替原老師上某堂課」。現行系統在代課記錄存在後，原老師的手機「待點名」列表與「補點名」列表仍能看到被代課的課堂，造成：

- 原老師誤操作點名（與代課老師產生衝突）
- 代課老師查衝堂時誤以為被代課的堂次仍佔用原老師時段（CourseEditForm 衝堂檢查誤報）
- 補點名（ended-sessions）頁面原老師仍出現被代課堂次，代課老師又可能多看到非代課時段

**業務價值：**

確保「代課」操作的可見性邊界正確，使老師僅看到自己實際負責的課，降低誤操作與溝通成本。

**可量化 KPI：**

- 修復後：原老師請求 `GET /api/v1/class-sessions` 或 `GET /api/v1/attendance/ended-sessions` 時，被代課堂次不出現在回傳清單（0 筆錯誤資料）
- 修復後：代課老師可見到被指派的代課堂（1 筆正確資料）
- Regression test 全綠（2 個測試檔，6 個 case，0 failures）

---

## 3. 範圍

**In Scope：**

- (A) `ClassSessionController::index` 的 `teacher_id` query 參數過濾邏輯（衝堂檢查路徑）
- (B) `AttendanceController::endedSessions` 的補點名路徑（teacher role 時的堂次級過濾）
- 以上兩個修改所對應的 regression test
- `schedules` 表新增複合索引（支援 whereExists 子查詢效能）
- CHANGELOG 與 AI_REGRESSION_LESSONS 文件更新

**Out of Scope：**

- B2 已完成的 `ClassSessionController::index` `role === 'teacher'` 分支（已上線，本次不重複修）
- `AttendanceController::store`（點名寫入）的授權邏輯（另有 `isContractTeacher / isSubstituteTeacher` 保護，不在本次範圍）
- 前端 UI 視覺變更（純後端邏輯修正，前端無需改動）
- `director` 或 `super_admin` 角色的可見性（不受影響）

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|------|------|---------|
| AI Agent（實作） | `[FEATURE]` Agent | R |
| AI Agent（測試） | `[TEST]` Agent | R |
| AI Agent（審查） | `[REVIEW]` Agent | R |
| AI Agent（文件） | `[DOCS]` Agent | R |
| AI Agent（部署） | `[OPS]` Agent | R |
| 人類（可選閱讀） | CTO / 主任 | I |

---

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|------|------|------|
| 前置 PR | B2 修正（ClassSessionController role=teacher 分支）必須已上線 | 已完成 (2026-04-21) |
| DB Migration | 新增 schedules 複合索引（本次新建） | 待執行 |
| 外部服務 | 無 | — |
| 環境前提 | schedules 表存在（migration 2026_03_13_000013 已跑） | 已確認 |

---

## 5. User Stories

### US-A：主任查某老師衝堂

**As a** 主任，  
**I want** 在編輯課程時，衝堂檢查（`?teacher_id=X`）只列出該老師實際負責的堂次，  
**so that** 被代課的時段不被誤判為衝堂，允許安排其他課程。

**Acceptance Criteria：**

- AC-A1：`GET /api/v1/class-sessions?teacher_id=T1&start=DATE&end=DATE`，當 DATE 當天有代課記錄（sub_sched.teacher_id = T2），系統不回傳 T1 的被代課堂
- AC-A2：同一請求，系統回傳 T2 的被代課堂（teacher_id 欄位 = T2）
- AC-A3：T1 在同一天無代課的其他課堂仍正常出現

### US-B：老師查補點名列表

**As a** 老師，  
**I want** 「補點名」列表只顯示我實際授課的已結束未點名堂次，  
**so that** 不會看到自己被代課的堂次，也不會看到我只代某一堂時、那門課其他堂次的資料。

**Acceptance Criteria：**

- AC-B1：`GET /api/v1/attendance/ended-sessions`（teacher role），被代課堂不在回傳清單
- AC-B2：代課老師可見到被指派的已結束未點名代課堂
- AC-B3：無代課的契約堂次仍正常顯示
- AC-B4：代課老師不因為代過某課程，而看到該課程中其他非代課時間段的未點名堂

---

## 5b. UI/UX 精緻化需求

本次為純後端邏輯修正，無前端 UI 變更。以下頁面會間接受益但無需修改：

- `AttendancePage.vue`：補點名 tab 資料更正確，但 UI 結構與互動不變
- `CourseEditForm.vue`：衝堂提示邏輯不變，只是底層資料更正確

**不適用**：版面、色彩、互動、空狀態、loading、防呆、響應式、無障礙規格均不需調整。

---

## 6. 功能需求 FR

| # | 需求 | 可測試條件 |
|---|------|-----------|
| FR-001 | `ClassSessionController::index` 在 `teacher_id` query 參數過濾時，若堂次存在代課記錄（`sub_sched.teacher_id IS NOT NULL`），僅當 `sub_sched.teacher_id = filterTid` 時該堂命中；不命中 `sc.TeacherID = filterTid` | PHPUnit AC-A1、AC-A2 通過 |
| FR-002 | `ClassSessionController::index` 在 `teacher_id` query 參數過濾時，若堂次無代課記錄，仍命中 `sc.TeacherID = filterTid` | PHPUnit AC-A3 通過 |
| FR-003 | `AttendanceController::endedSessions` 在 teacher role 時，對每一個候選 ClassSession，系統應確認「該堂次的有效授課老師 = 請求者」才納入結果 | PHPUnit AC-B1、AC-B2 通過 |
| FR-004 | 同 FR-003，無代課記錄時，契約老師的堂次仍正常出現 | PHPUnit AC-B3 通過 |
| FR-005 | 同 FR-003，代課老師不因「曾代某課程某堂」而看到同課程其他非代課時段的已結束堂次 | PHPUnit AC-B4 通過 |
| FR-006 | `schedules` 表新增複合索引 `idx_schedules_course_date_time_status`，欄位順序：`(student_course_id, schedule_date, start_time, status, original_schedule_id)` | `SHOW INDEX FROM schedules` 回傳該索引 |

---

## 7. 非功能需求 NFR

| 面向 | 指標 | 降級策略 |
|------|------|---------|
| 效能 — (A) | `GET /api/v1/class-sessions?teacher_id=X` P95 < 500 ms（同 B2 前） | whereNull + where 為純 LEFT JOIN 欄位比較，無額外子查詢；效能影響極小 |
| 效能 — (B) | `GET /api/v1/attendance/ended-sessions` P95 < 800 ms（預設 7 天 / 50 筆） | whereExists 子查詢在 schedules 量少時（目前 335 筆）耗時 < 1 ms；複合索引 FR-006 為保障 |
| 索引 | whereExists 子查詢過濾欄位須有複合索引覆蓋 | 若 migration 失敗，查詢仍正確但較慢；可回退刪除索引而不影響正確性 |
| 向後相容 | director / super_admin 角色路徑的回傳結果不變 | 兩者不走 FR-003 的 whereExists 分支 |
| 資料正確性 | 修正後「漏顯示合法堂次」（false-negative）比「多顯示非授權堂次」（false-positive）更不可接受 | 測試 AC-A3、AC-B3、AC-B4 專門驗證不過度排除 |

---

## 8. 技術方向

**涉及檔案：**

- [backend/app/Http/Controllers/ClassSessionController.php](backend/app/Http/Controllers/ClassSessionController.php)：修改 lines 169–175 的 teacher_id 參數 WHERE 條件
- [backend/app/Http/Controllers/AttendanceController.php](backend/app/Http/Controllers/AttendanceController.php)：拆出 builder 並追加堂次級 WHERE 條件（lines 259–270 後段）
- 新建 migration：`schedules` 複合索引（無 down 風險，可直接 dropIndex 回退）
- [backend/tests/Feature/ClassSessionsTeacherVisibilityAfterSubstituteTest.php](backend/tests/Feature/ClassSessionsTeacherVisibilityAfterSubstituteTest.php)：追加兩個 case
- 新建 [backend/tests/Feature/AttendanceEndedSessionsSubstituteTest.php](backend/tests/Feature/AttendanceEndedSessionsSubstituteTest.php)：4 個 case

**架構取捨：**

- (A) 修法複雜度低，與已上線 B2 分支對稱，無額外 JOIN 或子查詢
- (B) 採「粗粒度 classIds 超集合 + 堂次級 whereExists 精確過濾」兩階段，而非直接重寫整個查詢，保留現有 Stop=0 / date range 索引命中路徑，僅在最後一層補精確條件
- 索引採複合索引而非單欄索引，避免 MySQL optimizer 在多欄過濾時選錯索引

**不做的事：**

- 不改 `$classIds` 計算邏輯（第一階段仍保留作粗粒度縮小，避免 whereExists 掃全表）
- 不改 director / super_admin 路徑（else 分支原樣保留）
- 不動前端

---

## 8b. Decision Log

| 日期 | 決策 | 考慮過的替代方案 | 選擇理由 |
|------|------|----------------|---------|
| 2026-04-21 | (A) 用 whereNull + where 對稱 B2 而非改寫為 JOIN | 改寫整段 query、用 CASE WHEN | 最小差異，降低 regression 風險；與已驗證的 B2 邏輯保持一致 |
| 2026-04-21 | (B) 追加 whereExists 而非重寫 classIds 計算 | 改 classIds 為堂次級查詢、改用 DB::table 純 SQL | 現有兩階段架構語意清楚；whereExists 是業界標準的存在性過濾模式（MySQL 8 文件建議） |
| 2026-04-21 | 新增複合索引 `(student_course_id, schedule_date, start_time, status, original_schedule_id)` 而非多個單欄索引 | 各欄單獨加索引 | MySQL optimizer 在多欄 AND 條件下複合索引命中率更高；schedules 目前只有 PRIMARY KEY，無任何覆蓋索引 |

---

## 9. 資安與存取控制

**角色邊界：**

- FR-001～FR-005 僅影響 `role === 'teacher'` 分支，不變更 director / super_admin 可見範圍
- `teacher_id` query 參數（FR-001、FR-002）由 director / super_admin 呼叫；teacher 自身不帶此參數（已由 middleware 綁定）

**STRIDE 快評：**

| 威脅 | 評估 |
|------|------|
| Spoofing | 不變：auth_teacher_id 由 middleware `AttachAuthUser` 從 token 解析，不接受前端偽造 |
| Tampering | 不變：whereExists 子查詢為純 read，不寫入 schedules |
| Repudiation | 不變：修正不影響現有 log 路徑 |
| Information Disclosure | 修正後**降低**風險：原老師無法再透過正常 API 讀到代課堂次資料 |
| Denial of Service | 低度新增：whereExists 子查詢需全表掃描時可能增加 DB 負載；複合索引（FR-006）作為緩解 |
| Elevation of Privilege | 不變：修正只收緊可見性，不新增任何寫入或管理權限 |

**PII：** 本修正不處理、不新增任何個資欄位。

---

## 10. QA 驗收

### Happy Path

- [ ] 原老師 GET class-sessions，被代課堂不出現（AC-A1）
- [ ] 代課老師 GET class-sessions，被代課堂出現，teacher_id 正確（AC-A2）
- [ ] 主任帶 teacher_id=T1 GET class-sessions，T1 無代課的其他堂仍出現（AC-A3）
- [ ] 原老師 GET ended-sessions，被代課的已結束堂不出現（AC-B1）
- [ ] 代課老師 GET ended-sessions，被代課的已結束堂出現（AC-B2）
- [ ] 原老師無代課的其他契約堂仍出現在 ended-sessions（AC-B3）
- [ ] 代課老師不因代過某課程而看到同課程其他時段（AC-B4）

### Edge Cases

- [ ] 同一課程同一日期有兩筆代課記錄（取最新，derived table MAX(id) 已保護）
- [ ] schedules 代課記錄存在但 status 不為 'scheduled'（不應命中，status='scheduled' 條件過濾）
- [ ] 代課期間過後，代課記錄 status 改為 'rescheduled' 或 'cancelled'（原老師應重新可見）
- [ ] super_admin 呼叫 ended-sessions（走 else 分支，不受 whereExists 影響）

### Error Cases

- [ ] teacher role 無法識別 teacher_id（teacherId = 0）→ 回傳空列表而非 500
- [ ] schedules 表為空（沒有任何代課記錄）→ whereExists 回傳 false，正常走 whereNotExists + 契約老師分支

### UI/UX 驗收清單

不適用（純後端修正，無前端變更）。

---

## 11. 上線與維運

**部署步驟：**

1. 執行 migration：`php artisan migrate`（新增 schedules 複合索引）
2. 清 Laravel cache：`php artisan config:clear && php artisan route:clear`
3. 無前端變更，不需執行 `npm run deploy`
4. 確認 health check：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `{"status":"ok",...}`

**Feature Flag 策略：**

無 feature flag。本修正為純後端可見性收緊（不新增 UI 入口），部署即全量生效。回退成本低（git revert 即可），無需分階段上線。

**Observability：**

| 監控項目 | 指標 / log 關鍵字 | 告警閾值 | 負責 Agent |
|---------|-----------------|---------|-----------|
| ended-sessions 回應時間 | `storage/logs/*.log` 中 `ended-sessions` 慢查詢 | P95 > 2000 ms 連續 3 分鐘 | `[OPS]` |
| class-sessions teacher_id 回應時間 | Apache access log, endpoint `class-sessions` | P95 > 1000 ms | `[OPS]` |
| 代課堂次誤出現（false-positive） | 上線後人工抽樣 1 位原老師 × 1 天 | 任何被代課堂出現 | `[OPS]` |

**回滾：**

- `git revert <commit-hash>`（僅 PHP 檔案，無資料異動）
- Migration 回退：`php artisan migrate:rollback`（dropIndex 即可，不影響資料）
- 預估回滾時間：< 5 分鐘

---

## 12. 里程碑與優先級

| 優先級 | 項目 | 執行 Agent |
|--------|------|-----------|
| P0 | FR-001, FR-002: ClassSessionController teacher_id 過濾 | `[FEATURE]` |
| P0 | FR-003, FR-004, FR-005: endedSessions 堂次級過濾 | `[FEATURE]` |
| P0 | Test AC-A1～AC-A3, AC-B1～AC-B4 全綠 | `[TEST]` |
| P1 | FR-006: schedules 複合索引 migration | `[FEATURE]` |
| P1 | STRIDE 審查 | `[REVIEW]` |
| P1 | Code Review 逐條對照 FR | `[REVIEW]` |
| P2 | CHANGELOG / AI_REGRESSION_LESSONS 更新 | `[DOCS]` |
| P2 | health check 與上線確認 | `[OPS]` |

---

## 13. 風險 / 假設 / 開放問題

### 風險

| 風險 | 等級 | 業界標準解法（來源） | 本專案採行方式 |
|------|------|--------------------|--------------| 
| whereExists 子查詢在 schedules 資料量增長後效能劣化 | 中 | 複合索引覆蓋過濾欄位（MySQL 官方文件 8.4、Medium @zgza778 — Laravel whereExists 效能最佳化） | FR-006 複合索引；schedules 目前 335 筆，未來增長仍在可控範圍 |
| MySQL 8.0 EXISTS semi-join 優化器迴歸（SO #72644205） | 低 | 設 `semijoin=off` 或改用 `JOIN` 替代 | 本機為 MariaDB 10.11，不受 MySQL 8.0 迴歸影響；[AI-RESOLVABLE] 若升級至 MySQL 8 再評估 |
| 原老師被代課後，代課記錄若遭意外刪除 | 低 | 軟刪除或 audit log（Clever 建議 identity audit trail） | 現有 schedules status 欄位管控；無代課記錄時自動回退至契約老師可見（邏輯安全） |
| (B) whereNotExists 與 whereExists 的 classIds 超集合假設失效 | 低 | 使用 single-pass SQL（一次 join 取代兩階段） | classIds 超集合透過 contractClassIds + subClassIds merge 計算，邏輯等價；測試 AC-B3、AC-B4 驗證 |

### 假設

- `schedules.status = 'scheduled'` 且 `original_schedule_id IS NOT NULL` 是判斷「有效代課記錄」的唯一且完整條件（若假設不成立，`[AI-RESOLVABLE]`：查 SubstituteService 的寫入邏輯確認）
- `schedules.start_time` 格式為 `HH:MM`（5 字元），與 `ClassSession.StartTime` 前 5 字元對齊（已驗證於 B2 migration）

### 開放問題

- `[AI-RESOLVABLE]` 代課記錄 undo（取消代課）後，schedules 列的 status 如何變化？若改為 'cancelled' 則不命中 `status='scheduled'`，原老師自然重新可見，需驗證 SubstituteController::undo 的寫法

---

## 14. Definition of Done

- [ ] FR-001 (A 衝堂過濾)：驗證方式：`./vendor/bin/phpunit tests/Feature/ClassSessionsTeacherVisibilityAfterSubstituteTest.php` 回傳 `OK (N tests, M assertions)`，N ≥ 4，0 failures
- [ ] FR-003～FR-005 (B 補點名過濾)：驗證方式：`./vendor/bin/phpunit tests/Feature/AttendanceEndedSessionsSubstituteTest.php` 回傳 `OK (4 tests, ≥ 8 assertions)`，0 failures
- [ ] Revert-proof：git stash 後重跑兩個測試檔，至少各 1 個 case failure（確認測試有真正覆蓋 bug）
- [ ] FR-006 (複合索引)：驗證方式：`php artisan tinker` 執行 `DB::select('SHOW INDEX FROM schedules')` 回傳含 `idx_schedules_course_date_time_status` 的條目
- [ ] 現有 Substitute 相關測試無新增 failure：驗證方式：`./vendor/bin/phpunit --filter Substitute` 與修前失敗數相同（pre-existing failures 不算）
- [ ] STRIDE 審查無 HIGH 風險：驗證方式：`[REVIEW]` Agent 逐條對照第 9 節，回傳無 HIGH 項目
- [ ] CHANGELOG 更新：驗證方式：`git diff docs/CHANGELOG.md` 含 `2026-04-21` 的新增條目
- [ ] Health check 通過：驗證方式：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `{"status":"ok",...}` HTTP 200