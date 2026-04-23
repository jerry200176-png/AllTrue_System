---
name: student-line-bindings-admin-ui
overview: 在主任後台的學生管理頁，學生編輯 modal 新增「LINE 綁定家長」區塊，列出所有已綁定的 LINE 帳號（masked ID + 綁定時間），並提供逐筆解除綁定功能。
todos:
  - id: backend-api
    content: "[FEATURE] StudentController 新增 lineBindings() 與 removeLineBinding()，以及對應路由（GET/DELETE students/{id}/line-bindings）"
    status: completed
  - id: frontend-ui
    content: "[FEATURE] StudentsList.vue 編輯 modal 新增「LINE 綁定家長」section（清單、loading、空狀態、解除按鈕）"
    status: completed
  - id: ux-polish
    content: "[FEATURE/UX] confirm dialog、成功 toast、masked ID 格式、錯誤狀態補齊"
    status: completed
  - id: changelog-bindings-ui
    content: "[DOCS] 更新 docs/CHANGELOG.md 記錄主任後台 LINE 綁定管理功能"
    status: completed
isProject: false
---

# PRD — 主任後台：查看並管理學生 LINE 綁定家長

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 學生 LINE 綁定管理（主任後台） |
| 版本 / 日期 | v1.0 / 2026-04-16 |
| 狀態 | Draft |
| 目標角色 | 主任（`director` / `admin` / `super_admin`） |

---

## 2. 目標與業務背景

**痛點**：多家長 LINE 綁定完成後，主任無法在後台確認「哪些 LINE 帳號綁定了某學生」，也無法幫家長解除錯誤綁定（例如：家長換手機、換 LINE 帳號、綁錯學生）。

**業務價值**：
- 主任可一眼確認綁定狀態，減少人工溝通
- 解除綁定無需動資料庫，降低運維風險

**成功指標**：
- 主任打開任一學生的編輯 modal，可看到已綁定的 LINE 帳號清單與綁定時間
- 可對每筆綁定個別執行解除，操作後列表即時更新

---

## 3. 範圍

**In Scope**：
- `GET /api/v1/students/{id}/line-bindings`（director 限定）：回傳該學生的綁定清單
- `DELETE /api/v1/students/{id}/line-bindings/{bindingId}`（director 限定）：解除單筆綁定，同時清除 `Student.LineID`（若被解除的 `line_user_id` 與 `Student.LineID` 相同）
- `StudentsList.vue` 編輯 modal 新增「LINE 綁定家長」區塊

**Out of Scope**：
- 主任手動新增綁定（由家長自行透過 LINE webhook 綁定）
- 批次解除所有綁定
- 顯示家長真實姓名（資料表沒有存）

---

## 4. RACI

| 角色 | R/A/C/I |
|---|---|
| PM | A |
| CTO / 工程 | R |
| UI/UX Designer | R |
| QA | R |
| 資安 | C |
| IT / Ops | I |

---

## 5. User Stories

**US-01**（查看綁定清單）
> As a 主任, I want 在學生 modal 看到哪些 LINE 帳號已綁定此學生, so that 我確認家長綁定狀態正確。
>
> Acceptance Criteria：
> - [ ] 編輯 modal 顯示「LINE 綁定家長」區塊，列出每筆綁定的 masked line_user_id 與綁定時間
> - [ ] 無綁定時顯示空狀態說明文字

**US-02**（解除綁定）
> As a 主任, I want 點擊「解除」按鈕移除某筆 LINE 綁定, so that 家長換帳號後可重新綁定。
>
> Acceptance Criteria：
> - [ ] 點擊「解除」→ 確認 dialog → 呼叫 DELETE API → 列表即時移除該筆
> - [ ] 若解除的 line_user_id 與 Student.LineID 相同，後端同步清空 Student.LineID

---

## 5b. UI/UX 精緻化需求

**StudentsList.vue 編輯 modal — 「LINE 綁定家長」區塊**（插入現有 RFID 區塊之後）：

| 面向 | 規格 |
|---|---|
| 版面層次 | 與現有 form-section-title 樣式一致（同一 modal，不加 tab）；綁定清單用 compact list，每行含 masked ID + 綁定時間 + 解除按鈕 |
| 空狀態設計 | 顯示「尚未有家長透過 LINE 綁定此學生」淺灰文字，不顯示空白 |
| Loading 狀態 | 開啟 modal 時 fetch 綁定清單，loading 期間顯示 skeleton 或「載入中…」文字 |
| 解除綁定 | 危險操作：使用 `confirm()` dialog（「確定要解除此 LINE 綁定？解除後家長需重新綁定。」）；解除成功後顯示短暫 toast「已解除綁定」 |
| masked ID | `line_user_id` 前 8 碼 + `…` + 後 4 碼（例：`Uc36a216…169e`），避免顯示完整 ID |
| 響應式 | modal 已固定 520px，列表不需特別處理行動裝置 |

---

## 6. 功能需求（FR）

