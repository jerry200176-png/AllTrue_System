---
name: student-name-binding-fix
overview: 修復兩個獨立 bug，導致管理員更正學生姓名後，LINE 綁定或家長入口仍找不到學生：(1) 名字存入 DB 前未 trim；(2) 編輯學生時無條件覆蓋 CampusID，學生被移至錯誤校區。
todos:
  - id: trim-name-backend
    content: "StudentController: store() 與 update() 對 name 加 trim()，防止含空白的名字存入 DB"
    status: completed
  - id: campus-protect
    content: "StudentController::update(): 移除 PUT 中無條件覆蓋 CampusID 的邏輯，編輯學生資料不得異動校區"
    status: completed
  - id: studentlist-branch-id
    content: "StudentsList.vue submitStudent: PUT（編輯）payload 移除 branch_id，只有新建（POST）才帶"
    status: completed
  - id: trim-lookup
    content: ParentPortalController 與 LineWebhookController 的 Student 名字查詢改用 TRIM(name) = ?，相容 DB 中已存的舊空白資料
    status: completed
  - id: uxfix-error-message
    content: StudentsList.vue 編輯學生 modal：name 欄位存檔前前端亦 trim，避免誤送帶空白的名字（UI/UX 精緻化）
    status: completed
  - id: test-design
    content: "[TEST] 設計 Pest Feature Test：改名後家長登入 / LINE 綁定正常；名字帶空白存檔後仍可正確比對；編輯後 CampusID 不異動"
    status: cancelled
  - id: qa-verify
    content: QA 驗收：執行 FR-001~FR-004 所有 Happy Path / Edge / Error 場景
    status: cancelled
  - id: security-check
    content: 資安確認：whereRaw TRIM(name) 使用 prepared statement，無 SQL injection 風險
    status: completed
  - id: code-review
    content: "[REVIEW] 對 StudentController / ParentPortalController / LineWebhookController / StudentsList.vue 的變動執行 Code Review"
    status: cancelled
  - id: docs-update
    content: "[DOCS] 更新 docs/CHANGELOG.md，記錄本次修復"
    status: completed
  - id: deploy
    content: 執行 npm run deploy，確認 index.html + assets 同步上線
    status: completed
  - id: ux-signoff
    content: UI/UX sign-off：name 欄位 trim 後顯示、錯誤提示措辭確認（本次前端改動極小，可快速過）
    status: cancelled
  - id: pm-signoff
    content: PM sign-off：DoD 全部打勾
    status: cancelled
isProject: false
---

# PRD — 學生改名後綁定失敗修復

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 學生姓名更正後 LINE 綁定 / 家長入口仍找不到學生 — Bug Fix |
| 版本 / 日期 | v1.0 / 2026-04-16 |
| 狀態 | Draft |
| 目標角色 | 主任（編輯學生資料）、家長（LINE 綁定 / 家長入口登入） |

---

## 2. 目標與業務背景

**痛點**：學生建檔時姓名輸入錯誤，主任在學生列表更正後，家長仍無法透過 LINE 綁定或家長入口（姓名 + 手機）找到學生，導致家長入口功能完全失效。

**業務價值**：
- 家長能即時查閱孩子的課程與評量，是 LINE 整合的核心使用情境
- 姓名輸入錯誤是建檔常見情況，更正後應立即生效，無需額外步驟

**成功指標**：
- 更正名字後，家長使用新名字登入家長入口或 LINE 綁定，成功率 100%
- 更正名字後，學生所在校區（CampusID）不異動

---

## 3. 範圍

**In Scope**：
- 學生名字存檔前加 `trim()`（`StudentController::store` / `update`）
- 編輯學生資料時，**不覆蓋** `CampusID`（`StudentController::update` + `StudentsList.vue` PUT payload）
- 名字查詢時使用 DB-side `TRIM(name)` 相容舊有含空白的資料（`ParentPortalController`、`LineWebhookController`）
- 前端輸入欄位存檔前 trim name

**Out of Scope**：
- 姓名的繁簡轉換或拼音模糊搜尋
- 將學生移轉到其他校區的功能（需另立獨立功能）
- 全形 / 半形字元自動轉換

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| PM | | A |
| CTO / 工程 | | R |
| UI/UX Designer | | R（name 欄位 trim 顯示確認） |
| QA | | R |
| 資安 | | C（whereRaw 確認） |
| IT / Ops | | I |

---

## 5. User Stories

