---
name: 單堂費用固定不隨時長
overview: 修正「備註 / 調整時段」模式下，按堂計費（rate_unit=session）課程在前端預覽與後端寫入時，費用被時長比例縮放的錯誤；應固定顯示排課設定的每堂金額，與業界按堂計費慣例一致。
todos:
  - id: backfill-migration
    content: "[FEATURE] 撰寫並執行 Backfill Migration：找出所有 session mode 且 session_charge ≠ Rate 的 class_sessions，批次修正 session_charge = Rate，並將 delta 回補至 student_classes.Charge；執行前將受影響 rows 備份至稽核記錄，確保可回滾"
    status: completed
  - id: backend-api
    content: "[FEATURE] 修正後端 PATCH /api/v1/class-sessions/{id} 的計費邏輯：session mode 下 session_charge 固定為合約 Rate，不依時長縮放，確保 Charge delta 不被錯誤異動"
    status: completed
  - id: frontend-feature
    content: "[FEATURE] 修正 SessionEditModal「此堂費用」預覽（session mode 固定顯示 Rate）；修正 SmartCalendar「本堂費用」改為 session mode 時直接取 Rate（Single Source of Truth），不依賴可能過期的 session_charge"
    status: completed
  - id: frontend-ux
    content: "[FEATURE / UI/UX] 依第 5b 節規格精緻化費用區塊：session mode 不顯示 delta chip、不觸發偏離對話框；提示文字依計費模式條件顯示；費用色彩保持 standard（藍），無警告色；確保無 layout shift"
    status: completed
  - id: test-design
    content: "[TEST] 設計自動化回歸測試（Pest Feature Test）與 QA 手動測試案例（含 UI/UX 驗收項目）：session mode 固定費、hour mode 時長縮放、SmartCalendar Source of Truth 顯示、Backfill Migration 前後資料正確性"
    status: completed
  - id: qa-acceptance
    content: QA 執行 PRD 第 10 節所有 FR 驗收測試（含 Backfill 後帳務數字確認），UI/UX 驗收清單全部打勾
    status: completed
  - id: security-review
    content: "[REVIEW] 確認存取控制與 STRIDE 無阻擋風險（費用欄位僅主任可改，Backfill Migration 無 PII 洩漏，稽核記錄正確）"
    status: completed
  - id: code-review
    content: "[REVIEW] 對前後端變動執行 Code Review，確認 session/hour 兩分支邏輯清晰獨立、Backfill Migration 有回滾腳本、SmartCalendar Source of Truth 改法正確"
    status: completed
  - id: docs-update
    content: "[DOCS] 更新 docs/CHANGELOG.md，記錄 session mode 費用固定修正與 Backfill Migration；在 docs/AI_REGRESSION_LESSONS.md 補入防再犯條目（session mode 不得比例縮放）"
    status: completed
  - id: deploy
    content: IT/Ops：依第 11 節步驟順序執行—先跑 Backfill Migration → 後端 OPcache 清除 → cd frontend && npm run deploy（index.html 與 assets 同輪寫入）→ API health 確認
    status: completed
  - id: ux-signoff
    content: UI/UX Designer sign-off：確認第 5b 節所有精緻化項目已實作並符合規格（session mode 費用色彩、提示文字措辭、無 layout shift、SmartCalendar 費用顯示一致）
    status: completed
  - id: pm-signoff
    content: PM sign-off：確認 DoD 全部打勾，費用顯示與排課設定一致，Backfill 稽核記錄已備份
    status: completed
isProject: false
---

# PRD — 單堂費用固定不隨時長縮放

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 單堂費用顯示與寫入修正（按堂計費固定金額） |
| 版本 / 日期 | v1.0 / 2026-04-17 |
| 狀態 | Draft |
| 目標角色 | 主任（排課與記帳）；間接受益：家長（帳單正確）、老師（薪資對帳） |

---

## 2. 目標與業務背景

**痛點（非技術語言）**

主任在「單堂檢視 → 備註 / 調整時段」畫面調整上課時間後，畫面顯示的「此堂費用」金額與排課設定的每堂金額不符。例如：合約設定每堂 NT$5,000，但畫面顯示或後端寫入的金額因時長比例計算而出現偏差，導致主任必須手動核對每筆帳，且若儲存後，課程總費用（Charge）也會被錯誤調整，帳務混亂難以追溯。

**業務價值**

- 確保按堂計費課程的單堂金額永遠等於合約費率，主任不必手動核對。
- 防止因前端預覽錯誤導致主任誤按「確定儲存」，造成難以回溯的 Charge 異動。
- 與補習班業界慣例一致：「按堂計費」是固定費，「按時計費」才依實際時長計費。

