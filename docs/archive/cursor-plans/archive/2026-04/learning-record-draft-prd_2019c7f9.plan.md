---
name: learning-record-draft-prd
overview: 規劃老師在學習評量表填寫途中離開後，可於同裝置返回續填的本機草稿方案，包含同筆自動續填與草稿清單入口。PRD 將涵蓋產品範圍、UI/UX、風險、驗收、部署與跨部門 todo。
todos:
  - id: feature-backend-compat
    content: "[FEATURE] 本次不適用，原因：MVP 採本機草稿，不新增後端 API / migration，但需確認與 `LearningRecordController` 正式送出契約完全相容。"
    status: completed
  - id: feature-frontend-ui
    content: "[FEATURE] 在 `frontend/src/pages/LearningRecordsPage.vue` 實作老師評量草稿暫存、同筆自動續填、草稿清單入口、草稿狀態提示與送出後清除。"
    status: completed
  - id: feature-uiux-polish
    content: "[FEATURE/UI-UX] 依 PRD 第 5b 節完成草稿狀態列、空狀態、loading、toast、清除確認、mobile 觸控與視覺層次精緻化。"
    status: completed
  - id: test-design
    content: "[TEST] 設計並執行草稿續填的手動測試案例，覆蓋同筆返回、多筆草稿、儲存失敗、草稿失效、送出後清除與回歸驗收。"
    status: completed
  - id: qa-validation
    content: "[QA] 依 PRD 第 10 節執行 Happy Path / Edge / Error 與 UI/UX 驗收清單，確認不影響老師課表卡片、待審佇列與正式送出流程。"
    status: completed
  - id: security-review
    content: "[REVIEW/資安] 確認本機草稿內容最小化、登出/共用裝置風險、失效草稿處理與本機資料不可作為可信來源。"
    status: completed
  - id: code-review
    content: "[REVIEW] 審查草稿 key、版本與過期策略，以及 `LearningRecordsPage` 新邏輯是否引入既有評量頁 regression。"
    status: completed
  - id: docs-update
    content: "[DOCS] 更新 `docs/CHANGELOG.md`，並補充老師操作說明：如何辨識草稿已保存、如何找回與清除未完成評量。"
    status: completed
  - id: deploy-release
    content: "[Ops] 前端變更完成後執行 `cd /home/admin/frontend && npm run deploy`，確認 `index.html` 與 assets 同步更新且評量頁正常。"
    status: completed
  - id: uiux-signoff
    content: "[UI/UX Designer] 針對第 5b 節精緻化項目完成驗收與 sign-off。"
    status: completed
  - id: pm-signoff
    content: "[PM] 確認範圍、風險、驗收、開放問題與 DoD 全部完成後 sign-off。"
    status: completed
isProject: false
---

# 老師評量表草稿續填 PRD

## 1. 文件資訊
- 功能名稱：老師評量表草稿暫存與續填
- 版本 / 日期：v1.0 / 2026-04-16
- 狀態：Draft
- 目標角色：老師為主要使用者；主任與 QA 為間接受影響角色

## 2. 目標與業務背景
- 痛點：老師在 `LearningRecordsPage` 填寫某位學生的學習評量時，若臨時切去填另一位學生、切換分頁、關閉 modal，未送出的內容會遺失，造成重工與挫折。
- 業務價值：降低老師填寫評量的中斷成本，提升評量完成率與填寫意願，減少現場抱怨與重複輸入。
- 成功指標：
- 老師於同裝置離開後返回同筆評量時，90% 以上情境可恢復未送出內容。
- 老師評量頁因誤關閉或切換導致重填的回報量在上線後兩週下降。
- 未送出草稿不影響正式 `LearningRecord` 審核、扣堂、待審列表與主任檢視結果。

## 3. 範圍
- In Scope：
- 在 `frontend/src/pages/LearningRecordsPage.vue` 提供本機草稿暫存能力。
- 同一台裝置、同一瀏覽器下，老師離開同筆評量後再次返回可恢復草稿。
- 提供「草稿清單 / 未完成評量」入口，讓老師能找回多筆尚未送出的內容。
- 顯示草稿狀態、最近儲存時間、清除草稿與送出後清空草稿的規則。
- 定義本機草稿 key、版本、過期與資料衝突策略。
- Out of Scope：
- 跨裝置、跨瀏覽器同步草稿。
- 後端新增草稿 API 或資料表。
- 主任可查看老師未送出草稿。
- 自動將草稿轉成正式送出或批次送出多筆草稿。

