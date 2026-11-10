# Bug Fix Plan — 出勤頁預設只顯示今天，管理員無法查看歷史到班紀錄

| 欄位 | 內容 |
|---|---|
| 狀態 | 草稿 2026-04-24 |
| 關聯 PR | fix/attendance-range-view（本次） |
| 前置 PR | #41 fix/makeup-attendance-flow（已 merge） |

---

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P2 |
| 根因類型 | Query 條件錯誤（前端寫死 date=今天；後端不支援日期區間） |
| 根因摘要 | `AttendancePage.vue fetchRecords` 固定傳 `date: recordsDate.value`（預設今天），`AttendanceController::index` 也只支援單日 `date` 參數，導致管理員開頁面永遠只看到今天的出缺勤紀錄 |
| 錯誤行為 | 管理員開出勤頁面，「出缺勤紀錄」區塊只顯示今天有 SignInDT 的 `StudentSingIn` 列；昨天或更早已到班的學生完全不可見，必須手動改日期選擇器一天一天查 |
| 預期行為 | 管理員開頁面預設看到最近 7 天的紀錄（含已到班）；可切換到指定單日查詢；老師視角維持預設今天（只看自己的課） |
| 影響範圍 | 角色：director、super_admin；API：`GET /api/v1/attendance`；元件：`AttendancePage.vue fetchRecords` |
| B1 偵查來源 | 本計畫 B1（程式碼審查 AttendanceController.php L97–102 + AttendancePage.vue fetchRecords） |

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 出勤頁歷史紀錄查詢 |
| 版本 | v1.1（接續 PR #41） |
| 嚴重度 | P2（管理員可用性缺陷，不影響資料正確性） |
| 目標角色 | director、super_admin（管理員）；teacher（老師，預設行為維持不變） |
| 關聯 Bug | 設計限制延伸自 PR #41 bugfix_makeup_class_session_missing_2026-04-24.md §設計限制 |

---

## 2. 業務背景與影響

**痛點**：管理員要確認昨天楊老師是否已幫薛米亞補點，需要在出勤頁手動把日期選擇器改成昨天才看得到。若忘記改回，接著又看不到今天紀錄。業界主流系統（PowerSchool、TimeClock 365、Emgage HRMS）預設均顯示最近 7–14 天的紀錄，支援日期區間篩選。

**修復後預期行為**：
- 管理員開出勤頁 → 預設看最近 7 天所有 `StudentSingIn`（含 present / late / absent / leave）
- 日期選擇器改為「開始日」+「結束日」區間，或保留單日切換模式
- 老師開出勤頁 → 預設行為不變（仍傳 `date: 今天`，只看自己課程）

---

## 3. 範圍（Scope）

### In Scope
- `AttendanceController::index`：新增 `start_date` / `end_date` 區間參數；無參數時預設最近 7 天
- `AttendancePage.vue fetchRecords`：管理員改傳 `start_date` + `end_date`，預設最近 7 天；老師維持 `date: today`
- `AttendancePage.vue` 模板：管理員「出缺勤紀錄」標頭加日期區間顯示與快捷按鈕（今天 / 最近 7 天）

### Out of Scope
- `fetchPendingSessions`（待補點名）：邏輯獨立，不改
- `AttendanceController::store / update / destroy`：不改
- `StudentSingIn` 補充 leave-session 查詢（僅在單日 `date` 模式下執行，區間模式跳過）
- 其他頁面（LearningRecordsPage、TeacherHomePage 等）：不動
- DB migration：不需要

---

## 4. RACI

| 工作 | R | A | C | I |
|---|---|---|---|---|
| Bug Fix Plan | AI Agent | AI Agent | — | Jerry |
| 後端 + 前端修復 | AI Agent | AI Agent | — | Jerry |
| Regression Tests | AI Agent | AI Agent | — | Jerry |
| CHANGELOG / Lessons | AI Agent | AI Agent | — | Jerry |
| PR merge + deploy | AI Agent | AI Agent | — | Jerry |

### 4b. Dependencies

- PR #41 已 merge（`fix/makeup-attendance-flow`）：本次 branch 從 main 分出 ✅
- 無 DB migration
- 無其他前置 PR

---

## 5. Acceptance Criteria

### AC-001：管理員預設看最近 7 天

- AC-001-a：管理員開出勤頁（不傳任何 date 參數），`GET /api/v1/attendance` 後端回傳最近 7 天的 `StudentSingIn` 紀錄（含今天）
- AC-001-b：若過去 7 天某日有 present 紀錄，該紀錄出現在回傳清單中（不限今天）

### AC-002：管理員可用 start_date / end_date 區間查詢

- AC-002-a：`GET /api/v1/attendance?start_date=2026-04-20&end_date=2026-04-22` 僅回傳 SignInDT 在 4/20–4/22 之間的紀錄
- AC-002-b：超出區間的紀錄不出現在回傳中

### AC-003：老師預設行為不改

- AC-003-a：老師以 `date: 今天` 呼叫 API，仍只看到自己今天的課（TeacherID 過濾不受影響）
- AC-003-b：老師不傳 `date`，後端回傳最近 7 天（老師的），不影響點名流程

### AC-004：前端管理員預設顯示區間

- AC-004-a：管理員開頁面，「出缺勤紀錄」區塊預設傳 `start_date=7天前&end_date=今天`，頁面顯示最近 7 天紀錄
- AC-004-b：點「今天」快捷鈕，切換為只傳 `date: 今天`，頁面只顯示今天紀錄

---

## 6. 功能需求 FR

