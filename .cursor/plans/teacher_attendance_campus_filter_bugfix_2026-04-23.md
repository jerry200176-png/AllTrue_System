# Bug Fix Plan：Teacher Attendance 分校過濾缺失

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1（資料可見性越界，director 可看到其他分校的老師出勤，但僅讀取，無寫入洩漏） |
| 根因類型 | Query 條件錯誤 + 前後端契約脫節 |
| 根因摘要 | `TeacherAttendanceController` 的 `index()` / `unclosed()` / `export()` / `exportMonthly()` 四個方法均接受 `campus_id`（或前端傳的 `branch_id`）但完全**忽略**該參數；後端改為以 `auth_campus_ids`（用戶所屬**所有**分校）過濾，導致多分校 director 無論前端選哪個分校都看到全部分校資料。次要漏洞：若 `auth_campus_ids = []`（UserCampus 無記錄），`!empty()` 判斷失效，非 super_admin 也能看到所有分校。 |
| 錯誤行為 | Director 在 UI 選定分校 A 後，`GET /api/v1/teacher-attendance` 仍回傳 director 被指派的所有分校的老師出勤記錄 |
| 預期行為 | 後端在 `auth_campus_ids` 已過濾的基礎上，再套用前端傳入的 `campus_id` 參數（必須是 `auth_campus_ids` 子集），使結果只顯示當前選定分校 |
| 影響範圍 | 被指派多分校的 director；或 UserCampus 無記錄的 director。super_admin 不受影響（設計上看全部）。前端 teacher tab 顯示、CSV 匯出、未簽退清單、月報匯出皆受影響。 |
| B1 偵查來源 | 本計畫整合 B1 內容（直讀 `TeacherAttendanceController.php` + `AttachAuthUser.php` + `AttendancePage.vue`） |

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | Teacher Attendance Campus Isolation Fix |
| 版本 | v1.0 |
| 狀態 | Draft |
| 嚴重度 | P1 |
| 目標角色 | Director（多分校） |
| 關聯 Bug | 出勤打卡無分校過濾（user report 2026-04-23） |

---

## 2. 業務背景與影響

**痛點**：director 在大安分校登入後，切換至「老師出勤」tab，能看到其他分校（如新生、信義）的老師打卡記錄，造成隱私問題與操作混亂。

**修復後預期行為**：
- director 僅能看到前端目前選定分校的老師出勤，即使帳號管理多分校
- 若未傳 `campus_id`，後端 fallback 為 `auth_campus_ids` 全部（維持現有多分校合法場景）
- 空 `auth_campus_ids` 的非 super_admin 用戶回傳 403，不再 bypass 過濾

---

## 3. 範圍

**In Scope**：
- 後端：`TeacherAttendanceController::index()` / `unclosed()` / `export()` / `exportMonthly()` — 加入 `campus_id` 參數過濾
- 後端：空 `auth_campus_ids` fallback 改為回傳 403（非 super_admin）
- 前端：`fetchTeacherRecords()` 傳 `campus_id`；`unclosed`、`export` endpoint 呼叫同步補上 `campus_id`

**Out of Scope**：
- `TeacherAttendanceController::today()` — 已有正確的 teacher self-isolation，不動
- `TeacherAttendanceController::adjust()` — 已有 `in_array($signin->CampusID, $campusIds)` 檢查，不動
- StudentAttendance / StudentSignIn 模組 — 不動
- UserCampus 資料修正（資料問題另案處理）
- 新增分校（Campus CRUD）— 不動
- 其他 Controller 的 campus 隔離 — 不在本次範圍

---

## 4. RACI

| 角色 | 任務 |
|---|---|
| R / A | AI Agent |
| I | 系統管理員（deploy 前知會） |

---

## 4b. Dependencies

無前置 PR / migration 前提。無 DB 結構異動。

---

## 5. Acceptance Criteria

### AC-001：`index()` 接受 `campus_id` 並驗證其屬於 auth_campus_ids
- AC-001-a：director（campusIds=[14,15]）呼叫 `GET /api/v1/teacher-attendance?campus_id=14`，回傳僅含 `CampusID=14` 的記錄
- AC-001-b：director 呼叫 `campus_id=99`（非所屬），後端回傳 403
- AC-001-c：director 不傳 `campus_id`，後端 fallback 為 `auth_campus_ids` 全部（現行行為保留）

