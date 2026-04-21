---
name: 代課換時間合併操作
overview: 讓主任在指派代課老師時，可在同一個操作步驟內選填「同時調整上課時間」，後端以單一原子交易完成換師與換時，徹底消除分兩步操作的衝堂盲區與部分失敗風險。
todos:
  - id: be-extend-substitute-api
    content: "[FEATURE] 後端：擴充 POST /api/v1/class-sessions/{id}/substitute，接受選填的新日期與新時段；以新時段執行跨分校與同分校衝堂雙層檢查；在同一 DB 交易內完成 ClassSession 時間更新 + 代課老師寫入 + 家長通知（訊息含換時資訊）；回傳包含新時段欄位"
    status: completed
  - id: be-undo-with-reschedule
    content: "[FEATURE] 後端：Undo API 擴充還原 ClassSession 原始時間（original_date/original_start/original_end 存入通知 Payload）；純代課的 Undo 路徑不受影響（回歸）"
    status: completed
  - id: fe-picker-modal
    content: "[FEATURE] 前端：代課 V2 Picker Modal 新增可展開的「同時調整上課時間」區塊（日期 + 開始時間選單 + 自動計算結束時間）；日期或時間改變時即時重新查詢各老師可用性並更新衝堂標記"
    status: completed
  - id: fe-pages-wiring
    content: "[FEATURE] 前端：SmartCalendar 與課程管理頁的代課提交流程傳遞新時間欄位至 API；Toast 說明文字若有換時則顯示新時段；RecentSubstitutesCard 在 operation_type=substitute_with_reschedule 時顯示「含換時」chip"
    status: completed
  - id: uiux-polish
    content: "[UI/UX 精緻化] 依第 5b 節規格精緻化：換時間區塊展開/收合動畫、結束時間唯讀顯示樣式、日期與時間選錯時的 inline 錯誤訊息措辭、衝堂標記在新時段下的即時刷新 skeleton、Picker 底部送出區新佈局（含換時摘要）"
    status: completed
  - id: test-design
    content: "[TEST] Pest Feature Test：合併代課+換時（成功）、合併代課+換時（新時段衝堂被擋 422）、合併代課+換時（跨分校衝堂被擋 422）、Undo 還原時間、純代課回歸（無 new_date 時原路徑不變）"
    status: completed
  - id: qa-acceptance
    content: QA 驗收：執行第 10 節所有 FR Happy Path / Edge / Error + UI/UX 驗收清單；回歸確認純代課、純調課、代課+調課分兩步三條舊路徑均不受影響
    status: completed
  - id: security-review
    content: 資安確認：新時段欄位的輸入驗證（格式、合理範圍）；衝堂檢查以新時段為準不可被繞過；Undo 的 operator 分校校驗含新時段還原；STRIDE 快評
    status: completed
  - id: code-review
    content: "[REVIEW] Code Review：後端合併交易邊界（回歸現有代課 transaction 不受影響）、前端 Picker 可用性重查觸發時機（避免 race condition）、Undo 還原邏輯"
    status: completed
  - id: docs-update
    content: "[DOCS] 更新 docs/CHANGELOG.md；更新 docs/SUBSTITUTE_UX.md 操作手冊（加入合併換時流程說明）；docs/AI_REGRESSION_LESSONS.md 新增「合併代課+換時後 Undo 必須同時還原 ClassSession 時間」"
    status: completed
  - id: deploy
    content: 部署：npm run deploy + 後端 route/config cache 清除；驗證代課 API 新欄位 health；確認純代課與純調課舊路徑正常
    status: completed
  - id: uiux-signoff
    content: UI/UX Designer sign-off：確認 5b 節所有精緻化項目已實作並符合既有 design token；特別確認換時區塊展開動畫、衝堂即時刷新 skeleton、行動裝置觸控尺寸
    status: completed
  - id: pm-signoff
    content: PM sign-off：確認 DoD 全部打勾；確認 P0（合併換師+換時）、P1（Undo 含時間還原）均完成；回歸三條舊路徑無影響
    status: completed
isProject: false
---

# 代課 + 換時間合併操作 PRD

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 代課換師換時合併操作（Substitute with Reschedule） |
| 版本 / 日期 | v1.0 / 2026-04-18 |
| 狀態 | Draft |
| 目標角色 | 主任（主要操作者）、家長（被通知方） |

---

## 2. 目標與業務背景

### 現在的痛點