**成功指標（KPI）**

- 按堂計費課程在「備註 / 調整時段」畫面顯示費用 = 合約 Rate，誤差 0%。
- 儲存後，`class_sessions.session_charge` = 合約 Rate（session mode），`student_classes.Charge` 無非預期異動。
- QA 回歸測試 0 個 regression。

---

## 3. 範圍

**In Scope**

- 「備註 / 調整時段」模式下，session mode 課程的費用預覽顯示修正。
- 後端 PATCH 時，session mode 課程的 `session_charge` 寫入修正。
- 費用區塊下方提示文字更新，區分兩種計費行為。
- SmartCalendar「本堂費用」：依賴後端已存的 `session_charge`，修正後新儲存的堂次即正確（不需額外改動）。

**Out of Scope**

- `rate_unit=hour`（按時計費）行為不變。
- 歷史已存入的錯誤 `session_charge` 資料 migration（本次不做，見風險節說明）。
- 排課設定（新增 / 編輯課程）畫面的費率計算邏輯。
- 薪資 / 教師薪酬計算。

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| PM | 產品負責人 | A |
| CTO / 工程 | 後端 + 前端工程師 | R |
| UI/UX Designer | 視覺與互動品質把關 | R |
| QA | 測試工程師 | R |
| 資安 | 安全審查 | C |
| IT / Ops | 部署與維運 | I |

> **UI/UX Designer 職責**：負責費用區塊視覺精緻化（色彩語意、提示文字措辭、無 layout shift）；第 5b 節與第 10 節 UI/UX 驗收清單須 sign-off 後方可列入 DoD。

---

## 5. User Stories

> **As a** 主任，**I want** 在「備註 / 調整時段」畫面調整上課時間後，「此堂費用」仍顯示排課設定的每堂金額，**so that** 我可以放心儲存，不必擔心帳務被錯誤異動。
>
> Acceptance Criteria：
> - [ ] 課程為按堂計費（rate_unit=session）時，不論如何調整起訖時間，「此堂費用」數字始終等於合約 Rate。
> - [ ] 儲存後，後端 `session_charge` = 合約 Rate，`Charge` 無差額異動。
> - [ ] 費用區塊不顯示「高於標準 / 低於標準」等 delta 提示（delta 對 session mode 無意義）。
> - [ ] 提示文字明確告知：按堂計費時段調整不影響費用。

> **As a** 主任，**I want** 按時計費（rate_unit=hour）課程在調整時段後仍依實際時長計算費用，**so that** 時長超出或縮短時，費用能自動反映。
>
> Acceptance Criteria：
> - [ ] 課程為按時計費時，費用 = 合約 Rate × 實際分鐘數 / 60，行為與修正前相同。
> - [ ] delta chip 與「明顯偏離」確認對話框仍正常觸發。

---

## 5b. UI/UX 精緻化需求

**頁面：單堂檢視 → 備註 / 調整時段（SessionEditModal）**

| 面向 | 要求描述 |
|---|---|
| **版面層次** | 費用區塊（`se-charge-preview`）維持現有卡片樣式；session mode 下費用數字大、粗體、藍色（primary），無警告橘 / 紅色；移除 delta chip 後，區塊高度應縮短，避免空白過多（調整 padding）。 |
| **色彩一致性** | session mode 費用色彩固定使用 `se-charge-standard`（藍色），不觸發 `se-charge-higher`（橘）或 `se-charge-lower`（紅）。hour mode 色彩邏輯不變。沿用既有 design token，不新增顏色。 |
| **互動回饋** | 儲存成功後，現有 toast / 關閉 modal 行為不變；session mode 下，因無 delta 不需觸發「明顯偏離」二次確認對話框。 |
| **空狀態設計** | 費率未設定時（`kind=no-rate`）維持現有「費率未設定，無法計算」文字；時段無效時維持「請輸入有效的時段」文字，不需改動。 |
| **載入狀態** | 儲存按鈕按下後的 `處理中…` overlay 行為不變；費用預覽為即時 computed，無非同步載入，無需 skeleton。 |
| **防呆設計** | 提示文字（目前：「若費用因時長變動，課程總費用（Charge）也會依差額調整」）須依計費模式顯示不同說明：session mode 顯示「按堂計費：時段調整不影響費用」；hour mode 顯示「按時計費：若時長變動，課程總費用（Charge）也會依差額調整」。措辭正向、非錯誤警告語氣。 |
| **響應式 / 行動裝置** | Modal 本身已有 max-width 520px 限制，行動裝置操作方式不變；觸控目標（儲存 / 返回按鈕）≥ 44px，沿用現有 CSS，本次不需額外調整。 |

