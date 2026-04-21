---
name: Teacher Overdue Learning Reminder
overview: 在教學工作台新增「補填提醒」區塊，讓老師一眼看到過去 7 天已上課但尚未填寫評量表的堂次，並可直接從工作台點擊跳轉填寫。
todos:
  - id: fetch-overdue
    content: 在 TeacherHomePage.vue 新增 fetchOverdueLearning()，查詢過去 7 天 attended + missing 的堂次，並行 fetch 所有 teacher branches
    status: completed
  - id: update-count
    content: 更新 pendingLearningCount 計算邏輯，加入 overdueRecords.length，並改進按鈕標籤顯示今日 vs 過往分拆
    status: completed
  - id: overdue-section
    content: 新增「補填提醒」section 模板，顯示最多 5 筆，含填寫按鈕與查看全部連結，v-if 條件控制顯示/隱藏
    status: completed
  - id: refresh-polling
    content: 更新 refreshAll、polling interval、onMounted 以包含 fetchOverdueLearning
    status: completed
  - id: style
    content: 為補填提醒區塊加入 scoped CSS，橘色 left-border 風格，與現有 card 一致
    status: completed
  - id: deploy
    content: 執行 npm run deploy 上線
    status: completed
isProject: false
---

# PRD：教學工作台補填評量提醒

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 教學工作台－過往未填評量補填提醒 |
| PRD 版本 / 日期 | v1.0 / 2026-04-16 |
| 作者（PM） | AllTrue PM |
| 狀態 | Draft |

---

## 2. 目標與業務背景

**問題陳述**
老師登入教學工作台後，「今日待辦」只計算今天的評量缺漏。過去幾天已實際上課（`attended`）但未填評量的堂次，在工作台完全不可見，導致老師不知道有補填責任，造成主任側看到評量完成率偏低、家長無法及時收到學習回饋。

**影響角色**
- **老師**：主動使用者，需被提醒補填
- **主任**：評量審核佇列積壓，影響管理效率
- **家長**：收不到評量通知，家長入口資訊不完整

**成功指標**
- 老師平均評量補填延遲天數（`attended` → `approved`）從目前基準降低 ≥ 50%（上線後 4 週量測）
- 工作台「補填提醒」區塊點擊率（前往填寫）≥ 40%（有提醒時）
- 評量待審佇列中 > 3 天未填比例降至 < 10%

---

## 3. 範圍

**In Scope**
- 在 `TeacherHomePage.vue` 新增「補填提醒」區塊
- 過去 7 天內 `attended` 且 `learning_record_status = missing` 的堂次
- 今日待辦「評量」按鈕數字合計含過往
- 每筆顯示日期、學生名、科目、分校、一鍵跳轉填寫

**Out of Scope**
- LINE / 推播通知提醒（另立需求）
- 後端新增 API 或資料表（沿用現有 `GET /api/v1/class-sessions`）
- 主任側提醒老師補填（另立需求）
- 7 天以上的歷史補填（本次不涵蓋，避免資料量爆炸）
- 評量頁（`LearningRecordsPage.vue`）UI 異動

---

## 4. 利害關係人與 RACI

| 角色 | 負責人 | RACI |
|---|---|---|
| PM | AllTrue PM | A |
| 工程（前端） | 前端工程師 | R |
| QA | QA 負責人 | R |
| 資安 | — | C |
| IT / Ops（Raspberry Pi 部署） | IT 維運 | I |

---

## 5. User Stories

**US-01：補填提醒區塊**
> As a **老師**，I want **登入工作台後直接看到過去 7 天哪些已上課的堂次還沒填評量表** so that **我不需要自己回想或翻課表，主動知道需要補填**。

Acceptance Criteria：
- [ ] 工作台「今日待辦」下方出現「補填提醒」區塊，列出 ≤ 5 筆最近未填堂次
- [ ] 每筆顯示：日期（含星期）、學生姓名、科目、分校 chip
- [ ] 每筆有「填寫」按鈕，點擊後跳轉至評量頁該筆位置
- [ ] 若超過 5 筆，顯示「查看全部 X 筆」連結
- [ ] 若無過往未填，此區塊完全不顯示（畫面保持乾淨）

**US-02：今日待辦數字合計**
> As a **老師**，I want **今日待辦的評量數字包含過往未填** so that **我一眼就知道整體還欠幾筆，不會誤以為只有今天的才重要**。

Acceptance Criteria：
- [ ] 評量 CTA 按鈕標籤：只有今日時顯示「待填 N 筆」，含過往時顯示「待填 N 筆（含過往 M 筆）」
- [ ] 今日全部填完但有過往未填時，按鈕不顯示「今日評量已完成」，而是顯示過往數字

**US-03：跨分校補填**
> As a **跨分校授課的老師**，I want **補填提醒涵蓋我所有任教分校的未填堂次** so that **不會漏掉他校的補填責任**。

Acceptance Criteria：
- [ ] 補填提醒列表包含老師所有 `teacherBranchIds` 分校的未填記錄
- [ ] 每筆有分校 chip 顯示，點擊填寫時自動切換至對應分校

