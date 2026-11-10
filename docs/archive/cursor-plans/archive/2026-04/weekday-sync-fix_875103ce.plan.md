---
name: weekday-sync-fix
overview: 修正課程管理編輯固定排課星期後，課程主檔與未來堂次未同步更新的問題，並補強前後端與回歸測試，避免再次出現「改成一二四但仍停留舊星期」的回歸。
todos:
  - id: backend-api-data
    content: "[FEATURE] 修正 `backend/app/Http/Controllers/StudentClassController.php` 的固定星期更新、未來堂次 remap / sync、以及 reconcile 保護條件，確保改成一二四後未來未上堂次真的對齊新契約。"
    status: completed
  - id: frontend-ui-functional
    content: "[FEATURE] 修正 `frontend/src/pages/CourseManagement.vue` 與必要的 `CourseEditForm.vue` 儲存路徑、payload 與刷新行為，避免 Laravel 課程誤走舊 fallback 並讓同步結果可見。"
    status: completed
  - id: ui-ux-polish
    content: "[FEATURE/UI-UX] 依第 5b 節精緻化課程編輯成功/警示回饋：loading、成功/部分同步文案、刷新期間的可感知狀態；若本次不做新元件，至少完成既有 UI 文案與回饋品質提升。"
    status: completed
  - id: test-design
    content: "[TEST] 補強 `backend/tests/Feature/StudentClassUpdateScheduleReconcileTest.php`，涵蓋改成一二四、移除舊星期、全部未來堂次鎖定、以及前端常見 payload 形狀。"
    status: completed
  - id: qa-validation
    content: "[QA] 依 PRD 第 10 節執行手動驗收：大安課程改星期後檢查課程列表、未來堂次、部分鎖定提示與跨頁重載表現。"
    status: completed
  - id: security-review
    content: "[REVIEW/資安] 確認 `PUT /api/v1/student-classes/{id}` 仍受既有角色與 `require_campus` / 校區隔離保護，本次修正不會跨校抓到他校課程。"
    status: completed
  - id: code-review
    content: "[REVIEW] 對前後端變更做 code review，重點檢查固定排課契約與堂次一致、歷史堂次不回寫覆蓋、鎖定堂次不被誤改。"
    status: completed
  - id: docs-update
    content: "[DOCS] 更新 `docs/CHANGELOG.md`；若修正策略與既有回歸守則有新增注意事項，再補到 `docs/AI_REGRESSION_LESSONS.md`。"
    status: completed
  - id: deploy-release
    content: "[Ops] 若有前端變更，執行 `cd /home/admin/frontend && npm run deploy` 並確認課程管理頁最新 bundle 已上線；若本次純後端則標註不適用原因。"
    status: completed
  - id: uiux-signoff
    content: "[UI/UX Designer] 確認第 5b 與第 10 節 UI/UX 驗收項目均完成，特別是同步成功/部分同步/無需同步三種回饋清晰可懂。"
    status: completed
  - id: pm-signoff
    content: "[PM] 確認此問題在真實案例可重現並已修復，DoD 全部勾選後核准進入實作與上線。"
    status: completed
isProject: false
---

# 課程管理固定排課星期未同步修正 PRD

## 1. 文件資訊
- 功能名稱：課程管理固定排課星期同步修正
- 版本 / 日期：v1 / 2026-04-16
- 狀態：Draft
- 目標角色：主任、櫃檯；間接受影響老師

## 2. 目標與業務背景
- 痛點：主任在 `CourseManagement.vue` 把課程固定星期改成一、二、四後，畫面或堂次列表仍保留舊星期，會讓現場誤以為系統沒有儲存成功，甚至影響後續補課、點名與評量安排。
- 業務價值：讓「課程契約」與「未來未上堂次」保持一致，降低人工核對與客服介入成本，避免課程時段錯亂影響排課與教學。
- 成功指標：
- 主任修改固定星期後，課程列表與未來未上堂次在單次操作後即一致。
- `PUT /api/v1/student-classes/{id}` 的同步結果可明確反映是否有未來堂次被重排。
- 回歸測試覆蓋「移除舊星期」「改成新星期」「有歷史紀錄但仍需同步未來堂次」三類情境。

## 3. 範圍
- In Scope：
- 修正課程編輯時固定星期 / `day_time_slots` 儲存與後端同步判定。
- 修正未來 `ClassSession` 對新契約星期的 remap / sync 行為。
- 修正前端編輯送出後的成功訊息與重新載入，使使用者能看到實際同步結果。
- 補齊後端 Feature tests 與手動 QA 驗收案例。
- 更新 `docs/CHANGELOG.md` 與必要的回歸說明。
- Out of Scope：
- 不重做整個排課架構。
- 不變更請假 / 調課 / 補課的商業規則。
- 不修改核准評量扣堂、繳費提醒、月結規則。