## 4. RACI
- PM：A，定義需求範圍、驗收與優先級。
- CTO / 工程：R，決定前端暫存架構、資料鍵設計與回歸控制。
- UI/UX Designer：R，負責草稿提示、草稿清單、空狀態、toast、互動層次與 mobile 呈現品質。
- QA：R，執行 Happy Path、Edge、Error 與 UI/UX 驗收。
- 資安：C，確認本機暫存內容不超出必要範圍，並評估裝置共用風險與登出處理。
- IT / Ops：I，知悉前端 deploy 與回滾方案。

## 5. User Stories
- As a 老師, I want 在填寫評量表途中切去別位學生後仍能回來續填, so that 我不用重打剛剛已填的內容。
- Acceptance Criteria：
- 使用者從同筆堂次重新開啟評量表時，若有未送出草稿，系統應自動回填欄位內容。
- 草稿內容不得直接覆蓋已正式送出的 `LearningRecord` 資料。
- As a 老師, I want 看見我有哪些未完成草稿, so that 我能快速找回中斷中的評量。
- Acceptance Criteria：
- 系統應提供草稿清單入口，列出至少學生、科目、日期時段、最近儲存時間。
- 點擊草稿項目後應能開啟對應評量表並載入草稿。
- As a 老師, I want 明確知道草稿是否已保存, so that 我能放心切換去做別的事情。
- Acceptance Criteria：
- 系統應在適當時機顯示儲存中、已儲存、儲存失敗或草稿已清除等回饋。
- 送出成功後，對應草稿應被移除，不得殘留造成下次誤載入。

## 5b. UI/UX 精緻化需求
- 受影響頁面：`frontend/src/pages/LearningRecordsPage.vue`
- 可能受影響的共用樣式：`frontend/src/styles.css`
- 版面層次：
- 在評量編輯 modal 或表單區顯示清楚的草稿狀態列，優先級低於學生/課程資訊、高於欄位輸入區。
- 草稿清單入口應放在老師常用操作區，例如課表區塊或表單工具列附近，避免藏在深層選單。
- 色彩一致性：
- 草稿狀態用中性或資訊色，不可誤用成功色讓使用者誤認已正式送出。
- 清除草稿屬危險操作，應用警示色並要求確認。
- 互動回饋：
- 自動暫存時顯示輕量的「已於 HH:MM 儲存草稿」提示，不應頻繁彈出干擾型 toast。
- 手動清除草稿、送出成功、載入草稿失敗時，應給明確 toast 或 inline 提示。
- 空狀態設計：
- 草稿清單無資料時，需顯示空狀態圖示、說明文字與 CTA，例如「從課表點一筆課程開始填寫評量」。
- 載入狀態：
- 開啟草稿清單或載入草稿時需有 loading 樣式，避免版面跳動。
- 防呆設計：
- 當使用者正編輯中且嘗試關閉表單，如有未儲存到本機的最新變更，應先完成節流後保存，再允許關閉。
- 若找到草稿但對應正式評量已送出或狀態已不允許編輯，應提示「此草稿已失效」並提供清除選項。
- 響應式 / 行動裝置：
- 老師常在平板或手機使用，草稿入口與清單項目點擊區需符合最小觸控面積。
- 草稿狀態列與操作按鈕不可被底部導覽遮擋。

## 6. 功能需求（FR）
- FR-001：系統應在老師編輯學習評量表時，於同裝置本機保存未送出的草稿資料。
- FR-002：系統應以「老師 + 堂次 / 記錄識別」建立唯一草稿 key，避免不同學生或不同堂次互相覆蓋。
- FR-003：老師重新開啟同一筆評量表時，系統應自動偵測並載入對應草稿。
- FR-004：系統應提供草稿清單入口，列出所有未完成草稿，並可開啟續填。
- FR-005：系統應顯示最近儲存時間與草稿狀態，讓老師知道內容未遺失。
- FR-006：正式送出評量成功後，系統應自動刪除該筆本機草稿。
- FR-007：系統應允許老師手動清除單筆草稿，且危險操作需二次確認。
- FR-008：若草稿對應的堂次或評量狀態已不可編輯，系統應阻止套用過期草稿並提示原因。
- FR-009：系統應設定草稿版本與過期策略，以避免未來欄位變更後載入錯誤資料。

## 7. 非功能需求（NFR）
- 草稿自動保存不應造成明顯卡頓；單次保存與讀取應為前端本機操作，體感在 100ms 內完成。
- 自動保存應採節流策略，避免每次按鍵都大量寫入 localStorage。
- 若本機儲存失敗，例如容量不足或瀏覽器限制，系統應降級為提示老師本次無法保存草稿，但不得影響正式送出功能。
- 新功能不得改變既有 `/api/v1/learning-records` 正式 API 契約。