---

## 6. 功能需求（FR）

**FR-001**：系統應在「備註 / 調整時段」畫面，對 `rate_unit=session` 課程，顯示的「此堂費用」= 合約 Rate，不隨起訖時間變動而縮放。

**FR-002**：系統應在儲存時，對 `rate_unit=session` 課程，將 `class_sessions.session_charge` 寫入合約 Rate 的整數值；若新值與舊值相同，`student_classes.Charge` 不得被異動。

**FR-003**：系統應在「備註 / 調整時段」畫面，對 `rate_unit=session` 課程，不顯示費用 delta chip（「高於標準 / 低於標準」），也不觸發「明顯偏離」確認對話框。

**FR-004**：系統應在「備註 / 調整時段」畫面底部，依課程計費模式顯示對應提示文字：session mode 顯示「按堂計費：時段調整不影響費用」；hour mode 顯示「按時計費：若時長變動，課程總費用（Charge）也會依差額調整」。

**FR-005**：系統應維持 `rate_unit=hour` 課程原有時長比例計費行為（費用 = Rate × 實際分鐘 / 60），本次修正不得影響 hour mode。

---

## 7. 非功能需求（NFR）

- **效能**：「此堂費用」為即時 computed，UI 回應 < 50ms（無非同步呼叫），不影響現有效能。
- **錯誤處理**：若合約 Rate 未設定（= 0 或 null），維持現有「費率未設定，無法計算」降級顯示，不 crash。
- **向後相容**：修正後，舊的錯誤 `session_charge`（已被比例縮放存入 DB）將在下次儲存時被修正覆寫，屬預期的一次性補正；不影響尚未被修改的歷史堂次。
- **可維護性**：session / hour 兩個計費分支應有清晰的程式碼注解，方便未來擴充計費模式。

---

## 8. 技術方向（給 CTO，非實作細節）

**受影響的頁面 / API / 資料表**

- 頁面：單堂檢視 Modal（`SessionEditModal`）、智慧排課日曆（`SmartCalendar`，間接；依賴後端已存值）
- API：`PATCH /api/v1/class-sessions/{id}`
- 資料表：`class_sessions`（欄位 `session_charge`）、`student_classes`（欄位 `Charge`、`Rate`、`rate_unit`）

**架構選擇取捨**

- 修正「計費分支判斷」：在後端計費同步函式與前端費用預覽計算中，明確區分 session / hour 兩個分支，session mode 直接取 Rate，不做比例運算。選擇在現有函式內增加分支，而非新增獨立函式，理由：改動最小、最易 review、不影響其他呼叫路徑。
- 不做歷史資料 migration：只在「下次儲存時自動修正」，理由：現有錯誤資料量未知，強制 migration 有 Charge 帳務連帶風險，需 PM 另行確認後才執行（見開放問題）。

**是否需要 migration**：本次不做。詳見風險節。

**子任務 Agent 派發**：

- `[FEATURE]` → 後端 PATCH API 計費分支修正 + 前端 SessionEditModal 費用預覽修正 + 提示文字條件顯示
- `[TEST]` → 設計 QA 手動測試案例（含 UI/UX 驗收項目）
- `[REVIEW]` → 程式碼審查（前後端計費分支邏輯）
- `[DOCS]` → 更新 `docs/CHANGELOG.md` 與 `docs/AI_REGRESSION_LESSONS.md`

---

## 9. 資安與存取控制

- **存取控制**：`PATCH /api/v1/class-sessions/{id}` 已限制 `middleware role:director`，本次不需變更權限設定。
- **PII**：`session_charge` 為財務金額，非個人識別資訊；`student_classes.Charge` 同。無新增 PII 欄位。
- **稽核 log**：`session_charge` 變動已透過現有 PATCH 流程記錄；本次修正後若 session mode 的 delta = 0，Charge 不被異動，不需新增 log。若日後需要明確記錄「計費模式 = session，費用固定未調整」，可在稽核 log 補充計費模式欄位，本次列為 P2。
- **STRIDE 快評**：
  - **Tampering**：PATCH 已驗證 token + role，惡意修改 Rate 欄位需有 director 權限，風險低。
  - **Information Disclosure**：`session_charge` 僅透過主任端 API 回傳，無額外洩漏風險。
  - 其餘 STRIDE 項目（Spoofing / Repudiation / DoS / Elevation）本次修正無新增攻擊面。