### AC-002：空 `auth_campus_ids` 非 super_admin 回傳 403
- AC-002-a：director 帳號 UserCampus 無任何記錄，呼叫 `GET /api/v1/teacher-attendance`，後端回傳 403

### AC-003：`unclosed()` 同步套用 `campus_id` 過濾
- AC-003-a：director（campusIds=[14,15]）呼叫 `unclosed?campus_id=14`，回傳僅含 `CampusID=14` 的記錄

### AC-004：`export()` 同步套用 `campus_id` 過濾
- AC-004-a：director 呼叫 `export?campus_id=14&date_from=...&date_to=...`，CSV 結果只含分校 14 的記錄

### AC-005：`exportMonthly()` 同步套用 `campus_id` 過濾
- AC-005-a：director 呼叫 `export-monthly?campus_id=14&year_month=...`，XLSX 只含分校 14 的記錄

### AC-006：前端 fetchTeacherRecords 傳正確 campus_id
- AC-006-a：前端切換分校時，`teacher-attendance`、`unclosed` 兩個 fetch 都帶上 `campus_id=${branchId}`
- AC-006-b：`exportTeacherCsv()` 帶上 `campus_id=${branchId}`

### AC-007：super_admin 不受影響
- AC-007-a：super_admin 呼叫 `GET /api/v1/teacher-attendance` 不傳 `campus_id`，仍看到所有分校記錄（bypass 邏輯維持）

---

## 6. Functional Requirements (FR)

**FR-001**：`index()` 接受可選 `campus_id` (integer) 參數，若提供則驗證是否屬於 `auth_campus_ids`；驗證失敗回傳 403。

**FR-002**：`unclosed()` 同 FR-001。

**FR-003**：`export()` 同 FR-001。

**FR-004**：`exportMonthly()` 同 FR-001。

**FR-005**：非 super_admin 且 `auth_campus_ids = []` 時，上述所有方法一律回傳 403（代替無過濾的 bypass）。

**FR-006**：前端 `fetchTeacherRecords()` 的兩個 fetch 呼叫（`teacher-attendance` + `unclosed`）均傳遞 `campus_id=${props.branchId}`。

**FR-007**：前端 `exportTeacherCsv()` 傳遞 `campus_id=${props.branchId}`。

---

## 7. NFR

不適用。本 bug 屬邏輯過濾錯誤，無效能問題。所有修改均在已有索引欄位（`CampusID`）上新增 WHERE 條件，不引入新的 N+1 或全表掃描。

---

## 8. 技術方向

**涉及檔案**：
- `backend/app/Http/Controllers/TeacherAttendanceController.php`：`index()` / `unclosed()` / `export()` / `exportMonthly()` 各加一個 shared private helper `resolveEffectiveCampusIds(Request $request): array|Response`，處理：(1) 空 campusIds 403；(2) 傳入 campus_id 的驗證與過濾；(3) 未傳 campus_id 的 fallback。
- `frontend/src/pages/AttendancePage.vue`：`fetchTeacherRecords()` / `exportTeacherCsv()` 補 `campus_id` query param。

**架構取捨**：
- 採 private helper 而非 Middleware，因為只有 TeacherAttendanceController 需要這個語意（campus_id 子集驗證），其他 Controller 的 campus 隔離不在本次 scope。
- 業界標準（WebSearch 結論）：「Global scopes only protect Eloquent queries. Any raw SQL, DB::table() calls will not apply the tenant filter. Audit every raw query.」→ 本 Controller 全用 `DB::table()`，必須手動在每個 query 加 WHERE，不適合加 global scope。

---

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-23 | 後端加 private helper 處理 `campus_id` 驗證 | 前端過濾（不送後端） | 前端過濾不可信，安全邊界必須在後端 |
| 2026-04-23 | 空 campusIds 改回 403 | 保持現行 bypass | Bypass 是安全漏洞，多分校場景應強制選校 |
| 2026-04-23 | campus_id 為可選（未傳則 fallback 全部） | 必填 | 維持 super_admin 及合法多分校操作場景 |

---

## 9. 資安與存取控制

**觸發條件**：Bug 涉及角色邊界（director 可見性）。

