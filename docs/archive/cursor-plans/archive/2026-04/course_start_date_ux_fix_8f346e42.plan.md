---
name: Course Start Date UX Fix
overview: 修正新建課程時「開課日」缺乏明確欄位、導致遠未來首堂被誤判為已上或操作複雜的功能與 UX 問題，並補強相關防呆機制。
todos:
  - id: backend-course-start-date
    content: "[FEATURE] EnrollmentService::store 接收 course_start_date，session_plan future 資料驗證 session_date >= course_start_date（不符 422）"
    status: completed
  - id: frontend-start-date-field
    content: "[FEATURE] UniversalClassScheduler.vue 新增「開課日」date picker，預設今天，變更後月曆自動跳轉至該月"
    status: completed
  - id: frontend-auto-gen-fix
    content: "[FEATURE] futureSessionOccurrences 掃描起始日改為 max(今天, 開課日)，確保開課日前不產生堂次"
    status: completed
  - id: frontend-visual-diff
    content: "[FEATURE] 月曆格子視覺區分：kind=confirmed（綠底/補登）vs kind=future（藍框/預排）；送出前摘要顯示「預排 N 筆 / 補登 N 筆」"
    status: completed
  - id: frontend-early-date-warn
    content: "[FEATURE] 手動點選日期早於開課日時，顯示橘色警示「此日早於開課日，將視為補登」"
    status: completed
  - id: test-course-start-date
    content: "[TEST] Pest Feature Test：開課日設遠未來，確認第一筆 ClassSession.SessionDate >= 開課日；future session 無多餘早堂；isManualDateConfirmed 回歸"
    status: completed
  - id: qa-acceptance
    content: QA 執行 PRD 第 10 節全部 FR 驗收；回歸測試 AI_REGRESSION_LESSONS.md 固定排課契約與 isManualDateConfirmed 段落
    status: completed
  - id: security-review
    content: 資安確認：course_start_date 無 PII、API 已在現有 auth:sanctum + require_campus 保護下（本次不適用新增存取控制）
    status: completed
  - id: code-review
    content: "[REVIEW] 對 EnrollmentService + UniversalClassScheduler 變更執行 code review，確認 isManualDateConfirmed 邊界、extendSessionsIfNeeded 不受影響"
    status: completed
  - id: docs-update
    content: "[DOCS] 更新 docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md 補充「開課日欄位語意」；更新 docs/CHANGELOG.md"
    status: completed
  - id: deploy
    content: IT/Ops：cd frontend && npm run deploy，確認 index.html + hashed chunk 同輪輸出；驗證測試課程首堂日期正確
    status: completed
  - id: pm-signoff
    content: PM 確認 Definition of Done 全部打勾並 sign-off
    status: completed
isProject: false
---

# PRD：新建課程開課日設定優化（明確開課日 + 防誤標已上）

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 新建課程「開課日」明確欄位 + 防誤標已上防呆機制 |
| 版本 / 日期 | v1.0 / 2026-04-16 |
| 狀態 | Draft |
| 目標角色 | 主任、老師（建課操作者） |

---

## 2. 目標與業務背景

**現在的痛點：**

1. `UniversalClassScheduler`（新建課程唯一入口）沒有獨立的「開課日」欄位。
2. 固定排課（週幾幾點）的自動堂次生成，從 **今天最近的符合星期** 開始計算，不理會實際第一堂何時上課。
3. 若第一堂在遠未來（如 2 個月後），使用者必須：
   - 在月曆上手動翻頁到遠月份
   - 點選第一堂日期（這筆在建課流程中屬於「手動日期」）
   - 系統若判定誤差（邊界情形）或使用者用錯介面（如 CourseManagement 補登月曆），首堂可能被建成 `Status: completed`（已上）
   - 使用者事後要到課程管理去找到那堂，手動改回「未上」
4. 整體操作流程不直覺，且存在資料被汙染的風險。

**解決後的業務價值：**
- 建課時一個欄位填「開課日」，自動從該日起算堂次，不再有雜訊堂次。
- 防呆：對未來日期發出警示，防止使用者誤標「已上」。
- 縮短建課操作時間，減少事後補救。

**成功指標：**
- 建課後 `ClassSession` 第一筆 `SessionDate >= 開課日`，無多餘早堂。
- 人工測試：以「2 個月後第一堂」建課，首堂 `Status = scheduled`，不需人工補改。

---

## 3. 範圍

**In Scope：**
- `UniversalClassScheduler.vue` — 新增「開課日」date input，auto-gen 改從開課日起算
- `EnrollmentService::store` — 後端 session_plan 處理，確保開課日前不建堂
- 防呆：future date 手動點選後，UI 標示「已安排（未上）」而非沉默地可能走進 `confirmed` 路徑
- UX 月曆：月曆快速跳轉至開課日（小箭頭或直接捲動至開課日）
- 建課預覽清單中加上「第一堂日期」顯示

