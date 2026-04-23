---
name: single session duration pricing
overview: 當單堂課程時間長度被調整（開始或結束時間異動），系統根據實際時長與標準時長的比例自動計算該堂費用，並同步更新課程總費用（StudentClass.Charge）。
todos:
  - id: migration
    content: 新增 migration：ClassSession 加入 session_charge (nullable INT) 欄位
    status: completed
  - id: backend-calc
    content: ClassSessionController::applyTimeAndNoteUpdates 加入 session_charge 計算邏輯，並同步調整 StudentClass.Charge
    status: completed
  - id: backend-response
    content: ClassSessionController::index 回傳 session_charge 欄位
    status: completed
  - id: frontend-modal
    content: SessionEditModal 加入開始時間欄位、即時費用預覽（debounce 300ms）、偏離 ±50% 的二次確認 dialog
    status: completed
  - id: frontend-composable
    content: useSessionEditFlow.js 在 doEditNoteTime 加入 start_time 傳送與費用計算 helper
    status: completed
  - id: ux-refinement
    content: UI/UX 精緻化：費用預覽色彩（高/低/未設定）、loading 狀態、inline 錯誤、觸控目標
    status: completed
  - id: pest-tests
    content: 撰寫 Pest Feature tests：session_charge 計算（session/hour 模式）、Charge 差額同步、SessionDuration=0 邊界
    status: completed
  - id: smartcal-p1
    content: "[P1] SmartCalendar 單堂資訊面板顯示 session_charge / 標準費用"
    status: completed
  - id: code-review
    content: Code Review：Charge 差額計算安全性、防呆邊界（SessionDuration=0、rate_unit 未知值）
    status: completed
  - id: changelog
    content: 更新 docs/CHANGELOG.md
    status: completed
  - id: deploy
    content: 執行 npm run deploy，確認 index.html + assets 同步
    status: completed
  - id: ux-signoff
    content: UI/UX Designer 確認費用預覽色彩、二次確認 dialog、inline 錯誤樣式符合規格
    status: completed
  - id: pm-signoff
    content: PM 確認 DoD 全部打勾
    status: completed
isProject: false
---

# 單堂時間費率自動計算

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 單堂時間調整 × 費率自動計算 |
| 版本 / 日期 | v1.0 / 2026-04-17 |
| 狀態 | Draft |
| 目標角色 | 主任、老師（課程管理操作者） |

## 2. 目標與業務背景

**痛點**：課程排定後偶有單堂延長或縮短，但費用未隨實際上課時長調整，造成家長帳單與實際上課時間脫節。

**業務價值**：讓每堂課的收費更精準、透明，減少事後手動調帳。

**KPI**：調整單堂時間後，帳單金額自動反映，不需人工補正。

---

## 3. 範圍

**In Scope**
- 在課程管理（`CourseManagement`）的堂次編輯彈窗中，加入開始時間欄位，並顯示費用預覽
- 在智慧排課（`SmartCalendar`）點選單堂後，加入費用預覽與確認
- 儲存時，計算 `session_charge` 並寫入 `ClassSession`
- 同步調整 `StudentClass.Charge`（加時 → 補差額，縮時 → 扣差額）

**Out of Scope**
- 月結制課程（`settlement_day`）的結帳邏輯異動（本次僅調整快照 Charge，結帳邏輯維持原樣）
- 已開立帳單（`Invoice`）的自動重算（已開票者需手動更新）
- 批次調整多堂時間

---

## 4. RACI

| 角色 | R/A/C/I |
|---|---|
| PM | A |
| 工程（後端 + 前端） | R |
| UI/UX Designer | R（SessionEditModal 精緻化） |
| QA | R |
| 資安 | C |
| IT / Ops | I |

---

## 5. User Stories

**US-01（主任/老師 — 課程管理）**
> As a 主任, I want 在課程管理調整單堂的開始/結束時間時看到費用預覽, so that 我可以確認費用是否合理再儲存。
> - [ ] 彈窗顯示「此堂費用：NT$X」，隨時間輸入即時更新
> - [ ] 儲存後 `ClassSession.session_charge` 反映新費用
> - [ ] 儲存後 `StudentClass.Charge` ± 差額

