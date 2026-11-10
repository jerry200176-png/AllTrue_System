---
name: 繳費清除與聊天搜尋修復
overview: 修復兩個已知 Bug：(1) 標記未繳費後繳費日期未清除導致顯示混亂；(2) 老師帳號在手機開啟聊天後無法搜尋到對象。
todos:
  - id: feat-bug-a-frontend
    content: "[FEATURE] StudentsList.vue：togglePaymentStatus 切換 unpaid 時 PUT payload 加入 paid_at: null；收到成功回應後本地 last_paid_at 同步清空"
    status: completed
  - id: feat-bug-a-backend
    content: "[FEATURE] StudentClassController::mapFrontendPayload：當 payment_status === 'unpaid' 且未傳 paid_at 時，自動設 PayDate=null（防守層）"
    status: completed
  - id: feat-bug-b-css
    content: "[FEATURE] ChatPage.vue：新對話 modal 內 .form-input 在 @media (max-width: 768px) 套用 font-size: 16px，防 iOS viewport 縮放"
    status: completed
  - id: feat-bug-b-toast
    content: "[FEATURE] ChatPage.vue：loadStaff catch 區塊顯示使用者可見 toast 錯誤訊息（含重試按鈕），staffList skeleton loader"
    status: completed
  - id: ux-refinement
    content: "[UI/UX 精緻化] 依 5b 節規格：繳費狀態切換按鈕 loading/disabled；聊天 modal 空狀態插圖+CTA；toast 位置/時長符合規格；觸控目標 ≥ 44px"
    status: completed
  - id: test-design
    content: "[TEST] 撰寫 Pest Feature Test 覆蓋 FR-A-001/A-002（payment_status unpaid + paid_at null 組合）；設計 QA 手動測試案例含 iOS Safari 手機驗收"
    status: completed
  - id: qa-verify
    content: QA 驗收：執行第 10 節所有 FR Happy Path / Edge / Error Case；含 UI/UX 驗收清單全部打勾
    status: completed
  - id: security-review
    content: "[REVIEW] 資安確認：PUT endpoint role check 無旁路；toast 無 stack trace 洩漏；評估是否需 audit log for clear_paid_at"
    status: completed
  - id: code-review
    content: "[REVIEW] Code Review：StudentsList.vue togglePaymentStatus 修改 + mapFrontendPayload 防守層"
    status: completed
  - id: docs-update
    content: "[DOCS] 更新 docs/CHANGELOG.md（Bug A 繳費日期清除修復 + Bug B 聊天手機搜尋修復各一條）"
    status: completed
  - id: deploy
    content: IT/Ops 部署：後端 config:cache；cd frontend && npm run deploy；確認 index.html + assets chunk 同步；curl 驗證 API 行為；iOS Safari 手機驗證
    status: completed
  - id: ux-signoff
    content: UI/UX Designer sign-off：確認第 5b 節精緻化項目（空狀態插圖、skeleton、toast 規格、手機 modal 排版）全部實作並符合規格
    status: completed
  - id: pm-signoff
    content: PM sign-off：DoD 全部打勾，確認兩個 Bug 皆通過驗收
    status: completed
isProject: false
---

# Bug Fix PRD：繳費日期清除 & 聊天手機搜尋

---

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | Bug Fix：繳費日期清除 / 聊天手機搜尋 |
| 版本 / 日期 | v1.0 / 2026-04-17 |
| 狀態 | Draft |
| 目標角色 | 主任（繳費管理）、老師（手機使用內部聊天） |

---

## 2. 目標與業務背景

### Bug A — 繳費日期無法清除

**痛點：** 主任在學生課程清單誤設繳費日期後，點擊「未繳費」按鈕無法真正清除，畫面仍顯示舊日期，導致帳務狀態判斷錯誤、無法重新追蹤欠費。

**業務價值：** 帳務正確性直接影響催繳提醒與財務報表；修復後可確保繳費狀態真實反映資料庫。

**KPI：** 按下「未繳費」後，畫面 `last_paid_at` 欄位清空率 100%；迴歸後催繳邏輯錯誤率降至 0。

### Bug B — 聊天手機搜尋失效

**痛點：** 老師帳號在手機（iOS Safari）開啟「新對話」後點擊搜尋輸入框，iOS 觸發 viewport 縮放，modal 跑版，搜尋結果無法正常顯示；另外 `staffList` 載入失敗時無任何 UI 提示，老師以為沒有任何聯絡人。

**業務價值：** 內部溝通順暢度直接影響跨分校協作效率；修復後老師在手機可正常找到並發起對話。

**KPI：** 手機 iOS Safari / Android Chrome 測試中，搜尋框可正常輸入且結果即時顯示率 100%；staffList 載入失敗有 toast 提示。