---

## 6. 功能需求（FR）

- **FR-001**：系統應在老師進入工作台後，自動查詢過去 7 天（含 7 天前當日、不含今日）所有 `status = attended` 且 `learning_record_status = missing` 的堂次。
- **FR-002**：系統應合併老師所有任教分校的查詢結果，依 `session_date DESC` 排序，取前 5 筆顯示於「補填提醒」區塊。
- **FR-003**：「補填提醒」區塊應以 `v-if` 控制，`overdueRecords.length === 0` 時完全不渲染。
- **FR-004**：每筆提醒列應顯示：`session_date`（格式：M/D 週X）、`student_name`、科目（`subject`）、分校 chip（`branch_id` 對應色）。
- **FR-005**：每筆提醒列應有「填寫」按鈕，點擊後執行 `goFillRecord(ev)`（現有邏輯）跳轉至評量頁。
- **FR-006**：當 `overdueRecords.length > 5` 時，顯示「查看全部 X 筆」按鈕，點擊後 `emit('navigate', 'learning')`。
- **FR-007**：「今日待辦」評量 CTA 按鈕的 `pendingLearningCount` 應加入 `overdueRecords.length`，標籤文字區分今日與過往（見 US-02 AC）。
- **FR-008**：`fetchOverdueLearning()` 應在 `onMounted`、`refreshAll()`、每分鐘 polling 時被呼叫。
- **FR-009**：跨分校查詢採並行 `Promise.allSettled`，單一分校失敗不影響其他分校結果顯示（與現有 `loadWeekSchedule` 一致）。

---

## 7. 非功能需求（NFR）

- **效能**：`fetchOverdueLearning()` 所有分校查詢並行完成，目標 < 1500ms（正常網路環境）；查詢以 `per_page=200` 限制，單次不超過 200 筆。
- **可靠性**：查詢失敗時靜默忽略（不顯示錯誤訊息到畫面），不影響今日待辦或週課表正常載入。
- **UX 回應**：載入中顯示 skeleton placeholder（與現有週課表 loading 風格一致）。
- **可觀測性**：失敗時在 `console.warn` 輸出，供開發階段除錯；不需新增後端 log。

---

## 8. 技術設計方向（for CTO / 工程）

**受影響檔案**
- [`frontend/src/pages/TeacherHomePage.vue`](frontend/src/pages/TeacherHomePage.vue)（唯一需改動的檔案）

**API 使用**
現有 `GET /api/v1/class-sessions` 已支援：
- `?start=YYYY-MM-DD&end=YYYY-MM-DD`：日期範圍篩選
- `?learning_record_status=missing`：後端直接過濾（`ClassSessionController` line 175-176）
- 回傳欄位已含 `learning_record_id`、`learning_record_status`、`student_name`、`subject`、`branch_id`

**後端改動：無**

**前端邏輯異動摘要**

```
新增 ref：
  overdueRecords = ref([])
  loadingOverdue = ref(false)

新增 computed：
  overdueCount = computed(() => overdueRecords.value.length)
  todayOnlyMissingCount  ← 從現有 pendingLearningCount 拆分

新增 function：
  fetchOverdueLearning()  ← 並行 fetch 所有 branches，篩 attended+missing，排序

修改：
  pendingLearningCount  ← 加入 overdueCount
  refreshAll()          ← 加入 fetchOverdueLearning()
  startPolling()        ← 加入 fetchOverdueLearning()
  onMounted()           ← 加入 fetchOverdueLearning()
```

**AllTrue Agent 分派**
- 前端 Vue 實作 → `[FEATURE]`
- 測試設計 → `[TEST]`

---

## 9. 資安與合規（for 資安）

- **存取控制**：`GET /api/v1/class-sessions` 已有 `role:teacher` middleware 保護，老師只能取得自己 `TeacherID` 對應的堂次，無需額外修改。
- **資料保護**：顯示欄位（學生姓名、科目）均為現有老師可存取的 PII，無新增 PII 暴露面。
- **多校區隔離**：查詢以 `branch_id` 分開發送，後端 `require_campus` middleware 確保老師只能查詢所屬校區，前端不做跨校合併展示（只是 UI 排列）。
- **稽核 log**：本功能為查詢展示，無寫入操作，不需新增稽核 log。
- **STRIDE 速覽**：無新增攻擊面（純呼叫現有 API、純前端渲染）。

---

## 10. QA 驗收標準與測試計畫（for QA）