**US-02（主任/老師 — 智慧排課）**
> As a 老師, I want 在智慧排課點選單堂時看到費用資訊, so that 我清楚知道這堂實際計費。
> - [ ] 單堂 panel 顯示 `session_charge`（若已調整）或標準費用
> - [TODO: 需確認] SmartCalendar 的單堂時間編輯入口是否為全新 UI 或沿用現有請假/調課流程

---

## 5b. UI/UX 精緻化需求

**SessionEditModal（課程管理）**

| 面向 | 要求 |
|---|---|
| 版面層次 | 現有「結束時間」欄位上方加入「開始時間」欄位；費用預覽區塊用 badge 或高亮行呈現，與輸入欄位有明確視覺分隔 |
| 色彩一致性 | 費用預覽文字使用主色；若費用高於標準費用以警示橘色標示，低於以藍色標示 |
| 互動回饋 | 時間欄位任一異動後立即（debounce 300ms）更新費用預覽，無需按儲存 |
| 空狀態 | 若 `Rate` 或 `SessionDuration` 未設定，費用預覽顯示「費率未設定，無法計算」 |
| 載入狀態 | 儲存按鈕 loading 動畫，防止重複送出 |
| 防呆設計 | 結束時間不可早於開始時間（inline 錯誤：「結束時間必須晚於開始時間」）；費用偏離標準 ±50% 以上時顯示二次確認 dialog（「此堂費用 NT$X，明顯偏離標準費用 NT$Y，確定儲存？」） |
| 響應式 | 欄位 stacked 排列，觸控目標 ≥ 44px |

---

## 6. 功能需求

- **FR-001**：`ClassSession` 新增 `session_charge`（nullable INT，單位：元）
- **FR-002**：`PATCH /api/v1/class-sessions/{id}` 收到 `start_time` 或 `end_time` 時，計算 `session_charge` 並存入
  - `rate_unit = 'session'`：`session_charge = Rate × (actual_minutes / SessionDuration)`
  - `rate_unit = 'hour'`：`session_charge = Rate × (actual_minutes / 60)`
  - `SessionDuration` 為 0 或 null 時 → `session_charge = null`（不計算）
- **FR-003**：`StudentClass.Charge` 依差額同步更新：`Charge += (new_session_charge - old_session_charge)`
  - `old_session_charge` 若為 null，以「標準費用」代入（session 模式 = Rate；hour 模式 = Rate × SessionDuration/60）
- **FR-004**：`GET /api/v1/class-sessions` 回傳 `session_charge`（null 表示標準費用）
- **FR-005**：`SessionEditModal`（CourseManagement）加入開始時間欄位，並即時顯示費用預覽
- **FR-006**：費用偏離標準 ±50% 以上時，前端顯示二次確認 dialog
- **FR-007**：SmartCalendar 的單堂資訊面板顯示 `session_charge`（或標準費用）（P1）

---

## 7. 非功能需求

- PATCH 端點含費用計算的整體回應時間 < 500ms
- `session_charge` 計算使用 PHP `round()` 取整數，前端顯示 `Math.round()`
- 已開立 Invoice 的 `session_charge` 修改：後端寫入 `ClassSession` + 調整 `Charge`，但不自動更新 Invoice（Invoice 需另外操作）

---

## 8. 技術方向

**受影響頁面**
- `frontend/src/components/course-management/SessionEditModal.vue`
- `frontend/src/composables/course-management/useSessionEditFlow.js`
- `frontend/src/pages/SmartCalendar.vue`（P1）

**受影響 API / Controller**
- `PATCH /api/v1/class-sessions/{id}` → `ClassSessionController::update`
- `GET /api/v1/class-sessions` → `ClassSessionController::index`（加回 `session_charge`）

**受影響資料表**
- `ClassSession`：加 `session_charge` 欄位
- `StudentClass`：`Charge` 欄位被調整（existing column）

**需要 migration**
- `add_session_charge_to_class_session`：`session_charge` nullable INT