**STRIDE 分析**：
- **I (Information Disclosure)**：當前 bug 的核心風險。Director 可讀取其他分校老師的出勤時間（屬 PII 範疇）。
- **E (Elevation of Privilege)**：空 campusIds 讓未被指派任何分校的 director bypass 隔離，看到全系統資料。
- 本次修復：FR-005 明確 403 杜絕 elevation；FR-001~004 的子集驗證杜絕 information disclosure。
- 不涉及寫入、token、RFID、LINE Webhook。

---

## 10. QA 驗收

### Happy Path
- [ ] director(campusIds=[14,15]) + campus_id=14 → 只看到分校 14 記錄
- [ ] director(campusIds=[14]) + 不傳 campus_id → 看到分校 14（fallback）
- [ ] super_admin + 不傳 campus_id → 看到所有記錄
- [ ] 前端切換分校 14 → API 帶 `campus_id=14`

### Edge Cases
- [ ] director + campus_id=99（非所屬）→ 403
- [ ] director + auth_campus_ids=[] → 403
- [ ] campus_id=0 → 403

### Error Cases
- [ ] 非 director/super_admin role 呼叫 index → 原有 role middleware 攔截（不在本 PR 處理，已有）

### Revert-proof 驗證
- [ ] `git stash` 後重跑新增測試，各 AC 至少 1 case failure（確認測試真正覆蓋了 bug）

---

## 11. 上線與維運

**Migration**：無。

**部署步驟**：
1. PR merge → `git pull`
2. `php artisan config:clear && php artisan route:clear`（config 和 route cache 清除）
3. 前端有改動：`npm run deploy` → 確認 `version.json` 更新
4. Health check

**Observability**：現有 `Log::info` 不需額外增加。403 回傳已足夠可見。

**回滾方案**：`git revert` PR commit，5 分鐘內可回滾。無 DB 異動，回滾零風險。

---

## 12. 優先級

**P1** — 涉及跨分校資料可見性，應在下一次部署窗口修復。執行 Agent：`[DEV]` → `[TEST]` → `[REVIEW]` → `[DOCS]` → `[OPS]`。

---

## 13. 風險 / 假設 / 開放問題

**WebSearch 結論（2026-04-23）**：業界標準（IGC, DEV.to, QCode）明確指出「DB::table() raw queries bypass Eloquent global scopes；每個 raw query 必須手動加 WHERE tenant_id」。本 bug 正是此問題的具體案例。修復方向與業界一致：在每個 query 手動注入 campus filter，並以 private helper 統一驗證邏輯。

**風險**：
- 若現有測試依賴「director 看全部分校」的行為（測試 setup 未設 campusId），新增 campus 驗證可能導致既有測試失敗 → 修改測試 setup 確保傳正確 campusIds。
- 403 for empty campusIds 可能影響尚未設定 UserCampus 的新 director 帳號 → 需在後台確保 director 帳號必有 UserCampus 記錄（Out of Scope，登記 TECH_DEBT）。

**假設**：`props.branchId` 在前端永遠與用戶目前選定的分校同步（已確認 watch 邏輯在 line 2263 正確觸發）。

---

## 14. Definition of Done（AI 可驗證）

- [ ] **FR-001**（index campus_id 過濾）：新增 test `director_cannot_see_other_campus_in_index` → CI green
- [ ] **FR-002**（unclosed campus_id 過濾）：新增 test `director_cannot_see_other_campus_in_unclosed` → CI green
- [ ] **FR-003**（export campus_id 過濾）：新增 test `director_cannot_export_other_campus` → CI green
- [ ] **FR-005**（empty campusIds 403）：新增 test `director_with_no_campus_gets_403` → CI green
- [ ] **FR-006/007**（前端帶 campus_id）：驗證方式：`grep "campus_id" frontend/src/pages/AttendancePage.vue` 含 fetchTeacherRecords + exportTeacherCsv + unclosed 三處
- [ ] **Revert-proof**：`git stash && CI run` 各新增 case 至少 1 failure
- [ ] **CHANGELOG**：`git diff docs/CHANGELOG.md` 含 `2026-04-23` fix 條目
- [ ] **AI_REGRESSION_LESSONS**：加入「teacher attendance campus filter」條目
- [ ] **Health check**：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `{"status":"ok",...}` HTTP 200
