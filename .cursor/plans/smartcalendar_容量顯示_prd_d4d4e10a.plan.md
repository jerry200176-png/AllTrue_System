---
name: SmartCalendar 容量顯示 PRD
overview: 為智慧排課行事曆新增「老師時段容量視覺化」功能，讓主任一眼看出每位老師在每個時段的學生人數（已佔 / 上限 3），判斷是否還能再塞課。
todos:
  - id: backend-api
    content: 後端 API：本次不適用，原因：容量計算為純前端 computed，不新增 API 端點或資料表
    status: completed
  - id: frontend-feature
    content: 前端 UI（功能）：SmartCalendar.vue 新增 getSlotOccupancy() helper（複用 CAPACITY_MAP），日檢視 .slot template 加入 capacity-badge span
    status: completed
  - id: frontend-ux
    content: UI/UX 精緻化：三色角標（綠/橘/紅）、hover tooltip、緊湊模式（≥10 欄）降級為彩色圓點、白色邊框確保與老師底色對比度
    status: completed
  - id: test-design
    content: 測試設計：手動 QA checklist — 一對一/二/三/輔導/試聽各班型徽章數字與顏色、空格無徽章、緊湊模式圓點、checkConflict 不受影響
    status: completed
  - id: qa-acceptance
    content: QA 驗收：執行第 10 節所有 FR 驗收（Happy Path / Edge / Error）+ UI/UX 驗收清單
    status: completed
  - id: security
    content: 資安確認：本次無新增 API 或資料存取，STRIDE 無高風險項，確認即可
    status: completed
  - id: code-review
    content: Code Review：確認 CAPACITY_MAP 未被修改、getCoursesForTeacherAt 過濾邏輯未動、isSlotRoomFull 教室容量邏輯未受干擾
    status: completed
  - id: docs-update
    content: 文件更新：更新 docs/CHANGELOG.md，記錄「SmartCalendar 老師時段容量徽章」功能上線
    status: completed
  - id: deploy
    content: 部署：cd frontend && npm run deploy，確認 backend/public/index.html + assets 同步
    status: completed
  - id: ux-signoff
    content: UI/UX sign-off：確認三色徽章在所有老師底色上可辨識、緊湊模式降級效果符合第 5b 節規格
    status: completed
  - id: pm-signoff
    content: PM sign-off：確認 Definition of Done 全部打勾，開放問題（容量上限是否可設定、跨時段課程計算）已由主任明示回覆
    status: completed
isProject: false
---

# 智慧排課行事曆 — 老師時段容量視覺化 PRD

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | SmartCalendar 老師時段容量指示器 |
| 版本 / 日期 | v1.0 / 2026-04-16 |
| 狀態 | Draft |
| 目標角色 | 主任（排課決策者） |

---

## 2. 目標與業務背景

**現在的痛點**

行事曆日檢視中，每位老師的時段格子顯示課程卡片（學生姓名 + 科目 + 班型），但一對一、一對二、一對三的格子視覺上「看起來一樣寬」。主任無法快速判斷「這位老師這個時段還能不能再塞一位學生」，只能靠記憶或逐一點開課程確認。

**解決後的業務價值**

- 主任排課效率大幅提升，減少排課失誤（超塞）。
- 可快速找到有空位的老師時段，推薦給新生或試聽。

**成功指標（KPI）**

- 主任從行事曆找到「可塞課時段」的操作步驟從 4+ 步降至 1 步（一眼看出）。
- 排課衝突（超過 3 人）的誤操作率降至 0。

---

## 3. 範圍

**In Scope**

- 智慧排課（SmartCalendar）**日檢視**（老師欄模式）中，每個老師 × 整點時段格子顯示容量指示器。
- 容量計算規則：老師該時段學生人數上限為 **3**，各班型消耗如下：
  - `one_on_one` → 1 人
  - `one_on_two` → 2 人
  - `one_on_three` → 3 人
  - `tutoring` → 1 人
  - `trial`（試聽）→ 1 人