**Out of Scope：**
- `CourseManagement.vue` 補登（backfill）流程的根本重設計（另案）
- 加購堂數（purchaseBatch）開始日流程（已有獨立 `start_date`，不重複）
- 月結制課程相關（此問題主要出現在固定堂次制）

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| PM | 本 PRD 作者 | A |
| CTO / 工程 | 前後端實作 | R |
| QA | 驗收測試 | R |
| 資安 | 存取控制確認 | C |
| IT / Ops | 部署上線 | I |

---

## 5. User Stories

**Story 1 — 開課日欄位**
> As a 主任，I want 在建課精靈中輸入一個「開課日」日期，so that 系統自動從那天起排第一堂，不會在那天之前建立多餘堂次。
>
> Acceptance Criteria：
> - [ ] `UniversalClassScheduler` 新增「開課日」date picker（預設值 = 今天）
> - [ ] 自動產生的固定排課堂次，第一筆 `SessionDate >= 開課日`
> - [ ] 手動點選的日期若早於開課日，UI 顯示警示（或不允許點選）
> - [ ] 建課成功後，`StudentClass.StartDate = 開課日`（若開課日為最早堂次日）

**Story 2 — 遠未來開課日月曆導航**
> As a 主任，I want 月曆能快速跳轉到開課日，so that 我不需要一頁一頁翻月份。
>
> Acceptance Criteria：
> - [ ] 設定開課日後，月曆自動捲至開課日所在月份
> - [ ] 月曆標示「預計開課」旗標於開課日

**Story 3 — 防止未來日期被誤標「已上」**
> As a 主任，I want 當我在建課時手動點選一個遠未來日期時，系統清楚顯示「此日期為預排（未上）」，so that 我不會誤以為它會被建成已上。
>
> Acceptance Criteria：
> - [ ] 手動點選的未來日期，月曆格子標示「預排」（與「手動已上」視覺有明顯區別）
> - [ ] 送出建課前的預覽摘要，明確列出「預排堂次 N 筆」vs「補登已上 N 筆」

---

## 6. 功能需求（FR）

- **FR-001**：`UniversalClassScheduler`（`mode="create"`）新增「開課日」`<input type="date">` 欄位，預設值為今天（`YYYY-MM-DD`）。
- **FR-002**：固定排課自動堂次生成（`futureSessionOccurrences`）的掃描起始日改為 `max(今天, 開課日)`，確保開課日前不產生堂次。
- **FR-003**：手動點選日期（`confirmed_dates`）若 `< 開課日`，UI 顯示橘色警示，提示「此日早於開課日，將視為補登」。
- **FR-004**：月曆在「開課日」設定或變更後，自動跳轉（scroll/navigate）至開課日所在月份。
- **FR-005**：月曆格子視覺區分：`kind='confirmed'`（已上補登）= 綠底；`kind='future'`（預排）= 藍底/框線；舊有「手動」圓點改為更明確的標籤。
- **FR-006**：送出前的預覽摘要（summary panel）新增兩行：「預排堂次」N 筆、「補登已上」N 筆（0 時可省略）。
- **FR-007**：後端 `EnrollmentService::store` 接收 `course_start_date` 欄位，針對 `kind='future'` 的 session_plan 資料，驗證 `session_date >= course_start_date`（不符者 reject 並回傳 422）。

---

## 7. 非功能需求（NFR）

- API `POST /api/v1/class-sessions/batch` 回應時間維持 < 1500ms（P95，批量建堂上限 52 筆）
- `course_start_date` 為 optional 欄位（向下相容舊呼叫端），缺失時行為等同現行邏輯
- 新月曆 scroll 動畫使用 CSS `scroll-behavior: smooth`，不引入額外套件
- 若開課日超過今天 180 天，UI 顯示提示訊息（非阻擋式）

---

## 8. 技術方向（給 CTO）

**受影響的頁面 / API / 資料表：**
- **前端頁面**：`frontend/src/components/UniversalClassScheduler.vue`（核心）、`frontend/src/pages/StudentsList.vue`（呼叫端，不需改動）
- **後端 API**：`POST /api/v1/class-sessions/batch`（`ClassSessionController` → `EnrollmentService::store`）
- **資料表**：`ClassSession.SessionDate / Status`、`StudentClass.StartDate`

**架構選擇取捨：**

| 方案 | 優點 | 缺點 |
|---|---|---|
| A. 前端只調整 `futureSessionOccurrences` 起始日 | 後端零改動 | 開課日未傳後端，無法驗證 |
| B. 前端 + 後端都加 `course_start_date` 驗證 | 防禦最完整，資料可靠 | 多一個可選欄位需維護 |

建議採 **方案 B**。`course_start_date` 為 optional，後端僅用於防禦性 422。

**是否需要 migration：** 否（`StudentClass.StartDate` 已存在，語意不變）

**子任務 Agent 派發：**
- `[FEATURE]` → 後端 `EnrollmentService` + API 接收 `course_start_date` + 前端 `UniversalClassScheduler`
- `[TEST]` → Pest Feature Test：開課日前無堂次、遠未來首堂 `scheduled`
- `[REVIEW]` → 確認 `isManualDateConfirmed` 邊界不受影響
- `[DOCS]` → 更新 `docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md`、`CHANGELOG.md`