主任在處理「老師臨時無法上課、且需要順便調整時間」的情境時，必須分兩步操作：先調課（選新日期/新時段），再換代課老師（開 Picker 重新選）。兩步操作帶來三個問題：

1. **衝堂驗證盲區**：先代課再調課時，代課是用「舊時段」驗的，調到新時段後，該代課老師在新時段有沒有衝堂，系統不會再驗，主任只能自己記住。
2. **部分失敗風險**：兩步操作間若系統或網路出問題，資料落在半完成狀態（已換老師但未換時間，或已換時間但老師仍是原任）。
3. **操作效率低**：主任必須在不同操作流程間切換，記住哪個步驟先、哪個後，認知負擔高，尤其在多堂排程日更明顯。

### 解決後帶來的業務價值

- 主任一次完成換師+換時，且系統以新時段做衝堂驗證，確保代課老師不會在新時間衝課
- 家長收到的通知訊息同時包含「時間調整」與「代課老師」，資訊完整、不需兩次通知
- 操作步驟從 2 次（調課 + 代課）縮減為 1 次

### 成功指標（KPI）

- 「代課+換時」的完成率（送出後成功率）≥ 98%（對齊現有純代課標準）
- 衝堂錯誤率（換時後代課老師其實有課但未被擋）降至 0%
- 主任操作平均時間（從開 Picker 到完成）≤ 45 秒（較目前兩步流程縮短約 40%）

---

## 3. 範圍

### In Scope

- 代課 V2 Picker Modal 內新增「同時調整上課時間」選填區塊（日期 + 開始時間）
- 系統以新時段即時重新計算各老師可用性（跨分校衝堂 + 同分校容量衝堂）
- 後端在單一原子交易內完成：ClassSession 時間更新 + 代課老師寫入 + 家長通知（通知訊息包含時間變更資訊）
- Undo 功能擴充支援還原 ClassSession 原始時間（P1）
- 適用範圍：課程管理頁「單堂檢視」與智慧排課行事曆

### Out of Scope

- 批次代課流程（TeacherLeaveBatchModal）不在此次範圍，維持原樣
- 調課後發家長通知（非代課通知，不在本 PRD 範圍）
- 調課後更改課程主檔（改排課契約）不在此次範圍
- 行事曆右鍵選單的代課入口（維持原路徑）

---

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| PM | AI PM | A |
| CTO / 工程 Lead | 工程 Agent | R |
| UI/UX Designer | UI/UX Agent | R |
| QA | QA Agent | R |
| 資安 | 資安 / Review Agent | C |
| IT / Ops | IT Agent | I |

> **UI/UX Designer 職責**：負責 Picker Modal 的「換時間區塊」視覺精緻化（展開動畫、選單樣式、衝堂標記刷新 skeleton、底部送出區佈局調整），以及行動裝置斷點確認。第 5b 節與第 10 節均含 UI/UX sign-off 項目。

---

## 5. User Stories

> **As a** 主任，**I want** 在換代課老師時，同時選填新上課日期與時間，**so that** 我只需要一步操作，不用擔心分開做時的衝堂盲區或部分失敗。
>
> Acceptance Criteria：
> - [ ] 點開代課 Picker 後，可展開「同時調整上課時間」區塊，填寫新日期與新開始時間
> - [ ] 填入新時間後，Picker 的老師卡片即時更新可用性（衝堂標記以新時段為準）
> - [ ] 送出後，ClassSession 日期/時間與代課老師在同一次成功或失敗，不會出現部分完成
> - [ ] 家長收到的站內通知包含新時間與代課老師姓名
> - [ ] 成功後顯示 ToastWithUndo，說明文字顯示新時段與代課老師名稱

> **As a** 主任，**I want** 若代課老師在新時段有衝堂，系統要擋住並明確說明，**so that** 我不會指派一個在新時間其實有課的老師。
>
> Acceptance Criteria：
> - [ ] 代課老師在新時段有同分校衝堂時，送出被擋（409）且 Picker 顯示 inline 錯誤
> - [ ] 代課老師在新時段有跨分校衝堂時，送出被擋（422）且 Picker 顯示 inline 錯誤
> - [ ] 衝堂錯誤訊息說明「此老師於新時段有課程衝突」，不揭露其他分校的學生資料

> **As a** 主任，**I want** 合併操作送出後可以在倒數時間內按 Undo 撤銷，**so that** 如果臨時改變主意，可以完整還原（包含時間與老師）。
>
> Acceptance Criteria：
> - [ ] Undo 成功後，ClassSession 日期/時間還原至操作前
> - [ ] Undo 成功後，代課老師還原至操作前，家長通知標記為已作廢
> - [ ] 不使用「換時間」功能的純代課操作，Undo 路徑與現行行為完全一致（回歸）