- 視覺：在每位老師的時段格子頂端顯示 **容量角標**（如 `1/3`、`2/3`、`3/3`），配合顏色區分：
  - 有空位（occupied < 3）→ 綠色邊框 or 綠色角標
  - 剩最後一個位子（occupied = 2）→ 橘色（警示）
  - 已滿（occupied = 3）→ 紅色

**Out of Scope**

- 週檢視（`week` mode）的容量顯示（本次僅日檢視，後續可迭代）。
- 自動推薦「哪個時段還有空位」（後續 P2）。
- 後端 API 異動（計算純前端）。
- 教室容量（`isSlotRoomFull`）邏輯不動。

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| PM | 本 PRD 作者 | A |
| CTO / 工程 | 前端工程師 | R |
| UI/UX Designer | 視覺精緻化 | R |
| QA | 驗收測試 | R |
| 資安 | 存取控制確認 | C |
| IT / Ops | 部署確認 | I |

> UI/UX Designer 職責：角標顏色語意是否與系統其他警示色一致、空狀態（該時段無課、0/3）是否顯示、格子在緊湊模式（≥10 欄）的顯示降級規格。

---

## 5. User Stories

> **As a** 主任, **I want** 在行事曆每位老師的時段格子上看到「目前已有幾位學生 / 上限 3」的指示器, **so that** 我可以立刻判斷是否還能幫這位老師排入新學生，不需要點開每張課程卡。
>
> Acceptance Criteria：
> - [ ] 日檢視中，每個有課的老師時段格子頂端顯示「X/3」徽章，X = 該時段實際學生人數合計（依班型加總）。
> - [ ] 一對一（X=1）→ 綠色；一對二（X=2）→ 橘色；一對三 / 已滿（X=3）→ 紅色。
> - [ ] `tutoring` 和 `trial` 同樣各計為 1 人。
> - [ ] 若時段無課（X=0），不顯示徽章（格子維持原本空白）。
> - [ ] 顏色不影響既有課程卡片的老師色塊（兩者獨立元素）。

> **As a** 主任, **I want** 已滿（3/3）的格子有明顯紅色標示, **so that** 我不會不小心想再往裡面塞課。
>
> Acceptance Criteria：
> - [ ] 已滿格子徽章顯示「3/3」，紅色背景白字。
> - [ ] 試圖拖曳新課到已滿格子時，系統現有的衝突檢查（`checkConflict`）仍會阻擋（已有機制，本次確認不被覆蓋）。

---

## 5b. UI/UX 精緻化需求

### SmartCalendar 日檢視 — 老師時段格子（`.slot`）

| 面向 | 要求描述 |
|---|---|
| **版面層次** | 容量徽章位於格子右上角，`position: absolute`，`z-index` 高於課程卡片但不遮擋學生姓名；字型 11–12px，bold；最小寬度 28px，確保「3/3」不截字 |
| **色彩一致性** | 綠色 = `#10b981`（系統現有成功色）；橘色 = `#f59e0b`（現有警示色）；紅色 = `#ef4444`（現有危險色）；白字；徽章圓角 4px |
| **互動回饋** | Hover 格子時徽章保持可見（不因 hover 動畫消失）；Tooltip 顯示「已佔 X 人 / 上限 3 人」，說明計算來源（列出各班型） |
| **空狀態設計** | 無課時段（X=0）不顯示徽章，格子維持原空白樣式；不顯示「0/3」避免資訊噪音 |
| **載入狀態** | 行事曆 `loadCourses()` 完成前，徽章區域不渲染（與課程卡片同步出現），無獨立 loading 需求 |
| **防呆設計** | 緊湊模式（欄數 ≥ 10）徽章縮小至 10px 字體，或只顯示圓點（實心圓，顏色同上三色），hover 才顯示數字 tooltip；避免格子被徽章遮滿 |
| **響應式** | 本功能主要為主任桌機操作，手機斷點（< 768px）可隱藏徽章文字、僅保留圓點色點 |