## 8. 技術方向
- 受影響頁面：`frontend/src/pages/LearningRecordsPage.vue`
- 可能需要抽出的前端共用邏輯：`frontend/src/lib` 或 `frontend/src/composables` 下的 learning record draft helper
- 參考後端模組但本次不修改 API 契約：`backend/app/Http/Controllers/LearningRecordController.php`
- 參考資料表：`LearningRecord`、`ClassSession`、`StudentClass`。本次不新增 migration。
- 架構選擇：
- 採本機草稿 MVP，可最快解決老師「離開再回來內容不見」的核心痛點，且不影響後端資料結構與權限模型。
- 以既有評量識別資料建立草稿鍵，避免僅用學生名或科目等不穩定欄位。
- 草稿清單由前端本機索引生成，不新增後端查詢成本。
- 與既有正式送出流程解耦，確保 `LearningRecord` 的 pending / approved / changes_requested 流程不被未送出草稿污染。
- 涉及檔案建議：
- `frontend/src/pages/LearningRecordsPage.vue`
- `frontend/src/styles.css`
- 視實作需要新增一個草稿管理模組，例如 `frontend/src/lib/learningRecordDrafts.js`
- Agent 派發：
- `[FEATURE]`：前端草稿暫存、草稿清單、UI 狀態與送出後清理
- `[TEST]`：Pest 不需新增後端 API 測試；以前端手動測試案例與必要的 unit 測試策略為主
- `[REVIEW]`：檢查草稿 key 設計、狀態失效條件、與既有評量流程的回歸風險
- `[DOCS]`：更新 `docs/CHANGELOG.md` 與老師操作說明

## 9. 資安與存取控制
- 存取角色：僅老師在自己的瀏覽器使用草稿功能；主任不應看到未送出草稿內容。
- PII / 敏感資料：草稿可能包含學生姓名、學習表現、評語等教育紀錄，屬敏感資訊。
- 風險控制：
- 本次僅在本機保存必要欄位，不保存多餘身分資料。
- 登出時建議評估是否清除當前使用者名下草稿，避免共用裝置下被下一位老師看到。
- 稽核 log：本機草稿不屬正式資料，不納入後端稽核；正式送出仍沿用現有後端紀錄。
- STRIDE 快評：
- Spoofing：低，沿用現有登入態。
- Tampering：中，本機資料可被使用者修改，因此草稿只能作為 UI 輔助，不可作為後端可信來源。
- Repudiation：低，正式送出仍以既有 API 為準。
- Information Disclosure：中，共用裝置可能暴露未送出內容，需在 PRD 中要求清除策略與 UI 提示。
- Denial of Service：低，僅 localStorage 限制需處理失敗提示。
- Elevation of Privilege：低，不新增後端權限面。

## 10. QA 驗收標準與測試計畫
- 對應 FR-001 ~ FR-003：
- Happy Path：老師開啟某筆評量，輸入內容，關閉後重新開啟同筆，內容完整恢復。
- Edge Case：同一位老師同時建立多筆不同堂次草稿，各自不互相覆蓋。
- Error Case：localStorage 不可寫時，系統提示無法保存草稿，但仍可正常送出正式評量。
- 對應 FR-004：
- Happy Path：草稿清單正確列出多筆草稿並可點擊開啟。
- Edge Case：草稿對應堂次已被正式送出或不可編輯時，清單項目顯示失效狀態或進入後提示不可套用。
- Error Case：草稿資料格式版本不符時，不載入舊草稿並引導清除。
- 對應 FR-005 ~ FR-007：
- Happy Path：畫面能顯示最近暫存時間；送出成功後自動移除草稿；手動清除後清單同步消失。
- Edge Case：送出失敗時草稿不應被刪除。
- Error Case：清除草稿操作中斷時，不得誤清其他筆草稿。
- 對應 FR-008 ~ FR-009：
- Happy Path：狀態變更為不可編輯時，系統阻止載入並解釋原因。
- Edge Case：欄位版本升級後，舊草稿能被安全忽略或只載入相容欄位。
- 回歸測試：
- 不得破壞 `LearningRecordsPage` 既有課表卡片去重邏輯。
- 不得影響 pending / approved / changes_requested 顯示與主任審核流程。
- 不得讓請假、調課、作廢/恢復評量的既有判斷被草稿 UI 混淆。
- UI/UX 驗收清單：
- 空狀態有圖示、說明與 CTA，不可只顯示空白。
- 非同步操作有 loading 或狀態提示，無明顯 layout shift。
- 成功 / 失敗 / 清除 / 失效均有明確回饋。
- 表單必填與草稿提示不互相遮擋，措辭清楚。
- 危險操作如清除草稿具二次確認。
- 手機與平板上無水平 overflow，按鈕可正常點擊。