---

## 5b. UI/UX 精緻化需求（前端必填）

### SubstituteTeacherPickerModal — 「同時調整上課時間」區塊

| 面向 | 要求描述 |
|---|---|
| **版面層次** | 換時間區塊置於老師清單下方、原因欄位上方，使用可展開的 disclosure 元件（展開前顯示「+ 同時調整上課時間（選填）」，收合時若已選則顯示已選時間摘要）；標題字重 600、字色 `#334155`，與 Picker 其他 section 標題一致；區塊內容以 `#f8fafc` 背景 + `#e2e8f0` border 的圓角卡片呈現 |
| **色彩一致性** | 日期/時間欄位 focus 狀態沿用 `--primary` blue ring（與 Modal 其他 input 一致）；結束時間欄位為唯讀，以 `#64748b` 字色與淡灰底（`#f1f5f9`）表示非可輸入，標注「依原課時長自動計算」字樣 |
| **互動回饋** | 展開/收合使用 CSS `transition: max-height 0.2s ease`，避免突兀跳動；填入新日期或開始時間後，老師卡片進入重查狀態：可用性徽章位置顯示 pulse skeleton（矩形，與徽章同尺寸），不影響卡片整體高度；重查完成後 skeleton 消失，衝堂標記以最新顏色呈現 |
| **空狀態設計** | 日期欄位空白或時間選單未選時，送出按鈕保持 disabled 且 tooltip 提示「請同時填寫日期與開始時間，或收合換時間區塊」；不允許只填日期不填時間（或反過來）的半完成狀態，inline 錯誤出現在區塊頂部 |
| **載入狀態** | 重查老師可用性時，老師清單上方出現細進度條（linear progress bar，height 2px，primary 色），避免整個清單 layout shift；skeleton 僅覆蓋「可用性徽章」區域 |
| **防呆設計** | 新日期的 date input 設定 `min` 為今日（不可選過去）；結束時間唯讀；送出時若 new_date 與 new_start_time 只填一個，inline 錯誤措辭：「請同時填寫新日期與新開始時間」（正向引導，非「輸入錯誤」）；合併換時模式的送出按鈕文字改為「確認代課 + 換時」 |
| **響應式 / 行動裝置** | 換時間區塊在 ≤ 480px 時，日期與開始時間欄位各佔全寬（垂直排列）；觸控目標 ≥ 44px；展開箭頭點擊區域 ≥ 44px |

### Picker 底部送出區

| 面向 | 要求描述 |
|---|---|
| **換時摘要行** | 若已填新日期+新時間，送出按鈕上方顯示灰色摘要行（`#64748b`，字型 13px）：「將調整至 {new_date} {new_start_time}，由 {老師名} 代課」；未填時不顯示此行，底部高度不變（預留最小高度避免 layout shift） |
| **按鈕文字與狀態** | 未選老師時 disabled（灰底）；選了老師但填了一半換時欄位時 disabled；所有條件滿足且有換時時，按鈕文字：「確認代課 + 換時」；純代課（無換時）維持「確認代課」 |

---

## 6. 功能需求（FR）

- **FR-001**：代課 V2 Picker Modal 提供「同時調整上課時間」選填區塊，包含新日期（date picker）與新開始時間（30-min 時段選單）；結束時間由系統根據原課時長自動推算並唯讀顯示。
- **FR-002**：填入新日期或新開始時間後，Picker 必須以新時段重新查詢所有候選老師的可用性，並即時更新衝堂標記（衝堂老師禁點）；重查期間顯示 skeleton。
- **FR-003**：新日期與新開始時間必須同時填寫，才能啟用換時功能；只填其中一個時系統阻止送出並顯示 inline 錯誤。
- **FR-004**：送出後，後端以新時段（若有）為準執行跨分校衝堂檢查（422）與同分校容量衝堂檢查（409）；無新時段時維持現有以原時段為準的衝堂邏輯（回歸）。
- **FR-005**：後端在單一 DB 交易內完成以下操作（全部成功或全部回滾）：ClassSession 日期/時間更新、排課代課列寫入（anchor + substitute）、學習評量時間與授課老師同步、家長站內通知建立。
- **FR-006**：家長通知在有換時時，Title 改為「課程異動通知」，Body 格式為「原定 {old_date} {old_start}~{old_end} 的課程已調整至 {new_date} {new_start}~{new_end}，由 {new_teacher_name} 代課。」；純代課（無換時）維持現行 Title「代課通知」+ 現行 Body 格式（回歸）。
- **FR-007**：成功後顯示 ToastWithUndo，說明文字在有換時時顯示新時段（格式：`{學生姓名} · {new_date} {new_start_time}`）。
- **FR-008（P1）**：Undo 操作在有換時的情況下，必須同時還原 ClassSession 的原始日期與時間，並作廢家長通知；原始時間需在代課交易成功時存入通知的 Payload 供 Undo 取用。
- **FR-009**：不填換時間（純代課）的操作路徑，行為與現行系統完全一致，不得因本次功能引入回歸。