---

## 6. 功能需求（FR）

- **FR-001**：系統應在日檢視每位老師的每個有課整點時段，計算「該老師、該日、該開始整點」所有可見課程的學生人數合計（`occupiedCount`），計算規則：`one_on_one=1, one_on_two=2, one_on_three=3, tutoring=1, trial=1`。
- **FR-002**：`occupiedCount` 在 1–2 時，系統應在格子右上角顯示綠色徽章「X/3」（X=1）或橘色徽章（X=2）。
- **FR-003**：`occupiedCount` ≥ 3 時，系統應在格子右上角顯示紅色徽章「3/3」（即使因資料異常超過 3，仍最多顯示 3/3）。
- **FR-004**：`occupiedCount` = 0（無課時段）時，系統不應渲染徽章元素。
- **FR-005**：徽章顏色與老師課程卡片底色（`getTeacherColor`）視覺上必須可區分，不得使用相同色系。
- **FR-006**：緊湊模式（欄數 ≥ 10）下，系統應降級顯示為 10px 小圓點（僅色彩）加 hover tooltip。
- **FR-007**：現有排課衝突檢查（`checkConflict` / `CAPACITY_MAP`）不得因本次修改被移除或弱化。

---

## 7. 非功能需求（NFR）

- **效能**：`occupiedCount` 計算為純前端 computed，基於已載入的 `filteredCourses`，不得新增 API 呼叫；計算耗時 < 2ms/格。
- **相容性**：不影響週檢視（`week` mode）的既有行為。
- **可維護性**：容量計算邏輯應抽成獨立 helper function（`getSlotOccupancy(teacherId, dow, hour)`），方便後續週檢視或其他頁面複用。

---

## 8. 技術方向（給 CTO）

**受影響的頁面**
- `frontend/src/pages/SmartCalendar.vue`（唯一修改點）

**受影響的 API / 資料表**
- 無需新增 API 端點或資料表異動。
- 計算來源：`filteredCourses`（已在前端記憶體），讀 `class_type`、`teacher_id`、`day_of_week`、`start_time`。

**架構選擇**
- 新增 computed helper `getSlotOccupancy(teacherId, dow, hour)` → 回傳 `{ count, color, label }`。
- 現有的 `CAPACITY_MAP`（`SmartCalendar.vue` line ~2172）已定義各班型人數，直接複用。
- 在日檢視 `.slot` template 中，新增 `<span class="capacity-badge">` 絕對定位於格子右上角。
- 不需要 migration。

**子任務 Agent 派發**
- `[FEATURE]` → 前端 Vue 實作（`SmartCalendar.vue` 新增 helper + template 角標）
- `[TEST]` → 驗收腳本（手動 QA checklist，純前端無 API 新增）
- `[DOCS]` → 更新 `docs/CHANGELOG.md`

---

## 9. 資安與存取控制

- 此功能為**讀取** `filteredCourses` 的純顯示優化，無寫入操作，無新增資料暴露。
- 行事曆頁面已受 `auth` middleware 保護（`require_campus`）；本次不新增路由。
- **STRIDE 快評**：
  - Spoofing / Tampering：無（純 computed 顯示）
  - Information Disclosure：無（僅顯示該校區已可見的課程資料）
  - DoS：無（純前端計算，不新增 API 呼叫）
- **PII**：徽章只顯示數字（人數），不暴露額外學生姓名資料。

---

## 10. QA 驗收標準