| FR | 測試案例 | 類型 | Pass 條件 |
|---|---|---|---|
| FR-001 | 老師有過去 3 天 attended+missing 的堂次 | 手動 UAT | 補填提醒區塊出現，列出正確筆數 |
| FR-001 | 老師過去 7 天無任何 attended+missing | 手動 UAT | 補填提醒區塊完全不顯示 |
| FR-002 | 老師在 2 間分校各有 3 筆未填 | 手動 UAT | 合計顯示 5 筆（截斷），有分校 chip 區別 |
| FR-003 | 補填清零後重新整理 | 手動 UAT | 區塊消失，畫面不留空白 |
| FR-004 | 每筆顯示正確日期格式 | 手動 UAT | 「4/14 週一」格式，無錯誤顯示 |
| FR-005 | 點擊「填寫」 | 手動 UAT | 跳轉至評量頁對應筆記，分校正確切換 |
| FR-006 | 超過 5 筆時 | 手動 UAT | 顯示「查看全部 X 筆」，X 數字正確 |
| FR-007 | 今日 2 筆 + 過往 3 筆 | 手動 UAT | 按鈕顯示「待填 5 筆（含過往 3 筆）」 |
| FR-007 | 今日 0 筆 + 過往 2 筆 | 手動 UAT | 按鈕不顯示「今日評量已完成」，顯示過往數 |
| FR-008 | 點擊「重新整理」 | 手動 UAT | 補填提醒同步刷新 |
| FR-009 | 其中一個分校 API 500 | 手動（Mock） | 其他分校結果仍顯示，不顯示錯誤訊息 |
| — | 回歸：今日待辦點名數不受影響 | 手動 UAT | 點名數字與補填無關，各自獨立 |
| — | 回歸：週課表顯示不受影響 | 手動 UAT | 週課表 `formStatus` chip 正常顯示 |

**Edge Cases 特別注意**
- 老師所有 `teacherBranchIds` 為空時（單校老師），不應觸發並行 fetch，改走單一 `branchId` 路徑
- 同一堂次因 LEFT JOIN 導致重複（`normalizeClassSessionsPayload` 已有 id dedup，應無此問題，QA 需確認）

---

## 11. 上線與維運計畫（for IT / Ops）

**部署步驟**
1. 程式碼合併到 `jerry-sync-main`
2. 在 Raspberry Pi 執行：`cd /home/admin/frontend && npm run deploy`（`vite build` + 複製到 `backend/public`）
3. 確認 `backend/public/index.html` 與 `assets/` chunk 已同步更新（防止 MIME 錯誤，見 `docs/AI_REGRESSION_LESSONS.md`）
4. 在瀏覽器硬重新整理（Ctrl+Shift+R），確認工作台補填區塊正常顯示

**不需要的步驟**（確認）
- 無 migration、無後端 config 異動、無環境變數異動

**監控**
- 無需新增 metric/alert，沿用現有 API 錯誤監控
- 上線後前 3 天，請主任觀察評量佇列積壓是否有改善

**回滾方案**
- 若出現問題，在 Raspberry Pi 執行 `git revert <commit>` 後重新 `npm run deploy` 即可，無資料庫變動無需額外回滾

---

## 12. 里程碑與優先級

| 優先級 | 項目 | 預估工期 | 負責 |
|---|---|---|---|
| P0（Must） | `fetchOverdueLearning()` + 資料合併 | 1h | `[FEATURE]` 前端 |
| P0（Must） | 補填提醒 section 模板 + 樣式 | 1h | `[FEATURE]` 前端 |
| P0（Must） | 今日待辦數字合計更新 | 0.5h | `[FEATURE]` 前端 |
| P1（Should） | polling / refreshAll 整合 | 0.5h | `[FEATURE]` 前端 |
| P1（Should） | QA 驗收測試（手動） | 1h | QA |
| P2（Nice-to-have） | Pest Feature Test 補填計數 | 1h | `[TEST]` |

---

## 13. 風險、假設、依賴、開放問題

**風險**
- **中**：老師過去 7 天課量大（如 > 100 堂），`per_page=200` 上限可能截斷；緩解：本版本截斷不影響功能（提醒仍有），後續可調整上限或新增專屬 API
- **低**：`class-sessions` API 回傳的 `learning_record_status` 若因 LEFT JOIN 重複導致誤判，已有 `normalizeClassSessionsPayload` id dedup 防護

**假設**
- 假設 `GET /api/v1/class-sessions?learning_record_status=missing` 後端過濾有效（已確認 `ClassSessionController` line 175-176 支援）
- 假設老師 `teacherBranchIds` prop 由 `App.vue` 正確傳入（現有行為）
- 假設「過去 7 天」足夠覆蓋大多數補填場景（使用者確認）

**依賴**
- 無外部依賴，所有資料均來自現有 API

**開放問題**
- [ ] 若未來要擴大到 7 天以上，是否需要後端新增專屬 endpoint（owner: PM，決策期限：下一個 sprint）
- [ ] 是否需要 LINE 通知老師補填（已標 Out of Scope，視使用者回饋決定）

---

## 14. Definition of Done（DoD）與 Sign-off

- [ ] 所有 FR（FR-001 ~ FR-009）完成並通過 QA 驗收測試表
- [ ] 跨分校場景驗證通過（US-03）
- [ ] 回歸測試：今日待辦點名、週課表顯示不受影響
- [ ] `npm run deploy` 執行，`index.html` 與 assets 同步
- [ ] `docs/CHANGELOG.md` 新增本次功能記錄
- [ ] PM sign-off
- [ ] CTO / 工程 Lead sign-off