---

## 10. QA 驗收標準與測試計畫

### FR-001 / FR-002 / FR-003（session mode 費用固定）

**Happy Path**

- 開啟按堂計費課程的「備註 / 調整時段」，確認「此堂費用」= 合約 Rate。
- 調整結束時間（縮短 / 延長），確認費用數字不變。
- 按下儲存，確認 DB `session_charge` = 合約 Rate，`Charge` 無異動。

**Edge Case**

- 開始時間 = 結束時間（時長 0 分鐘）：應顯示「請輸入有效的時段」，不顯示費用數字。
- 合約 Rate 未設定（0 或 null）：應顯示「費率未設定，無法計算」。
- 課程已有舊的（比例縮放）`session_charge`：儲存後 `session_charge` 更新為 Rate，`Charge` 差額自動修正（一次性補正）。

**Error Case**

- 儲存 API 回傳 500：Toast 顯示錯誤，`session_charge` 不被異動。

**回歸測試**

- 參照 `docs/AI_REGRESSION_LESSONS.md`「編輯課程費率後 Charge 未同步」條目：確認本次修正後，session mode Charge 在費率不變時仍維持正確值，不被 delta 誤算沖銷。

### FR-004（提示文字條件顯示）

- session mode：底部文字顯示「按堂計費：時段調整不影響費用」。
- hour mode：底部文字顯示「按時計費：若時長變動，課程總費用（Charge）也會依差額調整」。

### FR-005（hour mode 行為不變）

- 按時計費課程，調整時長後費用 = Rate × 實際分鐘 / 60，delta chip 與偏離確認框正常運作。

### UI/UX 驗收清單

- [ ] 空狀態（費率未設定 / 時段無效）有說明文字，非空白
- [ ] 儲存按下後有「處理中…」loading overlay，無 layout shift
- [ ] 成功儲存後 modal 關閉，行為與修正前一致
- [ ] session mode 費用色彩為 standard（藍），無橘 / 紅警告色
- [ ] session mode 不出現 delta chip、不觸發偏離對話框
- [ ] 提示文字措辭正向，依計費模式顯示對應說明
- [ ] 行動裝置（375px 寬）按鈕觸控目標 ≥ 44px，無水平 overflow

---

## 11. 上線與維運

**部署步驟（順序不可顛倒）**

1. **[PRE-DEPLOY] Backfill Migration 執行**：在應用程式碼上線前，先執行資料修復 migration（見第 13 節風險解法），確保 DB 資料在應用上線時已是乾淨狀態。
2. 後端程式碼 deploy：清除 OPcache（重啟 php-fpm）。
3. 前端 build + deploy：`cd frontend && npm run deploy`，確認 `backend/public/index.html` 與 `assets/` 同輪寫入（禁止只更新部分 assets）。
4. 確認 API health：`GET /api/v1/health` 回傳 200。
5. 在 staging 環境執行 QA 驗收 Happy Path（含 SmartCalendar 費用顯示確認）。

**監控新增項目**：本次無新增 API endpoint，不需新增監控。

**回滾方案**：
- 前端：git revert 後重新 `npm run deploy`。
- 後端程式碼：git revert 後重啟 php-fpm。
- Backfill Migration：migration 執行前已將受影響 rows 備份至 `session_charge_audit_log` 暫存記錄（或等效稽核 log），可依備份還原 `session_charge` 與 `Charge` 差額，執行回滾 migration 腳本。

---

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 預估工期 | 執行 Agent |
|---|---|---|---|
| P0（Must Have） | Backfill Migration：修復歷史錯誤 `session_charge` 並補正 `Charge` | 0.5h | `[FEATURE]` |
| P0（Must Have） | 後端 PATCH 計費分支修正（session mode 固定 Rate） | 0.5h | `[FEATURE]` |
| P0（Must Have） | 前端費用預覽修正 + SmartCalendar Source of Truth 修正 | 0.5h | `[FEATURE]` |
| P0（Must Have） | UI/UX 精緻化（色彩、提示文字措辭） | 0.5h | `[FEATURE] / UI/UX` |
| P1（Should Have） | 回歸測試設計（session / hour 兩分支自動化測試） | 0.5h | `[TEST]` |
| P1（Should Have） | QA 驗收 + Code Review | 1h | QA / `[REVIEW]` |
| P1（Should Have） | CHANGELOG + Regression Lessons 更新 | 0.5h | `[DOCS]` |

---

## 13. 風險、假設、開放問題

### 風險（含業界解法）