**US-01**（主任更正名字）
> As a 主任, I want 更正學生姓名後立即生效, so that 家長可以用新名字完成 LINE 綁定。
>
> Acceptance Criteria：
> - [ ] 主任在學生列表編輯名字並儲存後，家長用新名字 + 手機登入家長入口可成功
> - [ ] 儲存後學生所屬校區（CampusID）不變

**US-02**（家長 LINE 綁定）
> As a 家長, I want 使用學生正確名字綁定 LINE, so that 能即時收到課程與評量通知。
>
> Acceptance Criteria：
> - [ ] 發送「綁定 \[新名字\] \[手機\]」後，系統能找到學生並完成綁定
> - [ ] 若名字帶有前後空格（使用者複製貼上），仍可成功比對

**US-03**（向下相容舊資料）
> As a system, I want 名字查詢時容錯 DB 中已存在的含空白舊資料, so that 不需要 backfill migration 即可修復現有學生。
>
> Acceptance Criteria：
> - [ ] DB 中 name = `' 林澤清 '`（有空格）的學生，家長輸入「林澤清」也能找到

---

## 5b. UI/UX 精緻化需求

本次修改前端範圍極小（`StudentsList.vue` 的 `submitStudent` payload），但仍需確認以下項目：

| 面向 | 要求 |
|---|---|
| **name 欄位 trim** | 前端 submit 前對 `studentForm.value.name` 做 `.trim()`，避免誤送帶空白的字串；欄位 UI 不需改動 |
| **空狀態 / 錯誤提示** | 若名字 trim 後為空，顯示 inline 錯誤「姓名不得為空」，禁止送出；措辭正向引導 |
| **家長入口錯誤訊息** | 若姓名 + 手機組合找不到學生，現有錯誤訊息「找不到符合的學生」已足夠，**無需修改**（本次 Out of Scope） |
| **響應式** | 不涉及版面變動，無需檢查 |
| **載入 / 動畫** | 不涉及 |

---

## 6. 功能需求

| 編號 | 描述 |
|---|---|
| **FR-001** | `StudentController::store()` 與 `update()` 必須對 `name` 欄位執行 trim 後再存入 DB |
| **FR-002** | `StudentController::update()` 不得因 PUT payload 中含有 `branch_id` 就覆蓋 `CampusID`；校區異動需另行設計獨立欄位 |
| **FR-003** | `StudentsList.vue` 的編輯學生 PUT payload 移除 `branch_id`（POST 新建時保留） |
| **FR-004** | `ParentPortalController::login` 與 `LineWebhookController` 的學生名字查詢改為 `TRIM(name) = TRIM(?)` 以相容已存入含空白的舊資料 |
| **FR-005** | `StudentsList.vue` 前端 `submitStudent` 在送出前對 `studentForm.value.name` 執行 `.trim()`；trim 後為空則顯示 inline 錯誤並阻止送出 |

---

## 7. 非功能需求

- **效能**：`TRIM(name)` 查詢在學生數量 < 10,000 下不需要索引調整，可接受 < 50ms
- **相容性**：修改不可破壞現有 RFID 綁定流程（RFID 綁定不使用 name，不受影響）
- **錯誤處理**：若 PUT 更新失敗，前端現有的 catch 顯示「儲存失敗」即可，不需額外降級

---

## 8. 技術方向

**受影響頁面 / API / 資料表**：
- 頁面：`StudentsList.vue`（編輯學生 modal）
- API：`PUT /api/v1/students/{id}`、`POST /api/v1/parent/login`、LINE Webhook（非 REST，LINE callback）
- 資料表：`Student`（`name`, `CampusID` 欄位）

**架構選擇說明**：
- 不新增 migration：`name` 欄位的 trim 在 application layer 處理即可，DB schema 不動
- 不做 backfill：以 `TRIM(name) = TRIM(?)` 讓舊有帶空白資料在查詢層自動相容
- 移除 PUT 中的 `branch_id`（而非加條件判斷）：最簡單、風險最低，不引入「誰有權移校區」的新邏輯

**子任務 Agent 派發**：
- `[FEATURE]` → 後端 StudentController、ParentPortalController、LineWebhookController + 前端 StudentsList.vue
- `[TEST]` → Pest Feature Test
- `[REVIEW]` → Code Review
- `[DOCS]` → CHANGELOG 更新

---

## 9. 資安與存取控制