| 編號 | 描述 |
|---|---|
| **FR-001** | `GET /api/v1/students/{id}/line-bindings`（director）：回傳 `[{ id, line_user_id_masked, bound_at }]` |
| **FR-002** | `DELETE /api/v1/students/{id}/line-bindings/{bindingId}`（director）：刪除 `student_line_bindings` 該行；若 `Student.LineID === binding.line_user_id`，一併清空 `Student.LineID = null` |
| **FR-003** | `StudentController` 新增 `lineBindings()` 與 `removeLineBinding()` 方法 |
| **FR-004** | `StudentsList.vue` 編輯 modal 加入「LINE 綁定家長」section，`editingStudentId` 非空時才載入 |
| **FR-005** | 解除操作需 `confirm()` 確認，成功後列表移除該筆，並顯示成功 toast |

---

## 7. 非功能需求

- `line_user_id` 在 API response 中做 masked 處理，不回傳完整值（資安）
- 所有路由套用 `role:director` + `require_campus` middleware，確保只能操作自己校區的學生
- 刪除操作有 Log（`Log::info`）記錄操作人、學生 ID、binding ID

---

## 8. 技術方向

**受影響檔案**：
- [`backend/app/Http/Controllers/StudentController.php`](backend/app/Http/Controllers/StudentController.php) — 新增 `lineBindings()` 與 `removeLineBinding()`
- [`backend/routes/api.php`](backend/routes/api.php) — 新增兩條 student-bindings 路由（director middleware group 內）
- [`frontend/src/pages/StudentsList.vue`](frontend/src/pages/StudentsList.vue) — 在 RFID 區塊後新增 LINE 綁定 section + fetch / delete 邏輯

**API response shape**（FR-001）：
```json
{
  "bindings": [
    { "id": 1, "line_user_id_masked": "Uc36a216…169e", "bound_at": "2026-04-16 12:00:00" }
  ]
}
```

**masked 規則**：`substr($uid, 0, 8) . '…' . substr($uid, -4)`

**架構選擇**：
- `lineBindings()` 掛在 `StudentController` 而非獨立 controller，因為是同一 students resource 的子行為
- 無需 migration（複用現有 `student_line_bindings` 表）

---

## 9. 資安與存取控制

- `line_user_id` 完整值**不得**出現在 response 中（FR-001 只回傳 masked）
- DELETE 操作需驗證 `binding.student_id` 屬於 request 路徑的 `{student}` 且同一 campus，防止跨學生越權
- 操作稽核：`Log::info("Director {userId} removed LINE binding {bindingId} for student {studentId}")`

---

## 10. QA 驗收標準

### FR-001（查看清單）
- Happy Path：已有 2 筆綁定 → modal 顯示 2 行，masked ID + 時間正確
- Edge：無綁定 → 空狀態文字，非空白
- Edge：Loading 時有 loading 提示

### FR-002 / FR-005（解除綁定）
- Happy Path：點擊解除 → confirm dialog → 確認 → API 200 → toast + 列表移除
- Edge：取消 confirm → 不刪除
- Edge：若解除的 line_user_id 與 Student.LineID 相同 → Student.LineID 清空（後端 tinker 驗證）
- Security：嘗試刪除他校學生的 binding → 403

### UI/UX 驗收清單
- [ ] 空狀態有說明文字，非空白
- [ ] Loading 期間有文字提示
- [ ] 危險操作有 confirm dialog，措辭正向引導
- [ ] 解除成功有 toast 回饋
- [ ] masked ID 格式正確

---

## 11. 上線與維運

1. 不需 migration
2. 部署後端（新增 2 個路由 + controller 方法）
3. 執行 `cd frontend && npm run deploy`
4. 驗證：主任開啟有 LINE 綁定的學生 modal，確認區塊出現

---

## 12. 里程碑與優先級

| 優先級 | 項目 | Agent |
|---|---|---|
| P0 | 後端 `lineBindings()` + `removeLineBinding()` + 路由 | `[FEATURE]` |
| P0 | 前端 StudentsList.vue 新增 LINE 綁定 section | `[FEATURE]` |
| P1 | UI/UX 精緻化（empty state、loading、confirm、toast） | `[FEATURE]` |
| P1 | QA 驗收 | QA |
| P2 | CHANGELOG 更新 | `[DOCS]` |

---

## 13. 風險 / 假設 / 開放問題

**風險**：
- 低：masked ID 規則若未來 LINE 改 ID 格式可能截斷不同，但目前 LINE userId 均為 33 字元，前 8 + 後 4 安全

**假設**：
- 主任只需要「解除」，不需要「新增」綁定（由家長自行透過 LINE webhook 操作）

---

## 14. Definition of Done

- [ ] FR-001～FR-005 全數通過 QA 驗收
- [ ] UI/UX 驗收清單全部打勾
- [ ] 資安：masked ID 確認、跨校越權返回 403
- [ ] `npm run deploy` 完成，主任可在後台看到並操作 LINE 綁定清單
- [ ] `docs/CHANGELOG.md` 更新
- [ ] PM / 工程 Lead sign-off