## 4. RACI
- PM：A，確認需求邊界與驗收條件。
- CTO / 工程：R，決定修正策略與實作方式。
- UI/UX Designer：R，確認編輯成功回饋、錯誤提示、堂次偏移顯示是否清楚。
- QA：R，執行固定星期修改與未來堂次同步驗收。
- 資安：C，確認權限與跨校區隔離無回歸。
- IT / Ops：I，配合部署與回滾。
- UI/UX Designer 職責：針對課程編輯 modal、成功訊息、堂次偏移提示、空狀態與 loading 狀態進行可理解性與一致性把關。

## 5. User Stories
- As a 主任, I want 編輯課程固定星期後立即看到新星期與未來堂次同步, so that 我可以確認排課契約真的更新完成。
- Acceptance Criteria：
- 修改固定星期並儲存後，課程列表顯示的新星期與送出內容一致。
- 若有可調整的未來未上堂次，系統應同步重排到新星期。
- 若部分堂次因已點名 / 已核准評量而鎖定，系統需明確提示哪些未同步，而不是靜默失敗。
- As a 櫃檯, I want 系統不要把舊堂次歷史回寫成新的契約星期, so that 我不會看到改完後又被洗回舊資料。
- Acceptance Criteria：
- 當未來可同步堂次為 0 時，`StudentClass` 契約欄位仍保留使用者新設定，不可被舊 `ClassSession` 回寫覆蓋。
- 移除某星期後，沒有未來堂次時也不可因歷史堂次把該星期加回契約。

## 5b. UI/UX 精緻化需求
- 受影響頁面：`frontend/src/pages/CourseManagement.vue`
- 版面層次：編輯成功訊息需把「課程已更新」與「未來堂次同步結果」分成主訊息與次訊息，避免使用者只看到泛用成功文案。
- 色彩一致性：若有未同步或部分鎖定，應使用既有警示色；完全成功同步則維持成功色語意。
- 互動回饋：儲存期間需保持明確 loading / disabled 狀態；儲存成功後，列表需在可感知的時間內刷新為新契約資料。
- 空狀態設計：若沒有可同步的未來堂次，需顯示「無需調整或已無未來堂次」而非模糊成功訊息。
- 載入狀態：重新載入課程列表期間避免使用者短暫看到舊星期資料，必要時以局部 loading 或 await refresh 控制。
- 防呆設計：若課程被錯誤判定走舊 Supabase 更新分支，應改為統一路徑或至少有明確 fallback 訊號，不可靜默掉 `day_time_slots`。
- 響應式：手機與桌機皆需能看懂同步結果文案，不可只靠過長 alert 文字堆疊。

## 6. 功能需求
- FR-001：系統應在課程編輯儲存時，將 `days_of_week` 與 `day_time_slots` 一致送至 `PUT /api/v1/student-classes/{id}`。
- FR-002：系統應在固定星期改變時，將未來且可變動的 `ClassSession` 依新契約星期重新對齊，而非只改時間不改日期。
- FR-003：當未來堂次無法同步時，系統應保留新契約欄位，禁止以舊 `ClassSession` 歷史覆寫 `StudentClass.week* / time*`。
- FR-004：前端應在儲存後顯示結構化同步結果，包含已同步筆數、無需調整、或因鎖定未同步三種情境。
- FR-005：課程編輯流程應避免誤走舊資料來源更新分支，造成 `day_time_slots` 未送出或未觸發 partial rebuild。
- FR-006：系統應以測試覆蓋「改成一二四」「移除舊星期」「有歷史但仍需改未來堂次」「全部未來堂次鎖定」等情境。

## 7. 非功能需求
- API 效能目標：單次課程編輯同步在一般案例下維持可接受回應，避免因逐筆重排造成明顯卡頓。
- 降級策略：若未來堂次同步失敗，API 必須明確回傳原因，前端顯示警示並保留使用者更新後的契約欄位。
- 可維護性：固定星期契約判定、未來堂次同步、reconcile 保護條件應集中在既有 `StudentClassController` 同一路徑，不新增第二套規則。

## 8. 技術方向
- 受影響頁面：`frontend/src/pages/CourseManagement.vue`、`frontend/src/components/CourseEditForm.vue`
- 受影響 API：`PUT /api/v1/student-classes/{id}`
- 受影響資料表：`StudentClass`、`ClassSession`、`LearningRecord`、`StudentSingIn`、`schedules`
- 受影響後端：`backend/app/Http/Controllers/StudentClassController.php`
- 受影響測試：`backend/tests/Feature/StudentClassUpdateScheduleReconcileTest.php`
- 架構取捨：
- 以既有 `mapFrontendPayload`、`maybeRebuildSessionsAfterUpdate`、`syncFutureScheduledSessionTimes`、`remapFutureScheduledSessionsToContract`、`reconcileWeekTimeFieldsFromSessions` 為主修正，不另開新排程 API，避免再次分叉。
- 前端以 Laravel 更新路徑為主，減少舊 Supabase fallback 對固定星期欄位的污染。
- 若需 migration：本案預期不需要 migration。
- 子任務 Agent 派發：
- `[FEATURE]`：修正前端送出與後端同步／reconcile 流程。
- `[TEST]`：補強 Feature tests 與手動驗收案例。
- `[REVIEW]`：審查跨校區、鎖定堂次、歷史資料不被覆寫等風險。
- `[DOCS]`：更新 `docs/CHANGELOG.md` 與必要回歸紀錄。