**風險一：既有錯誤 `session_charge` 造成帳務不一致**

- 問題：過去 session mode 課程若已調整過時段，`session_charge` 為比例縮放值，`Charge` 亦被錯誤異動。
- 業界解法（**Backfill Migration**）：部署應用程式碼**之前**，執行一次性資料修復 migration。具體做法：
  1. 找出所有 `rate_unit='session'` 且 `session_charge IS NOT NULL AND session_charge ≠ Rate` 的 `class_sessions` rows。
  2. 計算每筆 delta（`Rate − session_charge`），批次將 `session_charge` 更新為 `Rate`，並將 delta 累計後回補至對應 `student_classes.Charge`。
  3. Migration 執行前，先將受影響 rows 的 before/after 寫入稽核記錄（`session_charge_audit_log` 或 CHANGELOG 附錄），供主任事後對帳。
- 結果：應用上線時 DB 已乾淨，不再有「使用者下次儲存時觸發預期外修正」的資料不一致窗口（data inconsistency window）。

**風險二：SmartCalendar 顯示過期的錯誤 `session_charge`**

- 問題：SmartCalendar「本堂費用」直接讀取 DB `session_charge`，若值為舊的比例縮放值，顯示錯誤。
- 業界解法（**Single Source of Truth**）：`session_charge` 是衍生值（derived field），session mode 的費用顯示不應依賴可能過期的衍生值，應在展示層回歸原始資料來源（合約 Rate）。具體做法：SmartCalendar 費用顯示邏輯改為：當 `rate_unit=session` 時，直接以合約 Rate 作為「本堂費用」顯示，忽略 `session_charge`；當 `rate_unit=hour` 時，仍顯示 `session_charge`（反映實際時長計費結果）。搭配 Backfill Migration，DB 與 UI 同步正確，此風險徹底消除。

**風險三：hour mode 回歸**

- 問題：修改計費分支時可能誤動 hour mode 邏輯。
- 業界解法（**防衛性分層測試**）：為計費分支邏輯補充自動化回歸測試（`[TEST]`），分別覆蓋 session / hour 兩種 `rate_unit` 的儲存行為，在 CI pipeline 中執行，任何回歸即時攔截；Code Review checklist 明確要求 reviewer 確認兩分支均有測試覆蓋且相互獨立。

### 設計決策（原「假設」，已確認為產品定義）

- `rate_unit='session'`：固定堂費。每堂收費 = 合約 Rate，與實際上課分鐘數無關。時段調整僅為記錄用途，不影響費用。此為本補習班管理系統的產品定義，非假設。
- `rate_unit='hour'`：按時計費。費用 = Rate × 實際分鐘 / 60，時段調整會影響費用。行為不變。
- 系統不支援「按堂計費但費用依時長比例縮放」的混合模式；如未來有此需求，需新增 `rate_unit` 枚舉值，不得修改現有 session / hour 語意。
- 合約 Rate 為 0 或 null 屬異常資料，由現有「費率未設定，無法計算」降級顯示覆蓋，不在本次修正範圍。

### 開放問題（已由業界解法關閉）

- ~~歷史資料 migration 是否執行~~ → **已納入 P0 Backfill Migration**，部署前強制執行，不再是開放問題。
- ~~SmartCalendar 是否需要「舊值提示」~~ → **已由 Single Source of Truth 解法消除**：session mode 改為直接顯示 Rate，根本不存在舊值，無需任何提示。

---

## 14. Definition of Done

- [ ] Backfill Migration 執行完成；稽核記錄已備份；受影響 rows 的 `session_charge` 與 `Charge` 數字經主任抽查確認正確
- [ ] 所有 FR（FR-001 ～ FR-005）通過 QA 驗收
- [ ] SmartCalendar「本堂費用」在 session mode 下顯示合約 Rate（Single Source of Truth），QA 確認
- [ ] 自動化回歸測試（session / hour 兩分支）在 CI 通過
- [ ] **UI/UX 驗收清單（第 10 節）全部打勾，UI/UX Designer sign-off**
- [ ] 資安審查無阻擋項（STRIDE 快評已過，Backfill 稽核記錄存在）
- [ ] `npm run deploy` 完成，`index.html` 與 `assets/` 同輪寫入，API health 正常
- [ ] `docs/CHANGELOG.md` 更新，記錄修正內容與 Backfill Migration 說明
- [ ] `docs/AI_REGRESSION_LESSONS.md` 補入本次防再犯條目
- [ ] PM sign-off
- [ ] CTO / 工程 Lead sign-off