---

## 9. 資安與存取控制

- API `POST /api/v1/class-sessions/batch` 已在 `auth:sanctum` + `require_campus` 中介層保護，本次無需額外修改。
- `course_start_date` 欄位屬排程參數，無 PII，無稽核 log 需求。
- STRIDE 快評：本功能無新的 Spoofing / Tampering / Info Disclosure 風險（純 UI 欄位與排程計算）。

---

## 10. QA 驗收標準

| FR | Happy Path | Edge Case | Error Case |
|---|---|---|---|
| FR-001 | 建課填入 `2026-07-01` 開課日，提交後第一筆 `ClassSession.SessionDate = 2026-07-01`（或之後最近符合星期日） | 開課日 = 今天，行為與現行相同 | 開課日空白，前端警示必填（若強制）或後端 optional |
| FR-002 | 開課日前的固定排課星期不產生堂次 | 開課日恰好是固定排課星期，應產生該日堂次 | 開課日早於今天，走現有 confirmed 路徑 |
| FR-003 | 手動點選早於開課日，出現橘色警示 | 剛好等於開課日，無警示 | - |
| FR-004 | 設定開課日後，月曆跳轉至該月 | 開課日在同月，不需跳轉 | - |
| FR-005 | 未來手動日顯示藍底/框線；補登日顯示綠底 | 同日同時段有兩種類型（不應出現） | - |
| FR-006 | 建課前摘要顯示「預排 3 筆 / 補登 0 筆」 | 兩者都有：「預排 2 筆 / 補登 1 筆」 | - |
| FR-007 | 後端收到 `session_date < course_start_date` 的 future 資料 → 422 | `course_start_date` 缺失 → 同現行邏輯 | 格式錯誤的日期字串 → 422 |

**回歸測試對照 `AI_REGRESSION_LESSONS.md`：**
- 確認 `isManualDateConfirmed` 的過去日語意未被破壞（2026-04-12 D 節）
- 確認 `futureSessionOccurrences` 調整後，固定排課契約未損壞（2026-04-12 固定排課契約）
- 確認建課後 `StartDate` 正確 = 最早堂次日（即開課日或之後最近符合日）

---

## 11. 上線與維運

1. 後端：無 migration，直接部署 `EnrollmentService.php` 修改。
2. 前端：`cd frontend && npm run deploy`，確認 `index.html` 與 hashed chunk 同輪輸出。
3. 驗證：建一筆開課日設 2 個月後的測試課程，確認 `ClassSession` 第一筆日期正確。
4. 回滾：前端回退 `UniversalClassScheduler.vue`；後端 `course_start_date` 缺失時維持現有路徑，無破壞性。

---

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 預估工期 | 執行 Agent |
|---|---|---|---|
| P0 | FR-001 + FR-002：開課日欄位 + 自動堂次從開課日起算 | 0.5 天 | `[FEATURE]` |
| P0 | FR-004：月曆跳轉至開課日 | 0.5 天（含 FR-001） | `[FEATURE]` |
| P1 | FR-005 + FR-006：視覺區分 + 摘要預排/補登計數 | 0.5 天 | `[FEATURE]` |
| P1 | FR-007：後端防禦性驗證 `course_start_date` | 0.25 天 | `[FEATURE]` |
| P1 | FR-003：早於開課日手動點選警示 | 0.25 天 | `[FEATURE]` |
| P2 | 超過 180 天提示訊息 | 0.25 天 | `[FEATURE]` |

---

## 13. 風險、假設、開放問題

**風險：**
- 中：調整 `futureSessionOccurrences` 起始日可能影響「加購堂數後補建堂次」路徑 — 需確認 `extendSessionsIfNeeded` 不吃 `course_start_date`（高於開課日起算），降低方案：`course_start_date` 僅用於 `mode="create"`。
- 低：月曆跳轉動畫在舊版 Safari 上可能不滑順 — 降級為立即跳轉。

**假設：**
- 開課日為必填欄位（預設今天，使用者可改）。`[TODO: 需確認]` — 是否允許「不填開課日」走現行邏輯？
- 月曆標示顏色（藍底 vs 綠底）與現有設計一致，不需設計師額外介入。`[TODO: 需確認]`

**開放問題：**
- `[TODO: 需確認]` 開課日是否要同步回填到 `StudentClass.StartDate`，還是 `StartDate` 仍維持「最早堂次日」語意？（建議同步，但需與使用者確認加購堂次不受影響）

---

## 14. Definition of Done

- [ ] FR-001 ～ FR-007 全數通過 QA 驗收
- [ ] `EnrollmentService` 開課日前無 future session 建立，Pest Feature Test 通過
- [ ] `isManualDateConfirmed` 回歸測試通過
- [ ] `npm run deploy` 成功，API health 正常
- [ ] `docs/CHANGELOG.md` 更新
- [ ] `docs/MANUAL_SCHEDULE_DATE_SEMANTICS.md` 補充「開課日欄位語意」一節
- [ ] PM sign-off
- [ ] CTO / 工程 Lead sign-off