---

## 7. 非功能需求（NFR）

- **效能**：含換時的代課 API 回應時間 < 800ms（較純代課多一個 ClassSession 更新步驟，允許寬鬆 300ms）；Picker 的老師可用性重查（多老師並行查詢）< 2 秒
- **原子性**：後端使用單一 DB transaction，任一步驟失敗整體回滾，不允許部分完成
- **降級策略**：若 new_date / new_start_time 欄位缺失或格式錯誤，後端回 422 並帶明確錯誤欄位；前端 Picker 顯示 inline 錯誤，不關閉 Modal（讓主任可以修正後重送）
- **向後相容**：現有純代課 API 路徑（無新時間欄位）的行為不得改變

---

## 8. 技術方向（給 CTO，非實作細節）

### 受影響的頁面

- 代課 V2 Picker Modal 元件（`frontend/src/components/substitute/` 資料夾）
- 智慧排課頁（`frontend/src/pages/SmartCalendar.vue`）
- 課程管理頁（`frontend/src/pages/CourseManagement.vue`）

### 受影響的 API

- `POST /api/v1/class-sessions/{id}/substitute` — 擴充選填換時參數，加入合併寫入路徑
- `POST /api/v1/class-sessions/{id}/substitute/undo` — P1：擴充還原 ClassSession 時間邏輯

### 受影響的資料表

- `class_sessions` — 合併路徑新增時間欄位（SessionDate、StartTime、EndTime）的寫入
- `schedules` — 代課 anchor 列與 substitute 列需以新時段為準寫入 start_time / end_time
- `learning_records` — 時間欄位（SessionDate、StartTime、EndTime）需與 ClassSession 同步
- `notifications` — Payload 欄位新增原始時間資訊（供 Undo 還原用）

### 架構選擇取捨

**選擇在現有代課 API 加入選填換時參數，而非新增獨立 API**，理由：

- 避免前端需要協調兩個 API 的呼叫順序（若分兩個 API，順序錯誤即產生舊問題）
- 原子性由單一 transaction 保證，不需跨 API 的補償機制
- 衝堂檢查在同一個 request context 內以新時段為準執行，無法被繞過
- 純代課的舊路徑（無新時間欄位）完全不受影響，降低回歸風險

**不需要 schema migration**：新欄位寫入的均為現有資料表的現有欄位，不新增資料表或欄位。

### 子任務 Agent 派發

- `[FEATURE]` → 後端 API 擴充（合併交易路徑）、前端 Modal 元件更新、頁面串接
- `[TEST]` → Pest Feature Test（合併路徑 + 純代課回歸）、QA 手動測試案例
- `[REVIEW]` → 交易邊界、衝堂邏輯、Undo 還原完整性
- `[DOCS]` → CHANGELOG、操作手冊更新、AI_REGRESSION_LESSONS 新增條目

---

## 9. 資安與存取控制

- **role 限制**：與現行代課 API 一致，限 `director` / `super_admin` / `admin`；`teacher` 不可呼叫
- **分校隔離**：新時段的衝堂檢查必須限定在 operator 的 managed_campus_ids 範圍內；跨分校衝堂阻擋邏輯（FR-004）不得被新參數繞過
- **輸入驗證**：new_date 需為合法日期格式（不可為過去日期）；new_start_time / new_end_time 需為 HH:MM 格式；格式錯誤一律回 422
- **PII**：新時段參數為日期/時間，不含學生個人資料；衝堂錯誤訊息只揭露「該老師在某分校有課」，不揭露其他分校的學生/科目細節（延續現有資訊揭露規則）
- **稽核 log**：合併換時操作需在現有 substitute 稽核 log 基礎上新增 rescheduled_to_date / rescheduled_to_start_time 欄位
- **Undo 校驗**：Undo 時需驗證 operator 分校權限（現有邏輯）+ 時間窗（現有邏輯），含時間還原的 Undo 不可放寬這兩項檢查
- **STRIDE 快評**：
  - Tampering：new_date / new_start_time 需格式驗證，避免注入
  - Info Disclosure：衝堂錯誤訊息不揭露其他分校學生/科目細節（延續現有設計）
  - 無新增 Spoofing / Repudiation / DoS / Elevation 風險