## 9. 資安與存取控制
- 存取角色：維持既有可編輯課程角色；不可放寬 teacher / director / admin 之外的權限。
- PII：本案不新增敏感資料欄位，但涉及學生課程與分校資料，仍須受 `require_campus` / 校區過濾保護。
- 稽核：至少保留既有課程更新 API 回應與必要 log 能力，便於釐清同步 0 筆的原因。
- STRIDE 快評：
- Spoofing：無新增登入風險。
- Tampering：需避免錯誤 fallback 路徑寫入不完整契約資料。
- Information Disclosure：不可因重排或查詢未來堂次而跨校抓到他校課程。

## 10. QA 驗收標準與測試計畫
- FR-001 Happy Path：將課程從原本星期改成一、二、四後儲存，重新載入列表仍顯示一、二、四。
- FR-001 Edge：只有 `day_time_slots` 有值、`days_of_week` 空陣列時，前後端仍應還原正確星期。
- FR-001 Error：若 payload 缺少有效 slot，API 或前端需明確提示，而不是靜默成功。
- FR-002 Happy Path：課程已有歷史點名，但未來仍有 `scheduled` 堂次；改星期後，未來堂次日期同步移到新星期。
- FR-002 Edge：只改開始時間不改開課日，未來堂次仍要同步。
- FR-002 Error：若全部未來堂次鎖定，系統需提示 0 筆同步且保留新契約。
- FR-003 Happy Path：移除某個舊星期後，沒有未來堂次時，契約欄位不會被歷史堂次洗回去。
- FR-004 Happy Path：前端成功文案需區分完全同步、無需同步、部分鎖定三種結果。
- FR-005 Happy Path：Laravel 課程編輯必走 Laravel 更新分支，不可掉入只更新基本欄位的舊 Supabase 路徑。
- 回歸測試：對照 `docs/AI_REGRESSION_LESSONS.md` 的「固定排課契約與堂次一致」區塊，確認不回歸成只改時間、不改日期，或用歷史堂次回寫契約。
- UI/UX 驗收清單：
- [ ] 無未來堂次時有明確說明，不是只有模糊 alert。
- [ ] 儲存時有 loading / disabled 狀態。
- [ ] 成功 / 失敗 / 部分同步有清楚回饋。
- [ ] 課程列表刷新後不應短暫持續顯示舊星期造成誤判。
- [ ] 警示色與成功色語意一致。
- [ ] 手機上可閱讀同步結果，無溢出或被遮擋。

## 11. 上線與維運
- 部署步驟：
- 先完成後端與前端修正。
- 跑對應 Feature tests。
- 若有前端變更，執行 `cd /home/admin/frontend && npm run deploy`。
- 確認課程管理頁修改固定星期後，課程列表與未來堂次一致。
- 回滾方案：若發生同步異常，可回滾本次課程同步修正；不得手動清除歷史 `ClassSession` / `LearningRecord` 作為第一手回滾方式。

## 12. 里程碑與優先級
- P0：修正 `StudentClassController` 的固定星期同步與 reconcile 保護，確保改星期後未來堂次可正確 remap。
- P0：修正 `CourseManagement.vue` 的儲存路徑與刷新回饋，避免 Laravel 課程誤走舊更新分支。
- P1：補強後端回歸測試與手動 QA 劇本。
- P1：優化前端同步結果文案與局部刷新體驗。
- P2：若仍有觀測盲點，再補充 log / 診斷資訊方便現場排查。

## 13. 風險、假設、開放問題
- 高風險：`StudentClassController` 同時影響課程契約、未來堂次、評量與出缺勤關聯；修改需避免動到已鎖定堂次。
- 中風險：前端課程來源判定若仍混用 Laravel / Supabase，容易再次造成 payload 不完整。
- 假設：目前使用者案例的大安課程屬於 Laravel 路徑管理的正式課程，而非舊資料來源孤例。
- 假設：需求是「改固定星期後，未來未上堂次要跟著改」，而不是只改課程主檔顯示。
- 開放問題：[TODO: 需確認] 是否需要在 UI 額外露出「哪些堂次因鎖定未同步」的更細節名單；若不做，先以摘要文案處理。

## 14. Definition of Done
- [ ] 所有 FR 通過 QA 驗收。
- [ ] UI/UX 驗收清單全部完成，並經 UI/UX Designer sign-off。
- [ ] 權限與分校隔離無回歸。
- [ ] 對應 Feature tests 通過。
- [ ] 若有前端變更，已完成 `npm run deploy`。
- [ ] `docs/CHANGELOG.md` 更新。
- [ ] PM sign-off。
- [ ] CTO / 工程 Lead sign-off。