## 11. 上線與維運
- 部署步驟：
- 完成前端功能與驗收後，執行前端 build 與 deploy 流程，確保 `index.html` 與 hashed assets 同步更新。
- 本次無 migration，部署順序以前端為主。
- 監控項目：
- 上線後收集老師對「草稿未恢復」或「誤載入舊草稿」的回報。
- 留意瀏覽器儲存限制與共用裝置情境。
- 回滾方案：
- 若草稿功能造成評量頁誤行為，可先移除草稿載入入口與自動保存初始化，回退到純正式送出流程。

## 12. 里程碑與優先級
- P0（Must Have）：
- 同筆評量本機草稿自動保存與返回續填。
- 送出成功後清除草稿。
- 基本草稿狀態提示與失敗降級處理。
- P1（Should Have）：
- 草稿清單入口與多筆草稿管理。
- 草稿過期 / 失效提示與手動清除流程。
- P2（Nice to Have）：
- 登出時草稿清理策略優化。
- 草稿數量上限、排序與搜尋體驗優化。

## 13. 風險、假設、開放問題

### Risk Register

風險等級定義：機率（H/M/L） × 影響（H/M/L）→ 等級（Critical / High / Medium / Low）

**R-001：元件複雜度失控**
- 描述：`LearningRecordsPage.vue` 已整合課表 widget、待審佇列、補登 modal、匯出等五項以上獨立邏輯；草稿功能若直接嵌入，將使單一元件超過 500 個響應式變數與 lifecycle hook，後續任何修改都需要完整回歸。
- 機率：高 / 影響：高 → **Critical**
- 緩解策略：草稿邏輯必須抽離為獨立模組 `learningRecordDrafts.js` 並透過 composable 注入，嚴禁直接在 LearningRecordsPage 內展開 inline 邏輯。Code Review 階段設立「元件行數與響應式變數數量」門檻，超出需拆分。
- 應變計畫：若 Review 發現草稿邏輯已污染元件，立即抽到獨立 composable 再合入，不允許帶污染邏輯上線。
- 責任人：CTO / 工程 Lead

**R-002：草稿 Key 碰撞導致欄位串錯**
- 描述：若草稿 key 僅用 `classSessionId` 而未納入 `teacherId`，A 老師填的草稿在同裝置被 B 老師開啟相同堂次時可能載入，造成內容錯誤寫入。
- 機率：中 / 影響：高 → **High**
- 緩解策略：草稿 key 格式明確定為 `lr_draft_v{VERSION}_{teacherId}_{classSessionId}`；若 `classSessionId` 不存在（新建模式），改用 `{teacherId}_{studentClassId}_{sessionDate}` 組合。Key 格式需在 [REVIEW] 階段逐行審查。
- 應變計畫：若上線後發現碰撞，立即在前端 patch key 格式並清除全域舊 key，不影響後端資料。
- 責任人：工程 + Review 審查者

**R-003：過期草稿覆蓋已核准的正式評量**
- 描述：老師先送出評量被主任核准後，若本機草稿未清除，再次開啟同筆堂次時草稿被載入並回填，使已核准的呈現被舊草稿資料視覺汙染（即使後端資料正確，也會造成老師誤解）。
- 機率：中 / 影響：高 → **High**
- 緩解策略：FR-006 要求正式送出成功後立即刪除草稿。FR-008 要求載入草稿前先比對評量狀態，凡 `approved` / `rejected` / `changes_requested` 的既有評量，一律不套用草稿並提示失效。
- 應變計畫：若發現草稿被誤套用，展示 banner 告知老師「目前顯示的是草稿，已送出版本以提交記錄為準」，並提供「清除草稿、檢視已送出內容」的 CTA。
- 責任人：工程 + QA

**R-004：共用裝置造成學生資料外洩**
- 描述：補習班常以共用平板或桌機給多位老師輪流使用。A 老師登出後，B 老師登入前若開啟相同頁面，可能看到含學生姓名、評語的 A 老師草稿。
- 機率：高 / 影響：中 → **High**（含隱私合規風險）
- 緩解策略：登出時必須以 `teacherId` 為 prefix 掃描並清除該老師所有草稿 key（`localStorage.removeItem` 逐筆或 `clear()` 搭配重建非草稿 key）。登出前若偵測到未完成草稿，以 modal 提示「您有 N 筆未完成評量，登出後無法從此裝置繼續填寫，確認登出？」，讓老師決策。
- 應變計畫：若已上線未做登出清除，緊急 patch 在 `App.vue` logout handler 加入清除邏輯，不需重新 deploy 後端。
- 責任人：工程 + 資安審查者