| FR | Happy Path | Edge Case | Error Case |
|---|---|---|---|
| FR-001 | 一對二課程的老師格子顯示「2/3」 | 同一時段兩筆課（一對一+輔導）= 2 人，顯示「2/3」 | `class_type` 為 null 時計為 0 人，不崩潰 |
| FR-002 | 1/3 綠色、2/3 橘色 | trial 試聽計入 1 人，顏色邏輯正確 | — |
| FR-003 | 3/3 顯示紅色 | 一對三（3 人）單筆 = 3/3 紅色 | 異常資料 4 人仍顯示「3/3」不顯示「4/3」 |
| FR-004 | 無課格子無徽章 | 所有課程皆被 student search filter 隱藏時，格子不顯示徽章 | — |
| FR-006 | 欄數 ≥ 10 降級為圓點 | hover 顯示 tooltip 人數說明 | — |
| FR-007 | 拖曳至已滿格子仍觸發衝突警告 | — | — |

**回歸測試**（對照 `docs/AI_REGRESSION_LESSONS.md`）
- 確認 `CAPACITY_MAP` 未被修改（影響 `checkConflict`）。
- 確認 `getCoursesForTeacherAt` 過濾邏輯未被異動（同格多筆橫排仍正常）。
- 確認 `isSlotRoomFull`（教室滿）視覺效果未受干擾。

**UI/UX 驗收清單**
- [ ] 無課格子（X=0）無徽章，非「0/3」文字
- [ ] 三色（綠橘紅）在各種老師底色上均可辨識
- [ ] 緊湊模式（≥10 欄）徽章降級為圓點，hover tooltip 顯示
- [ ] 格子 hover 動畫不導致徽章消失
- [ ] 手機斷點下徽章不造成水平 overflow

---

## 11. 上線與維運

- **部署步驟**：修改 `frontend/src/pages/SmartCalendar.vue` → `cd frontend && npm run deploy`（build + copy 到 `backend/public`）
- **監控**：無新增監控需求（純前端 UI 調整）
- **回滾方案**：git revert 前端 commit + 重新 deploy

---

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 預估工期 | 執行 |
|---|---|---|---|
| P0（Must Have） | 日檢視容量徽章（三色 X/3）+ helper function | 0.5 天 | `[FEATURE]` |
| P1（Should Have） | 緊湊模式圓點降級 + hover tooltip | 0.5 天 | `[FEATURE]` + UI/UX |
| P2（Nice to Have） | 週檢視同步顯示容量 | 待評估 | 後續迭代 |

---

## 13. 風險、假設、開放問題

**風險**
- 低風險：純前端 computed，不影響後端或資料庫。
- 中風險：若老師底色（`getTeacherColor`）恰好與綠/橘/紅相近，徽章辨識度下降 → 緩解：徽章加白色邊框（`border: 1.5px solid white`）或黑色投影。

**假設**
- 老師同一時段學生人數上限為 **3**（hardcode），不由後端資料表控制。`[TODO: 需確認]` 未來是否要做成可設定？
- `tutoring` 和 `trial` 均計為 1 人，與現有 `CAPACITY_MAP` 一致。
- 日檢視整點對齊的計算方式（`parseHour(start_time) === hour`）作為容量計算的分組基準，半小時班型（30 分鐘）不另行處理。`[TODO: 需確認]` 是否有 30 分鐘或跨整點的課？

**開放問題**
- Q1：容量上限是否一律 3，或「一對一老師」上限為 1 而非 3？`[TODO: 需主任確認]` —— 目前 PRD 採「所有老師上限 3，按班型消耗計算剩餘空位」。
- Q2：跨時段課程（duration_hours > 1）的計算：徽章是否應在每個跨越的整點格子都顯示？`[TODO: 需確認]` 目前 `getCoursesForTeacherAt` 僅比對開始整點。

---

## 14. Definition of Done

- [ ] 所有 FR（FR-001 到 FR-007）通過 QA 驗收
- [ ] UI/UX 驗收清單（第 10 節）全部打勾，UI/UX Designer sign-off
- [ ] 資安審查確認（本次無高風險項）
- [ ] `npm run deploy` 完成，`backend/public/index.html` 與 assets 同步
- [ ] `docs/CHANGELOG.md` 更新
- [ ] PM sign-off
- [ ] CTO / 工程 Lead sign-off