### FR-001：後端支援 start_date / end_date
修復後 `AttendanceController::index` 應：
- 若 `date` 有值 → 沿用現有單日過濾（向下相容）
- 若 `start_date` 或 `end_date` 有值 → 以 `DATE(si.SignInDT) BETWEEN start_date AND end_date` 過濾
- 若三者皆空 → 自動套用 `start_date = today-6, end_date = today`（最近 7 天）

### FR-002：前端管理員預設最近 7 天
修復後 `fetchRecords`（管理員分支）應：
- 預設傳 `start_date=today-6&end_date=today`
- 提供「今天」快捷切換（傳 `date: today`）與「最近 7 天」快捷切換（傳 `start_date/end_date`）

### FR-003：老師分支不變
老師呼叫 `fetchRecords` 仍傳 `date: quickForm.value.date`（今天），不受影響。

---

## 7. 非功能需求 NFR

不適用。本次修復屬 Query 條件擴充，不涉及效能瓶頸；後端 `si.SignInDT` 欄已有索引，增加 BETWEEN 條件不影響查詢效率。

---

## 8. 技術方向

### 後端（`AttendanceController::index`）
在現有 `if ($request->filled('date'))` 區塊下新增 `elseif` 處理 `start_date/end_date`；再加 `else` 寫死最近 7 天。補充 leave 查詢區塊維持只在 `date` 有值時執行。

### 前端（`AttendancePage.vue`）
- 新增 `recordsMode` ref（`'week'` | `'day'`），預設 `'week'`（管理員）
- `fetchRecords` 依 `isTeacher` 決定傳 `date` 或 `start_date/end_date`
- 模板：管理員出缺勤紀錄標頭加兩個快捷按鈕（今天 / 最近 7 天）

### 8b. Decision Log

| 日期 | 決策 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-24 | 無參數預設 7 天（後端） | 無參數回全部資料 | 全部資料可能達萬筆，影響效能與 UI 可讀性 |
| 2026-04-24 | 老師維持 date=today | 老師也預設 7 天 | 老師通常只看今天課；改變預設恐影響快速點名體驗 |
| 2026-04-24 | 快捷鈕「今天 / 最近 7 天」 | 完整日期區間選擇器 | 管理員最常用的查詢就是「今天」或「最近一週」，兩鈕可覆蓋 90% 使用情境 |

---

## 9. 資安與存取控制

不適用。本次改動不涉及 auth middleware、PII、RFID 或 webhook；API 現有角色過濾（`branch_id`、`TeacherID`）維持不變，date range 只改「時間窗口」，不影響資料所有權邊界。

---

## 10. QA 驗收

### Happy Path
- [ ] 管理員不帶 date 呼叫 API → 回傳 7 天紀錄
- [ ] 管理員帶 `start_date=4/20&end_date=4/22` → 只回傳該區間
- [ ] 管理員單日 `date=4/23` → 只回傳 4/23（向下相容）
- [ ] 老師帶 `date=today` → 只看到自己今天的課

### Edge
- [ ] `start_date > end_date` → 回傳空陣列，不報 500
- [ ] 無任何 date 參數 + 老師角色 → 回傳老師最近 7 天的記錄（TeacherID 過濾不受影響）

### Error
- [ ] `start_date` 格式錯誤（非日期字串）→ 後端回 422

### Revert-proof 驗證
- [ ] `git stash` 後重跑新增測試，至少 1 case failure（確認測試真正覆蓋 bug）

---

## 11. 上線與維運

- **Migration**：無
- **部署步驟**：push feature branch → CI 綠 → merge → deploy.yml 自動跑 → health check
- **Observability**：無需新增 log；現有 `/api/v1/health` 足夠
- **回滾**：若出問題 `git revert <merge-commit>` → push → auto-deploy（預計 < 5 分鐘）

---

## 12. 優先級

| 優先級 | 執行 Agent |
|---|---|
| P2 | AI Agent [DEV] + [TEST] + [DOCS] + [OPS] |

---

## 13. 風險 / 假設 / 開放問題

**WebSearch 業界解法**（已查詢）：
- **PowerSchool**：管理員無日期限制，老師預設 14 天回溯視窗
- **TimeClock 365**：提供「today / this week / last week / custom range」快捷鈕，預設 this week
- **Emgage HRMS**：From Date + To Date 必填，無預設區間（需使用者主動選）

**結論**：業界主流偏向「管理員看一週、提供快捷鈕」，本計畫採用此方向。

**風險**：
- 管理員分校資料量若超過 200 筆 / 7 天，`per_page=200` 可能截斷 → 可接受（現有限制，非本次範圍）
- 前端 30 秒 auto-refresh 會定期重新 fetch 7 天資料 → 無效能疑慮（單次 query + pagination）

---

## 14. Definition of Done

- [ ] FR-001（後端 date range）：`php artisan test --filter AttendanceRangeTest` 全綠
- [ ] FR-002（前端預設 7 天）：管理員開頁面，Network 面板確認請求帶 `start_date` + `end_date`
- [ ] FR-003（老師不變）：老師開頁面，Network 確認請求帶 `date: 今天`
- [ ] Revert-proof：`git stash && php artisan test --filter AttendanceRangeTest` 至少 1 failure
- [ ] CHANGELOG：`git diff docs/CHANGELOG.md` 含 `2026-04-24` 新條目
- [ ] Health check：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `{"status":"ok",...}`

---

## Todos

- [DEV] FR-001：`AttendanceController::index` 加 `start_date`/`end_date`/預設 7 天
- [TEST] 新增 `AttendanceRangeTest.php`（RED → GREEN）
- [DEV] FR-002：`AttendancePage.vue fetchRecords` 管理員改傳區間 + 快捷鈕
- [REVIEW] 逐條對照 FR-001～003
- [DOCS] CHANGELOG + AI_REGRESSION_LESSONS
- [OPS] PR merge → deploy → health check