**子任務派發**
- `[FEATURE]` → migration + `ClassSessionController` 計算邏輯 + `SessionEditModal` UI
- `[TEST]` → Pest Feature tests（FR-002、FR-003）
- `[REVIEW]` → 確認 Charge 差額計算、防呆邊界
- `[DOCS]` → CHANGELOG 更新

---

## 9. 資安與存取控制

- `PATCH /api/v1/class-sessions/{id}` 已受 `auth:sanctum` + `require_campus` 保護，無需額外異動
- `session_charge` 為財務敏感欄位：回傳對象限 `director` / `teacher`（現有 role 控制不動）
- STRIDE：`session_charge` 被篡改 → 由 `require_campus` 隔離校區；計算邏輯在後端，前端傳入 `start_time`/`end_time` 即可，不接受前端直接傳 `session_charge`

---

## 10. QA 驗收標準

**FR-002 / FR-003 驗收**

| 情境 | 輸入 | 期望 |
|---|---|---|
| 正常縮短 | Rate=1500, SessionDuration=120min, 改為 90min | session_charge=1125, Charge-=375 |
| 正常延長 | Rate=1500, SessionDuration=120min, 改為 180min | session_charge=2250, Charge+=750 |
| 小時費率 | Rate=750/hr, SessionDuration=120min, 改為 90min | session_charge=1125（750×1.5） |
| SessionDuration=0 | 任何時間 | session_charge=null, Charge 不變 |
| 已有 session_charge 再次修改 | 舊=1125, 改為 180min | delta=2250-1125=+1125, Charge+=1125 |
| 偏離 ±50% | Rate=1500, 改為 20min（1/6 標準） | 前端顯示二次確認 |

**UI/UX 驗收清單**
- [ ] 空狀態有說明文字（費率未設定時）
- [ ] 時間異動後 ≤ 300ms 費用預覽更新
- [ ] 偏離 ±50% 出現二次確認 dialog
- [ ] 結束時間早於開始時間有 inline 錯誤
- [ ] 儲存中有 loading 狀態，防重複送出

---

## 11. 上線與維運

1. `php artisan migrate`（新增 `session_charge` 欄位）
2. `cd frontend && npm run deploy`
3. 現有資料：`session_charge = null`（視為標準費用，不影響現有 Charge）
4. 回滾：`session_charge` nullable，移除前端計算邏輯即可降回舊行為

---

## 12. 里程碑與優先級

| 優先級 | 功能 | 執行 Agent |
|---|---|---|
| P0 | Migration + 後端計算邏輯（FR-001~004） | `[FEATURE]` |
| P0 | SessionEditModal 加開始時間 + 費用預覽 + 二次確認（FR-005、006） | `[FEATURE]` + UI/UX |
| P0 | Pest 測試（FR-002、FR-003） | `[TEST]` |
| P1 | SmartCalendar 單堂面板顯示 session_charge（FR-007） | `[FEATURE]` |
| P2 | Invoice 開立時自動帶入 session_charge（非本次範疇） | 待規劃 |

---

## 13. 風險、假設、開放問題

**風險**
- `StudentClass.Charge` 被多次調整可能與原始 Charge 脫節（中：`session_charge` 保留原始計算依據可追溯）
- `SessionDuration` 為 null 的舊課程：session_charge 不計算，Charge 不動（低）

**假設**
- `SessionDuration` 以分鐘為單位（與現有 `EnrollmentService` 一致）
- `rate_unit` 僅有 `session` / `hour` 兩種
- `duration1~duration6` 的每日時長 [TODO: 需確認] 是否需要作為 standard_duration 的 fallback（vs 只用 SessionDuration）

**開放問題**
- [TODO: 需確認] SmartCalendar 的單堂時間編輯入口：是現有調課/請假流程，還是需要全新入口？

---

## 14. Definition of Done

- [ ] FR-001~006 全部通過 QA 驗收
- [ ] UI/UX 驗收清單打勾，UI/UX Designer sign-off
- [ ] `ClassSession.session_charge` 計算邏輯 Code Review 通過
- [ ] `npm run deploy` 正常，API health 正常
- [ ] `docs/CHANGELOG.md` 更新
- [ ] PM sign-off