**R-005：localStorage 容量耗盡或被瀏覽器清除**
- 描述：現代瀏覽器每個 origin 約 5–10 MB localStorage 限制；多位老師或長時間使用時，草稿存量可能觸頂導致 `QuotaExceededError`。
- 機率：低 / 影響：低 → **Low**
- 緩解策略：草稿 key 只儲存表單欄位值，不含圖片 URL 等大型資料；設定草稿數量上限（P2），超限時提示老師清理舊草稿；儲存時包 try/catch，寫入失敗不影響正式送出。
- 應變計畫：若用戶回報無法儲存，提示清除舊草稿；不影響主流程。
- 責任人：工程

**R-006：前端 index.html 與 hashed assets 不同步（部署風險）**
- 描述：只更新部分 assets 而未完整執行 `npm run deploy` 時，瀏覽器會以 `text/html` 載入 `index-*.js`，導致整站無法啟動，與本功能無直接關係但在每次部署都須管控。
- 機率：低（需人為操作失誤） / 影響：高 → **Medium**
- 緩解策略：部署步驟強制 `npm run deploy`（build + copy），且 Ops 確認 `index.html` 修改時間與 assets 一致再放行。
- 應變計畫：立即以完整 `npm run deploy` 覆蓋，無需後端回滾。
- 責任人：IT / Ops

---

### 假設（Assumptions）

- ASM-001：本次 MVP 接受「同裝置、同瀏覽器」的草稿可見範圍限制，產品與使用者已知悉跨裝置不同步的行為。
- ASM-002：老師最常見的中斷情境為在同一個工作階段（< 2 小時）內切換不同學生，而非跨日甚至跨週。草稿保留 7 天已涵蓋絕大多數情境（詳見下方已決策事項）。
- ASM-003：草稿資料為暫存輔助，不具法律或稽核效力，後端正式評量紀錄為唯一可信來源。
- ASM-004：主任與管理者不需要看到老師未送出的草稿；草稿可見範圍為同瀏覽器、同帳號。
- ASM-005：系統目前未有 Service Worker 或 IndexedDB 需求，以 localStorage 實作即滿足 MVP 效能要求。

---

### 已決策事項（原 TODO 項目，依業界慣例給出建議值）

**登出時是否清除草稿**
- 決策：**登出時必須清除**。多使用者共用裝置（補習班平板/桌機）場景下，localStorage 為 origin-scoped 而非 user-scoped，業界慣例是在 logout 事件中清除所有以當前 teacherId 為 prefix 的草稿 key，以防止跨帳號資料洩漏（對應 OWASP CWE-312 Cleartext Storage）。登出前應顯示確認提示讓老師知情。
- 實作位置：`App.vue` 的 logout handler 呼叫 `learningRecordDrafts.clearAllByTeacher(teacherId)`，在 token 清除前執行。

**草稿保留天數**
- 決策：**7 天（604,800 秒）**。補習班週課制下，一筆草稿對應一堂特定日期的課；超過一週未完成送出，代表已超過一個教學週期，上課細節已模糊，繼續保留的意義低且可能造成誤用。業界 localStorage 草稿慣用值為 7–14 天，取較短的 7 天符合本業務循環且降低過期草稿的噪音。
- 技術實作：草稿物件內寫入 `savedAt`（Unix timestamp），每次讀取前比較 `Date.now() - savedAt > 7 * 86400 * 1000`，過期則視為無效並靜默清除。

**草稿清單排序**
- 決策：**預設以「最近修改時間 DESC」為主排序，課程日期 DESC 為次排序**。這是 Google Docs、Notion、Figma 等主流 SaaS 的標準清單排序策略，符合老師「先繼續最近在填的那份」的操作直覺。使用者可切換排序為 P2 功能，MVP 固定此順序即可。

## 14. Definition of Done
- 所有 FR 通過 QA 驗收。
- UI/UX 驗收清單全數完成並經 UI/UX Designer sign-off。
- 本機草稿不影響正式 `LearningRecord` API 契約與主任審核流程。
- 前端 deploy 完成且評量頁可正常載入。
- `docs/CHANGELOG.md` 與必要操作說明完成更新。
- PM sign-off。
- CTO / 工程 Lead sign-off。