---

## 3. 範圍

**In Scope：**
- Bug A：`togglePaymentStatus` 切換未繳費時同步清除 `PayDate`（後端）
- Bug A：`confirmPayment` 確認繳費時若反向操作也需清除 `PayDate` `[TODO: 需確認]`
- Bug B：`ChatPage.vue` 新對話 modal 內搜尋輸入框字型 ≥ 16px（防 iOS 縮放）
- Bug B：`loadStaff` 失敗時顯示使用者可見 toast 錯誤提示

**Out of Scope：**
- 繳費日期欄位的完整重新設計（另立需求）
- 聊天功能的跨分校可見性規則調整
- 老師帳號 ProfileController 可見範圍擴充
- Android 系統層級輸入問題

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| PM | 產品負責人 | A |
| CTO / 工程 | 後端 + 前端工程師 | R |
| UI/UX Designer | 介面設計師 | R（Bug B 行動體驗） |
| QA | 測試人員 | R |
| 資安 | 資安審查 | C |
| IT / Ops | 部署維運 | I |

---

## 5. User Stories

### Bug A

> **As a** 主任，**I want** 點擊課程列表的「未繳費」按鈕後，畫面繳費日期同步清空，**so that** 帳務狀態正確且催繳提醒不誤報。
>
> Acceptance Criteria：
> - [ ] 課程原本有 `PayDate`，按「未繳費」後，API 回應中 `last_paid_at` 為 `null`
> - [ ] 畫面繳費日期欄位顯示「—」或空白，不再顯示舊日期
> - [ ] 資料庫 `StudentClass.PayDate` 確實為 `NULL`、`Paid = 0`

### Bug B

> **As a** 老師，**I want** 在手機 iOS / Android 打開聊天搜尋框時，可正常輸入文字且結果即時更新，**so that** 能快速找到同事發起對話。
>
> Acceptance Criteria：
> - [ ] iOS Safari 點擊搜尋輸入框時 viewport 不縮放（font-size ≥ 16px）
> - [ ] 輸入部分姓名後，`filteredStaffList` 正確過濾並顯示
> - [ ] `loadStaff` API 失敗時，畫面顯示「載入聯絡人失敗，請重試」toast
> - [ ] toast 消失後仍可重試載入（或自動重試一次）

---

## 5b. UI/UX 精緻化需求

### Bug A — `StudentsList.vue` 課程列表繳費狀態欄

| 面向 | 要求 |
|---|---|
| **版面層次** | 按下「未繳費」後，日期欄位應由文字即時切換為「—」，不得有短暫殘留舊值的 flash |
| **色彩一致性** | 已繳費（綠色 tag）→ 未繳費（灰色 tag）狀態切換沿用既有 design token；不引入新顏色 |
| **互動回饋** | 按鈕點擊後顯示 loading spinner（按鈕 disabled），操作完成後顯示成功 toast（位置：右上角，持續 2 秒） |
| **空狀態設計** | 本次不涉及空列表場景 |
| **載入狀態** | 切換期間按鈕 disabled + spinner，防止重複點擊 |
| **防呆設計** | 若有發票或已核准評量關聯，清空繳費前需顯示警示 dialog `[TODO: 需確認關聯規則]` |
| **響應式** | 課程列表在手機寬度需確認繳費欄位不截斷；tag 最小觸控目標 ≥ 44px |

### Bug B — `ChatPage.vue` 新對話 modal 搜尋區

| 面向 | 要求 |
|---|---|
| **版面層次** | 搜尋框下方結果列表維持現有卡片樣式，不縮小行高；在手機 < 768px 時 modal 最大高度限制為 80vh，結果區域可捲動 |
| **色彩一致性** | 搜尋框 focus ring 使用既有主色 `var(--primary)`，沿用設計 token |
| **互動回饋** | 載入中：搜尋結果區顯示 3 個 skeleton row；`loadStaff` 失敗：toast 顯示錯誤訊息（右上角，持續 4 秒，含「重試」按鈕） |
| **空狀態設計** | 搜尋無結果時顯示插圖 + 文字「找不到符合的人員」+ 「清除搜尋」CTA，不顯示空白區域 |
| **載入狀態** | `staffList` 載入中顯示 skeleton，避免用戶以為「沒有聯絡人」 |
| **防呆設計** | 搜尋框 `font-size: 16px`（手機 override），防止 iOS 觸發縮放；`autocomplete="off"` 已設定 |
| **響應式** | modal 在 < 768px：全寬呈現、結果列表捲動高度限制、觸控列表項 ≥ 44px 高度 |

---

## 6. 功能需求（FR）