---

## 10. QA 驗收標準與測試計畫

### FR-001 / FR-002（Picker 換時區塊 + 即時可用性）

- Happy Path：展開換時區塊，填日期與時間，結束時間自動顯示，老師可用性以新時段重查，衝堂老師禁點
- Edge：填了新日期但未填新時間 → inline 錯誤，送出被阻擋
- Edge：新日期與原日期相同，但新時段不同 → 可用性以新時段為準重查並更新衝堂標記

### FR-004（後端衝堂檢查以新時段為準）

- Happy Path：代課老師在新時段無衝堂 → 200 成功
- Error：代課老師在新時段有同分校衝堂 → 409 + Picker inline 錯誤
- Error：代課老師在新時段有跨分校衝堂 → 422 + Picker inline 錯誤
- 回歸：不填換時欄位的純代課請求 → 衝堂邏輯與現行一致

### FR-005（原子交易）

- Happy Path：換時 + 換師全部寫入成功
- Error：ClassSession 時間更新失敗 → 整體回滾，schedules 不留任何代課列
- Error：家長通知建立失敗 → 整體回滾，ClassSession 時間不被更新

### FR-008（Undo + 時間還原）

- Happy Path：合併換時+換師 → Undo → ClassSession 時間還原、老師還原、通知作廢
- Edge：Undo 超過時間窗 → 410，ClassSession 維持換時後的狀態
- 回歸：純代課 Undo → 行為與現行一致（只還原老師，不嘗試還原時間）

### 回歸測試（對照 AI_REGRESSION_LESSONS.md）

- 代課後調課（兩步舊路徑）：ClassSession 時間與代課老師顯示正確，無重複排課列（對應 PRD 3972a088 修正項）
- 純調課：調課 API 不因本次改動受影響
- 純代課（無換時）：送出路徑行為與現行完全一致

### UI/UX 驗收清單

- [ ] 換時間區塊展開/收合有平滑動畫（max-height transition），無 layout shift
- [ ] 可用性重查期間有 skeleton（pulse 動畫），老師卡片不閃爍，整體高度不跳動
- [ ] 結束時間唯讀樣式與可輸入欄位視覺明顯區分（字色 + 背景色）
- [ ] 只填日期或只填時間時，inline 錯誤措辭正向引導（非純錯誤描述）
- [ ] 送出按鈕在換時模式下文字改為「確認代課 + 換時」，disabled 狀態正確
- [ ] 底部換時摘要行在有填時間時出現，未填時不出現（預留最小高度無 layout jump）
- [ ] 行動裝置（≤ 480px）日期/時間欄位垂直排列，觸控目標 ≥ 44px
- [ ] 成功 Toast 說明文字顯示新時段（有換時時）

---

## 11. 上線與維運

### 部署步驟

1. 後端：`php artisan route:clear && php artisan config:clear`（不需 migration，無 schema 變更）
2. 前端：`cd frontend && npm run deploy`（build + copy to backend/public）
3. 驗證：呼叫 `POST /api/v1/class-sessions/{id}/substitute`（含 new_date + new_start_time）確認 200 回應含新時段欄位；呼叫純代課版本確認回歸正常

### 監控

- 現有 substitute 稽核 log 自動含 rescheduled_to_date / rescheduled_to_start_time 欄位，監控項目不需額外新增

### 回滾方案

- 後端：Git revert 後重新部署（無 migration，schema 不受影響，可安全回滾）
- 前端：`npm run deploy` 舊版 branch 即還原
- 資料補救：若測試資料誤寫，使用 Undo API 回滾（時間窗內）；超時間窗的需手動 DB 修正

---

## 12. 里程碑與優先級

