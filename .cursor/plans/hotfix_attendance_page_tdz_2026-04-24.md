# Bug Fix Plan — 出缺勤管理頁面一片空白（TDZ）

> 狀態：DRAFT → IN PROGRESS
> 建立：2026-04-24
> 作者：AI Agent

---

## 0. 根因確認（Root Cause）

| 欄位 | 內容 |
|---|---|
| 嚴重度 | P0 |
| 根因類型 | 邏輯錯誤（JavaScript 初始化順序）|
| 根因摘要 | `AttendancePage.vue` 的 `quickForm` ref 在 line 1473 初始化時直接呼叫 `localTodayYmd()`，但 `localTodayYmd` 是 `const` arrow function，宣告在 line 1620（後面）→ JavaScript Temporal Dead Zone（TDZ）→ `ReferenceError: Cannot access 'localTodayYmd' before initialization`（minified 後為 `Xt`）→ Vue component `setup()` 拋出例外 → 整頁空白 |
| 錯誤行為 | 所有角色（teacher / director / super_admin）開啟出缺勤管理頁面時看到一片空白，瀏覽器 console 拋 `ReferenceError: Cannot access 'Xt' before initialization` |
| 預期行為 | 頁面正常渲染出勤資料、點名功能、日期篩選器 |
| 影響範圍 | 所有角色、所有分校，出缺勤管理功能完全不可用（P0 regression 由 PR #41 引入）|
| B1 偵查來源 | 本計畫完整整合 B1 偵查內容（browser console error + source diff + 宣告順序驗證）|

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 出缺勤管理（AttendancePage.vue）|
| 版本 | v2.0 hotfix（PR #45）|
| 狀態 | P0 Regression — 待修復 |
| 嚴重度 | P0 |
| 目標角色 | 所有角色（teacher / director / super_admin）|
| 關聯 Bug | 由 PR #41 `quickForm` 加 `date: localTodayYmd()` 引入 |

---

## 2. 業務背景與影響

**痛點**：出缺勤管理為日常核心功能，老師點名、管理員追蹤出缺勤全部依賴此頁面。頁面空白等同該功能整體停擺。

**錯誤行為（Before Fix）**：
- 任何角色點選「出缺勤管理」→ 瀏覽器拋 TDZ ReferenceError → Vue 元件 setup() 中止 → 整頁空白
- `version.json` 顯示 commit hash（`{"t":"2745f1f"}`），格式正確，不是問題所在

**修復後預期行為（After Fix）**：
- 頁面正常渲染：老師看到自己的點名清單、管理員看到最近 7 天出勤紀錄
- 瀏覽器 console 無 ReferenceError

---

## 3. 範圍

### In Scope
- `frontend/src/pages/AttendancePage.vue`：將 `localTodayYmd` 宣告移到首次使用前

### Out of Scope
- 不修改後端（`ScheduleController`、`AttendanceController`）
- 不修改其他前端頁面（`SchedulePage.vue`、`CoursePage.vue` 等）
- 不修改 `deploy.yml`
- 不修改資料庫 schema
- 不修改 `vite.config.js`（version.json 格式改變為預期行為，非 bug）

---

## 4. RACI

| 角色 | 負責事項 |
|---|---|
| AI Agent (R/A) | B1 偵查、修復、測試、CHANGELOG、PR、deploy |
| 管理員 (I) | 僅通知部署完成 |

---

## 4b. Dependencies

- 無前置 PR 或 migration 依賴
- 在 main branch 的 HEAD（2745f1f）上直接開 hotfix branch

---

## 5. Acceptance Criteria

### AC-001：頁面正常渲染
- AC-001-a：任何角色（teacher / director / super_admin）開啟出缺勤管理，頁面顯示正確 UI 元素（分校選擇器、出勤紀錄標題、日期篩選器），不出現空白頁面
- AC-001-b：瀏覽器 console 不出現 `ReferenceError` / `Cannot access ... before initialization`

### AC-002：功能回歸驗證
- AC-002-a：老師可見「待補點名」清單及「快速點名」功能
- AC-002-b：管理員/主任預設顯示「最近 7 天」出勤紀錄（PR #42 功能保留）

---

## 6. 功能需求 FR