**Bug A:**

- **FR-A-001：** 系統應於 `togglePaymentStatus` 切換至 `unpaid` 時，PUT payload 中包含 `paid_at: null`，使後端同步清除 `PayDate`
- **FR-A-002：** 後端 `StudentClassController::update` 當 `payment_status: 'unpaid'` 且未傳 `paid_at` 時，應自動清除 `PayDate = null`（防守）
- **FR-A-003：** 前端收到成功回應後，本地課程資料 `last_paid_at` 同步設為 `null`，不需重整頁面

**Bug B:**

- **FR-B-001：** `ChatPage.vue` 新對話 modal 內所有 `.form-input` 在手機斷點（< 768px）套用 `font-size: 16px`
- **FR-B-002：** `loadStaff` catch 區塊應顯示使用者可見錯誤 toast，而非只 `console.error`
- **FR-B-003：** `staffList` 載入期間，搜尋結果區顯示 skeleton loader，避免顯示空狀態誤導用戶

---

## 7. 非功能需求（NFR）

- `togglePaymentStatus` PUT API 回應 < 500ms（P99）
- `loadStaff` API 回應 < 1000ms；失敗時 toast 於 3 秒內顯示
- 修復不應影響繳費確認（`confirm-payment`）、課程編輯（`submitCourse`）的現有行為
- `npm run deploy` build 不引入新 chunk hash 衝突（遵守 AI_REGRESSION_LESSONS hash 同步規則）

---

## 8. 技術方向（給 CTO）

### Bug A

- **受影響頁面：** `StudentsList.vue`
- **受影響 API：** `PUT /api/v1/student-classes/{id}`
- **受影響表：** `StudentClass`（欄位 `Paid`、`PayDate`）
- **架構選擇：** 雙重防守——前端 `togglePaymentStatus` 加入 `paid_at: null`（主要修復）；後端 `mapFrontendPayload` 當 `payment_status === 'unpaid'` 時若 `paid_at` 未傳亦清除 `PayDate`（防守層），避免其他呼叫路徑遺漏
- **不需 migration**（欄位已存在且允許 NULL）
- 派發：`[FEATURE]` 前端 toggle + 後端 mapFrontendPayload；`[TEST]` Pest 測試

### Bug B

- **受影響頁面：** `ChatPage.vue`
- **受影響 API：** `GET /api/v1/profiles`（不改 API，只改前端載入錯誤處理）
- **架構選擇：** 純前端 CSS + JS 修復，不動後端；font-size 以 `@media` override 確保不影響桌機 14px 樣式
- **不需 migration**
- 派發：`[FEATURE]` 前端 CSS + loadStaff 錯誤處理；`[DOCS]` CHANGELOG

---

## 9. 資安與存取控制

- **Bug A：** `PUT /api/v1/student-classes/{id}` 受 `auth:sanctum` 保護，目前只有 director/admin 可呼叫（`togglePaymentStatus` 只出現在主任 UI）；`paid_at: null` 為允許值，無新增 PII 暴露
- **Bug B：** `GET /api/v1/profiles` 已透過 `ProfileController` 依 campus 限制可見範圍，本次不更動 API；toast 錯誤訊息不得洩漏 stack trace 或內部錯誤細節
- **稽核 log：** Bug A 清除繳費日期屬帳務異動，建議記錄 `teacher_id / admin_id + student_class_id + action=clear_paid_at + timestamp` `[TODO: 確認是否已有 audit log 機制]`
- **STRIDE 快評：**
  - Tampering：Bug A 修復後 `paid_at: null` 可清除繳費，需確認 role check 防止學生/家長呼叫此 endpoint（目前已有 middleware 保護）
  - Info Disclosure：Bug B toast 需確保只顯示友善訊息，不含原始 exception

---

## 10. QA 驗收標準與測試計畫

### FR-A-001 / A-002 / A-003

| 路徑 | 測試案例 |
|---|---|
| **Happy Path** | 課程有 PayDate，按「未繳費」→ API 200 → 畫面 last_paid_at 清空、tag 變灰 |
| **Happy Path** | 課程原本就是未繳費（Paid=0, PayDate=null），再次按「未繳費」→ 無錯誤、狀態不變 |
| **Edge Case** | `payment_status: 'unpaid'` 但不傳 `paid_at`（如其他呼叫路徑）→ 後端仍清除 PayDate |
| **Edge Case** | 同時傳 `paid_at: null` + `payment_status: 'paid'`（衝突）→ `payment_status` 優先，Paid=1，PayDate=null |
| **Error Case** | API 500 → 前端顯示錯誤 toast，按鈕恢復可點擊，本地狀態不變 |
| **回歸** | `submitCourse` 正常儲存繳費日期不受影響；`confirm-payment` 不受影響；催繳提醒邏輯不受影響（對照 DIRECTOR_PAYMENT_ALERT_RULES.md） |