| 優先級 | 功能項目 | 預估工期 | 執行 Agent |
|---|---|---|---|
| P0（Must Have） | 後端合併 API + 前端 Picker 換時區塊 + 衝堂驗證 + 家長通知含時間 | 1 天 | `[FEATURE]` |
| P0（Must Have） | 純代課路徑回歸驗證（Pest + QA 手動） | 含在 P0 中 | `[TEST]` |
| P1（Should Have） | Undo 含時間還原（Payload 存原始時間 + Undo API 擴充） | 半天 | `[FEATURE]` + `[TEST]` |
| P1（Should Have） | 儀表板近 7 天代課記錄顯示「含換時」chip（後端回傳 operation_type） | 半天 | `[FEATURE]` |
| P2（Nice to Have） | 換時成功後課程管理列表即時更新時段顯示（不等整頁重載） | 後續 | `[FEATURE]` |

---

## 13. 風險、假設、開放問題

### 風險

| 風險 | 等級 | 緩解方案 |
|---|---|---|
| 合併交易內 ClassSession 更新與排課代課列的時段對齊出現衝突（anchor / substitute 列應以新時段寫入） | 中 | Code Review 明確確認排課代課列以新時段（非 ClassSession 原始值）為準寫入；Pest Test 驗證 DB 狀態 |
| 前端 Picker 可用性重查與送出操作的 race condition（使用者快速填時間後立刻按送出） | 低 | 送出前確認重查完成（送出按鈕在重查期間保持 disabled）；後端衝堂檢查作為最終防線 |
| Undo 還原時間與 Notification Payload 欄位不一致（舊通知無 original_date） | 低 | Undo 時先檢查 Payload 是否有 original_date；無則僅還原老師（降級行為），不嘗試還原時間並在 Undo response 中提示 |

### 假設

- 原課時長可由目前 context 中的 start_time 與 end_time 差值計算，不需額外 API 欄位
- 新日期的 ClassSession 若已存在（例如加課日），合併代課以現有 ClassSession 為操作對象；若不存在則先建立（對齊現有 reschedule-session API 的行為）

### 開放問題

> 以下兩項已參照業界慣例決議，不再是待確認項。

**Q1：家長通知訊息措辭**

參照業界做法（ClassDojo 課程異動通知、Seesaw 家長訊息、台灣補教平台 LINE 推播）：

複合異動（時間 + 代課師）以**單一通知**涵蓋全部變更，不分兩次發送。現有 Body 格式為：

```
{date} {start_time}~{end_time} 原老師 {old_teacher_name} 由 {new_teacher_name} 代課。
```

合併換時時，改為下列格式（**決議定稿，工程直接實作**）：

- **Title**：`{student_name} {subject} 課程異動通知`（從「代課通知」改為「課程異動通知」，涵蓋時間 + 代課師兩種異動）
- **Body**：`原定 {old_date} {old_start}~{old_end} 的課程已調整至 {new_date} {new_start}~{new_end}，由 {new_teacher_name} 代課。`
- 純代課（無換時）：維持現行 Title「代課通知」+ 現行 Body 格式，**不受影響**

---

**Q2：主任儀表板「近 7 天代課記錄」是否需標記**

參照業界做法（Google Calendar 複合操作顯示「已修改」標記、Salesforce 活動 feed 以 chip 區分操作類型、GitHub commit 以 tag 顯示 amend / force-push）：**複合操作以視覺 chip 標記，不增加獨立列**。

**決議**：

- 儀表板代課卡片新增 `operation_type` 欄位，後端 `GET /api/v1/substitutes/recent` 回傳 `operation_type: "substitute"` 或 `"substitute_with_reschedule"`
- 前端 `RecentSubstitutesCard` 在 `operation_type === "substitute_with_reschedule"` 時，顯示灰色 chip「含換時」（`background: #f1f5f9`、`color: #475569`、`font-size: 11px`），置於代課老師姓名右側
- 不增加新的列或新的卡片，保持一堂一列的清晰度
- 此項為 **P1**（Should Have），與 Undo 含時間還原同期實作

---

## 14. Definition of Done

- [ ] 所有 FR（FR-001 ~ FR-009）通過 QA 驗收
- [ ] **UI/UX 驗收清單（第 10 節）全部打勾，UI/UX Designer sign-off**
- [ ] 資安審查無阻擋項（衝堂繞過風險、Undo 校驗、log 欄位完整性）
- [ ] 純代課舊路徑回歸測試通過（Pest + QA 手動）
- [ ] `npm run deploy` 且 API health 正常
- [ ] `docs/CHANGELOG.md` 更新、`docs/SUBSTITUTE_UX.md` 更新、`docs/AI_REGRESSION_LESSONS.md` 新增條目
- [ ] PM sign-off
- [ ] CTO / 工程 Lead sign-off