- `PUT /api/v1/students/{id}` 限 `director` / `admin` / `super_admin`（現有 middleware 不動）
- `whereRaw('TRIM(name) = ?', [trim($name)])` 使用 PDO prepared statement，**無 SQL injection 風險**
- 移除 CampusID 覆蓋後，學生所屬校區只能在建立時設定，降低跨校資料洩漏的風險
- PII：`Student.name` 屬個人資料，已在現有 Bearer token 認證下保護，不新增風險
- STRIDE 快評：本次變更縮小了攻擊面（移除非預期的 CampusID 異動），無新增 Spoofing / Tampering 風險

---

## 10. QA 驗收標準

### FR-001（name trim 存入）
- Happy Path：名字「 林澤清 」（帶空格）儲存後，DB 中為「林澤清」
- Edge：名字全為空格 → 被前端 FR-005 擋下，不送至後端
- Error：`name` 欄位不存在於 payload → controller 不更新 name（現有行為，不變）

### FR-002 / FR-003（CampusID 不異動）
- Happy Path：主任在「延平」分校編輯學生名字後儲存，`Student.CampusID` 仍為延平 ID
- Edge：前端 branchId prop 切換為其他校區時送出，後端不覆蓋 CampusID
- Regression：確認 POST 新建學生時 CampusID 仍由 `branch_id` 正確設定

### FR-004（TRIM 查詢）
- Happy Path：DB 中 name = `'林澤清'`（無空格），家長輸入「林澤清」→ 找到學生
- Edge：DB 中 name = `' 林澤清 '`（含空格），家長輸入「林澤清」→ 仍可找到
- Error：名字完全不符 → 回傳「找不到」，與現有行為一致

### FR-005（前端 trim）
- Happy Path：名字輸入後帶空格儲存 → 前端 trim 後送出「林澤清」
- Edge：名字輸入全空格 → inline 錯誤「姓名不得為空」，按鈕 disabled

**UI/UX 驗收清單**：
- [ ] name 欄位 trim 後顯示正確（無多餘空格）
- [ ] name 為空時 inline 錯誤訊息出現，送出按鈕 disabled
- [ ] 成功儲存後 toast 顯示（現有行為，確認不受影響）

**回歸檢查（對照 `docs/AI_REGRESSION_LESSONS.md`）**：
- 確認 RFID 綁定流程不受影響（不使用 name）
- 確認 POST 新建學生的 CampusID 設定正常

---

## 11. 上線與維運

1. 後端修改不需 migration，直接部署 Laravel
2. 前端執行 `cd frontend && npm run deploy`（確認 `index.html` + `assets` 同步）
3. 部署後手動測試：家長入口輸入正確名字 + 手機，確認可登入
4. 回滾方案：git revert 本次 commit，無 DB schema 變動，回滾無風險

---

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 預估工期 | 執行 |
|---|---|---|---|
| P0 | FR-001 name trim on save | 30 分鐘 | `[FEATURE]` |
| P0 | FR-002 / FR-003 移除 CampusID 覆蓋 | 30 分鐘 | `[FEATURE]` |
| P0 | FR-004 TRIM 查詢相容舊資料 | 30 分鐘 | `[FEATURE]` |
| P1 | FR-005 前端 trim + inline error | 20 分鐘 | `[FEATURE]` |
| P1 | Pest 測試補充 | 45 分鐘 | `[TEST]` |
| P2 | CHANGELOG 更新 | 10 分鐘 | `[DOCS]` |

---

## 13. 風險、假設、開放問題

**風險**：
- 低：移除 PUT 中的 `branch_id` 後，若有其他地方依賴此欄位來更新 CampusID，需確認 → 已確認 `StudentController::update` 是唯一路徑，風險低

**假設**：
- 假設「綁定」指 LINE 綁定（`LineWebhookController`）與家長入口登入（`ParentPortalController`）兩個流程
- 假設 CampusID 的異動並非刻意設計功能，只是未防護的副作用

**開放問題**：
- `[TODO: 需確認]` 是否需要同步 backfill 現有 DB 中已有含空白的 `Student.name` 資料？目前以 `TRIM` 查詢相容，若需清乾淨再另立 migration。

---

## 14. Definition of Done

- [ ] FR-001 ~ FR-005 全數通過 QA 驗收
- [ ] **UI/UX 驗收清單（第 10 節）全部打勾，UI/UX Designer sign-off**
- [ ] 資安確認：`whereRaw TRIM` 無 SQL injection
- [ ] `npm run deploy` 完成，API health 正常
- [ ] `docs/CHANGELOG.md` 更新
- [ ] PM sign-off
- [ ] CTO / 工程 Lead sign-off