- **FR-001**：修復後 `localTodayYmd` 函式應在 `quickForm` ref 初始化之前宣告，消除 JavaScript TDZ 錯誤
- **FR-002**：修復後所有在模組初始化時呼叫 `localTodayYmd()` 的 `const` 宣告（`quickForm`、`recordsDate`）應在 `localTodayYmd` 宣告之後才執行

---

## 7. 非功能需求 NFR

不適用。本 bug 為純 JavaScript 初始化順序問題，無效能、安全或可用性的非功能需求。

---

## 8. 技術方向

**涉及檔案**：`frontend/src/pages/AttendancePage.vue`

**修復策略**：
- 選擇 A（採用）：將 `localTodayYmd` 的 `const` arrow function 宣告從 line 1620 移到 line 1466（`quickMinDate` 之前），確保所有呼叫點在宣告之後
- 選擇 B（棄用）：改為 `function localTodayYmd()` function declaration — 雖有效（function declaration 在函式 scope 內 hoisting），但改變宣告形式，不如直接移位簡潔

**架構取捨理由**：
- 移位不改 API，不影響其他組件，最小範圍修改
- 業界最佳實踐（Vue 3 官方文件及 TheLinuxCode TDZ 指南）：在 `<script setup>` 中所有 `const`/`let` 宣告應依照使用順序由上而下排列

---

## 8b. Decision Log

| 日期 | 決定 | 替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-24 | 移動 `localTodayYmd` 宣告到首次使用前 | 改為 `function` declaration | 最小改動，符合 Vue 3 script setup 宣告順序最佳實踐 |

---

## 9. 資安與存取控制

不適用。本 bug 為純前端 UI TDZ 初始化問題，不涉及 auth token、角色邊界、PII 或 API 權限。

---

## 10. QA 驗收

### Happy Path
- [ ] 以 Super Admin 登入，點「出缺勤管理」→ 頁面正常顯示最近 7 天紀錄
- [ ] 切換「今天」模式 → 顯示當日紀錄

### Edge Case
- [ ] 無出勤紀錄的日期 → 顯示空清單，不崩潰

### Error Case
- [ ] 瀏覽器 console 應無 `ReferenceError`

### Revert-proof 驗證
- [ ] 在本地暫時把 `localTodayYmd` 移回 line 1620 後，在 browser devtools 重整 → 應看到 ReferenceError（確認此行宣告順序是 bug 根因）

---

## 11. 上線與維運

- 無 DB migration
- 有前端改動 → 需 `npm run deploy`
- 回滾方案：`git revert <merge-commit>` + re-deploy（< 5 分鐘）

---

## 12. 優先級

**P0 — 立即修復**（執行 Agent: AI Agent）

---

## 13. 風險 / 假設 / 開放問題

（基於 WebSearch 結果：TheLinuxCode TDZ Guide + Borstch Vue 3 Hoisting）

- **風險**：`localTodayYmd` 移位後，若有其他 `const` 宣告也在 `localTodayYmd` 之前呼叫它，同樣會 TDZ。本計畫已確認 `quickMinDate`（line 1467）不呼叫 `localTodayYmd`，移位後安全。
- **假設**：移位不影響 template 中的 `localTodayYmd()` 呼叫（template 呼叫在 setup 完成後，宣告順序無影響）
- **業界解法**：Vue 3 社群最佳實踐是在 `<script setup>` 依邏輯功能從上而下排列宣告（Reddit r/vuejs 討論串），工具類 helper（如 `localTodayYmd`）應置於最頂端或依賴它的 reactive state 之前

---

## 14. Definition of Done

- [ ] FR-001（TDZ 消除）：瀏覽器開啟出缺勤管理頁面，console 無 `ReferenceError`；驗證方式：`browser_console_messages` 回傳無 error 類型的 `ReferenceError`
- [ ] FR-002（宣告順序正確）：驗證方式：`grep -n "const localTodayYmd\|const quickForm\|const recordsDate" AttendancePage.vue` 中 `localTodayYmd` 行號 < `quickForm` 行號 < `recordsDate` 行號
- [ ] Revert-proof：暫時移回後確認 ReferenceError 重現，再移回正確位置
- [ ] CHANGELOG：`git diff docs/CHANGELOG.md` 含 `2026-04-24` 新增條目
- [ ] Health check：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `{"status":"ok",...}` HTTP 200
- [ ] version.json：`cat /home/admin/backend/public/version.json` 顯示新 commit hash（部署後更新）