### FR-B-001 / B-002 / B-003

| 路徑 | 測試案例 |
|---|---|
| **Happy Path** | iOS Safari 手機點擊搜尋框 → viewport 不縮放 → 輸入名字 → 結果即時過濾 |
| **Happy Path** | Android Chrome 同上 |
| **Happy Path** | `loadStaff` 失敗 → toast 顯示 4 秒含「重試」按鈕 |
| **Edge Case** | staffList 為空（無聯絡人）→ 空狀態插圖 + 說明文字，非空白 |
| **Edge Case** | 搜尋字串無結果 → 「找不到符合的人員」+ 清除 CTA |
| **Error Case** | 網路中斷時進入聊天 → skeleton 後顯示 toast，不崩潰 |
| **回歸** | 桌機搜尋框 font-size 仍為 14px（media query 只影響 < 768px） |

**UI/UX 驗收清單：**
- [ ] 未繳費操作後繳費日期欄即時清空，不閃舊值
- [ ] 所有非同步操作有 loading 狀態，無 layout shift
- [ ] toast 成功 2 秒 / 失敗 4 秒，位置右上角，符合規格
- [ ] 聊天 modal 搜尋無結果有插圖 + 說明 + CTA，非空白
- [ ] iOS 點擊搜尋框無 viewport 縮放
- [ ] 觸控目標 ≥ 44px（繳費 tag、聊天列表項）
- [ ] 色彩 / 間距沿用既有 design token，無視覺突兀

---

## 11. 上線與維運

1. 後端修改 `StudentClassController.php`（mapFrontendPayload 防守層）→ `php artisan config:cache`
2. 前端修改 `StudentsList.vue`、`ChatPage.vue` → `cd frontend && npm run deploy`（確保 index.html 與 assets chunk 同步更新）
3. 驗證：curl `PUT /api/v1/student-classes/{id}` 帶 `{ payment_status: 'unpaid' }` 確認 PayDate 清空
4. 驗證：用 iOS Safari 開啟聊天確認 viewport 不縮放
5. **回滾：** git revert 兩個 commit（後端 + 前端各一），重跑 `npm run deploy`；不需 DB migration rollback

---

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 預估工期 | 執行 Agent |
|---|---|---|---|
| P0 | Bug A：togglePaymentStatus 加入 paid_at:null | 0.5h | [FEATURE] |
| P0 | Bug A：後端 mapFrontendPayload 防守層 | 0.5h | [FEATURE] |
| P0 | Bug B：ChatPage .form-input 手機 font-size | 0.5h | [FEATURE] |
| P1 | Bug B：loadStaff 失敗 toast + 重試 | 1h | [FEATURE] |
| P1 | Bug B：staffList 載入中 skeleton loader | 1h | [FEATURE] / UI/UX |
| P2 | 稽核 log（clear_paid_at） | 1h | [FEATURE] |

---

## 13. 風險、假設、開放問題

**風險：**
- 中：同時傳 `paid_at: null` + `payment_status` 衝突邏輯已存在於 mapFrontendPayload，本次後端防守需小心不破壞衝突優先序 → 緩解：寫 Pest 測試覆蓋所有組合
- 低：font-size: 16px 在手機可能使 modal 排版稍寬 → 緩解：搭配 5b 節中 modal max-height: 80vh 限制

**假設：**
- `confirm-payment` endpoint 固定設置今日為 PayDate，本次不更動其反向邏輯
- 老師帳號已有 UserCampus 關聯，ProfileController 回傳的 staffList 範圍問題留待另一 ticket

**開放問題：**
- `[TODO: 需確認]` 清空繳費日期前是否需檢查相關發票或已核准評量存在？若有，是否應阻擋或警示？Owner：PM + CTO
- `[TODO: 需確認]` 是否需要 audit log for clear_paid_at？Owner：CTO + 資安

---

## 14. Definition of Done

- [ ] FR-A-001、A-002、A-003 通過 QA 驗收
- [ ] FR-B-001、B-002、B-003 通過 QA 驗收
- [ ] UI/UX 驗收清單（第 10 節）全部打勾，UI/UX Designer sign-off
- [ ] 資安審查：role check 無旁路、toast 無 stack trace 洩漏
- [ ] `npm run deploy` 完成，index.html 與 assets chunk 一致
- [ ] `docs/CHANGELOG.md` 更新（Bug A + Bug B 各一條）
- [ ] PM sign-off
- [ ] CTO / 工程 Lead sign-off
