# ARCH：續報防呆狀態機與 API 合約

## 1. 現況判讀

現有流程已具備核心能力，但入口與語意分散：

- `StudentClassController::purchaseBatch`：堂數制加購會直接建立新 `StudentClass` 與 `ClassSession`，並在舊課已繳且剩 0 堂時自動結案。
- `StudentClassController::renewMonthly`：月結制續約會直接延長 `EndDate`，重置繳費狀態，並建立下一期 `Invoice`。
- `StudentsList.vue` 與 `CourseManagement.vue` 都有續報入口，各自用 alert / confirm / modal 送出。
- 目前沒有「先預覽、再確認」的共用 API，主任無法在提交前看到完整後果。

## 2. Architecture Boundary

本階段只設計，不寫 production code。

### 不改動

- 不改 `AlertController::tuition` 列入條件。
- 不改既有 `StudentClass` / `Invoice` 的核心資料語意。
- 不刪除 `CoursePackage` legacy 能力。

### 新增邊界

建立「續報指揮層」作為既有 submit API 前的安全層：

1. Preview：只讀資料，回傳變更摘要與風險。
2. Confirm：帶 preview token / fingerprint，再次驗證後執行既有操作。
3. Receipt：回傳可讀結果，前端顯示並可追蹤。

## 3. 狀態機

### RenewalIntent 狀態

| 狀態 | 說明 | 下一步 |
|---|---|---|
| `draft` | 前端輸入續報參數，尚未送 preview | `previewed` |
| `previewed` | 後端已回傳試算與風險 | `confirmed` / `abandoned` |
| `blocked` | 發現不可執行問題，例如無權限、課程已結案、模式錯誤 | 結束 |
| `warning` | 可執行但需使用者確認，例如已有待繳新批次 | `confirmed` / `abandoned` |
| `confirmed` | 使用者確認並送出 | `applied` / `failed` |
| `applied` | 已產生新課/帳單/結案等結果 | 結束 |
| `failed` | 送出時資料已變更或寫入失敗 | 回到 `previewed` 或重試 |

## 4. API 合約草案

### A. 續報預覽

`POST /api/v1/student-classes/{studentClass}/renewal-preview`

Request：

| 欄位 | 型別 | 說明 |
|---|---|---|
| `mode` | string | `purchase_batch` / `renew_monthly` |
| `sessions` | int nullable | 堂數制新批次堂數 |
| `start_date` | date nullable | 堂數制新批次開始日 |
| `end_date` | date nullable | 月結制新到期日 |
| `months` | int nullable | 月結制延長月數 |

Response：

| 欄位 | 說明 |
|---|---|
| `preview_id` | 由 course id + input + current state hash 產生 |
| `state_hash` | submit 時用於偵測資料是否已變更 |
| `severity` | `ok` / `warning` / `blocked` |
| `source_course` | 舊課摘要：堂數、剩餘、繳費、結案狀態 |
| `proposed_course` | 可能新增/更新的課程摘要 |
| `billing` | 應收、是否產生 Invoice、期別、是否 0 元 |
| `schedule` | 預計建立堂次數、首堂、末堂、衝突摘要 |
| `warnings` | 使用者可理解的警告 |
| `blockers` | 不可執行原因 |

### B. 續報確認

`POST /api/v1/student-classes/{studentClass}/renewal-confirm`

Request：

| 欄位 | 型別 | 說明 |
|---|---|---|
| `preview_id` | string | 來自 preview |
| `state_hash` | string | 來自 preview |
| `mode` | string | 同 preview |
| `payload` | object | 原始輸入 |

Response：

| 欄位 | 說明 |
|---|---|
| `receipt_id` | 可追蹤結果 ID；第一版可為非持久化字串 |
| `message` | 非技術語言結果 |
| `source_course` | 舊課實際變更結果 |
| `new_course` | 新批次實際結果，若有 |
| `invoice` | 帳單結果，若有 |
| `schedule` | 實際建立/取消堂次 |
| `next_actions` | 前端 CTA，例如「查看新批次」「前往核帳」 |

## 5. 後端設計

### Service 分層

| Service | 職責 |
|---|---|
| `RenewalPreviewService` | 只讀試算，不寫 DB |
| `RenewalGuardService` | 檢查模式、權限、重複續報、狀態變更 |
| `RenewalApplyService` | 在 transaction 中呼叫既有 purchase/monthly 邏輯 |
| `RenewalReceiptBuilder` | 把技術結果轉成主任可讀摘要 |

### 重複續報判斷

堂數制 P1 規則：

- 同 `StudentID`
- 同 `SubjectID`
- `Stop=0`
- `Paid=0` 或 `RemainingSessions > 0`
- `ID != source.ID`
- 新批次 `StartDate` 在 source 最後一堂後 60 天內

結果：

- 若命中：`severity=warning`，前端需顯示「可能已續報」與對應課程。
- 第一版不硬擋，除非完全同時段/同開始日/同堂數，則 `blocked`。

月結制 P1 規則：

- 同 `StudentClassID`
- 同 `billing_period`
- 若 Invoice 已存在，不重複建立，preview 顯示既有 Invoice。

## 6. DB 異動清單

第一批 PR 建議 **不新增 migration**，先用 deterministic `preview_id/state_hash` 與 response receipt。

第二批若需要回看歷史，再新增：

| 表 | 欄位 | 說明 |
|---|---|---|
| `renewal_action_logs` | `id` | 主鍵 |
|  | `campus_id` | 分校隔離 |
|  | `student_class_id` | 來源課程 |
|  | `actor_user_id` | 操作者 |
|  | `mode` | 操作類型 |
|  | `before_snapshot` / `after_snapshot` | JSON |
|  | `created_at` | 操作時間 |

DBA gate：若新增表需 migration review。

## 7. 多校區隔離

- Preview / Confirm 都先走 `authorizeStudentClassAccess($studentClass)`。
- 重複續報查詢必須限制在同 `Student.CampusID` 或主任 `auth_campus_ids` 可見範圍。
- 回傳 warning 的候選課程不得跨分校洩漏學生資料。

## 8. 前端整合邊界

第一批只接：

- `CourseManagement.vue` 的「續報/加購」入口。
- `StudentsList.vue` 的「續報加購/月結續約」入口。

共用元件：

- `RenewalPreviewModal.vue`
- `ActionReceiptModal.vue`

不在第一批做全站視覺重皮。

## 9. 測試設計

PHPUnit：

- 堂數制 preview 不寫入 DB。
- 堂數制 confirm 建新批次且可自動結案舊課。
- 同學生同科目已有待繳新批次時 preview warning。
- 完全重複新批次時 preview blocked。
- 月結 preview 顯示下一期 Invoice。
- 月結 confirm 對既有 billing period idempotent。
- 跨分校 preview/confirm 403。

前端：

- `npm --prefix frontend run build`
- 手動 smoke：CourseManagement 續報 preview → confirm → receipt。

## 10. 分批 PR 建議

| PR | 內容 | 風險 |
|---|---|---|
| PR-1 | 後端 preview/confirm + PHPUnit，不改 UI 入口 | 中 |
| PR-2 | CourseManagement 接 preview modal + receipt | 中 |
| PR-3 | StudentsList 接同一套 preview modal | 中 |
| PR-4 | Premium HUD / design tokens 第一批 | 低 |
| PR-5 | UX audit P1 快修 | 視項目 |

## 11. Exit Checklist

- [x] DB 異動清單已列。
- [x] API 合約已列。
- [x] 前端元件規劃已列。
- [x] 多校區隔離已說明。
- [x] 高風險點標記為需要測試覆蓋。

