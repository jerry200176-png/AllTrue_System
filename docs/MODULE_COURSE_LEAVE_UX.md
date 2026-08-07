# Module PRD：課程管理與家長請假完整流程

> Issue：[#1601](https://github.com/jerry200176-png/AllTrue_System/issues/1601) · Epic：[#1600](https://github.com/jerry200176-png/AllTrue_System/issues/1600) · 2026-08-01

## 1. Problem / Opportunity

家長送出請假後，後端已建立 `student_leave` 工作流，但課程管理只顯示一條「請到主任收件匣」通知，沒有案件摘要、案件 ID deep-link 或「處理這筆請假」CTA。主任因此可能知道有事，卻不知道去哪裡按，也無法從課程管理快速核對原堂次與原因。

## 2. Goals / Non-goals

Goals：讓主任從課程管理直接辨識待處理案件並抵達指定案件；讓家長看到請假後的狀態；讓安排補課、核准不補課、退回各自成為可理解的一級決策。

Non-goals：不改 Charge/Invoice/Payment truth、不新增資料庫 schema、不改既有堂數／補課規則、不繞過角色權限或跨分校隔離、不重做主任總覽。

## 3. RACI

| 角色 | 責任 |
|---|---|
| Product/UX | 使用者流程、文案、狀態與 responsive acceptance |
| Frontend | CourseManagement/ParentPortal 導覽、案件摘要、可及性與測試 |
| Backend | 維持 ExceptionWorkflow API 與狀態契約；只有測試證明 payload 不足才擴充 |
| QA/Release | Playwright、回歸家族、CI、deploy、health/version、production evidence |
| Security/Architecture | 校區 ownership、家長 PII、堂數與 schedule 衍生資料審查 |
| AI assistant | 研究、實作、測試、證據整理；不替人批准高風險資料修復 |

## 4. Dependencies

依賴 `parentRequestLeave`、`listExceptionWorkflows`、`getExceptionWorkflow`、`generate-candidates`、`confirm-candidate`、`waive`、`reject` 既有契約，以及 App 的主任 deep-link state。回歸 #1099、#1101、#1342 與 LeaveCascade/ExceptionWorkflow 測試。

## 5. User stories / acceptance criteria

- 家長可以在未開始的課程送出請假；送出後看到「審核中」與補課安排尚未完成的說明。
- 主任在課程管理看到「家長請假待處理」及學生、原堂次、原因、期限與狀態；不用猜測入口。
- 主任點「處理這筆請假」後，主任頁直接定位到同一案件；案件動作明確為「安排補課／核准不補課／退回」。
- 缺資料、載入失敗、無待辦時仍有可理解狀態與重試/導覽。
- 390/412px 主要 CTA 不被藏起來，所有狀態與長文字可折行，無非預期水平溢出。

## 6. UI / UX specification

課程管理的待處理區位於頁面標題與課程清單之間；以單欄案件列呈現，不使用 KPI wall 或 pill 堆疊。案件列固定顯示：學生、原堂次日期時間、原因摘要、目前狀態、期限與「處理這筆請假」。右側/下方保留「查看全部請假」作為次要入口。狀態使用語意色但文字為主；CTA 使用 Design System 單一橘色 primary。

Loading 使用 skeleton；empty 顯示「目前沒有待處理的家長請假」；error 顯示原因與重試/回主任收件匣；長姓名與長原因自然折行。鍵盤可 focus 到案件 CTA，focus ring 可見，案件區使用 `aria-live`，錯誤使用 `role=alert`。

## 7. Functional requirements

1. `CourseManagement.vue` 讀取 branch-scoped `student_leave` open/candidate_ready workflows。
2. 每筆案件可產生主任 deep-link：`target=director`、`section=exception-workflows`、`workflowId`。
3. App 導覽同時相容既有字串頁面導覽與物件 deep-link，不破壞科目設定等既有入口。
4. 主任原有候選產生、確認補課、核准不補課、退回 API 與確認 modal 保持不變。
5. 家長端保留 `leave_requested`、rejected reason 與既有 PII/LINE/parent auth 規則。

## 8. Non-functional requirements

無不預期水平 overflow；初始 priority view 不發出分析型 API；CTA 可由鍵盤完成；分校 query 與後端 authorization 不被前端篩選取代；錯誤不可只寫 console；不新增高風險同步資料來源。

## 9. Technical direction

沿用 `frontend/src/api.js` 的 ExceptionWorkflow functions、`CourseManagement.vue` 的 branch-scoped loading、`DirectorDashboard.vue` 的 focus props 與 `App.vue` 的 navigation contract。視需要新增純顯示 helper/test，不新增資料表或改變 `ExceptionWorkflowController` 狀態轉移。

## 10. Decision log

- D1：主任的決策真相仍在主任收件匣；課程管理提供案件摘要與 deep-link，不複製一套核准流程。
- D2：使用明確動詞「處理這筆請假」；避免「查看」造成使用者不知道下一步。
- D3：保留現有 three-way decision 與 confirmation modal；本 PR 修 discoverability/integration，不重寫 domain logic。
- D4：`CourseManagement` 的 string navigation 與 object deep-link 由 App adapter 統一處理。

## 11. Security / privacy / authorization

只使用後端回傳的 branch-scoped workflow summary；不在前端推導或放寬校區權限。學生姓名與原因屬 PII，頁面不寫入 URL，URL 只帶 workflow ID；主任頁再次由後端檢查 ownership。家長端仍由 parent session/student ownership 保護。

## 12. QA plan

Playwright real Vue page evidence：normal、empty、loading、API error、long text、dense；390、412、768、1280、1440；assert `scrollWidth <= clientWidth`、案件 CTA 可見與 deep-link payload。後端/回歸：ExceptionWorkflow existing feature tests、#1099 leave_requested 排除評量、#1101 ghost session、#1342 candidate/slot repair；Vite build、lint、design token guard。高風險狀態變更仍由既有 backend tests/CI 驗證。

## 13. Rollout / rollback

feature branch → PR → CI 全綠 → merge → `deploy.yml` → health/version → desktop/mobile production smoke。rollback 為 revert PR/merge SHA；不做資料庫 rollback，因本模組無 schema/data truth migration。若 API payload/權限驗收失敗，停止 merge，保留 Issue blocker。

## 14. Milestones / risks / open questions / DoD

Milestones：M1 baseline docs + GitHub tracking；M2 test-first page evidence；M3 implement navigation/queue；M4 CI/PR/merge/deploy；M5 production evidence。Risks：工作流與堂數/課表衍生資料耦合、deep-link 權限、長內容 mobile overflow。Open question：production 是否存在歷史 workflow 缺 `due_at`/student summary；若存在，前端要提供降級文案，不直接修改資料。

DoD：主任能從課程管理找到指定家長請假案件並完成既有決策；家長狀態可理解；所有驗收矩陣、CI/deploy/health/version/evidence、CHANGELOG 與 regression records 完整；未完成工作仍在 Issue。

## Research references

- [Power BI dashboard design tips](https://learn.microsoft.com/en-us/power-bi/create-reports/service-dashboards-design-tips)
- [Grafana dashboard best practices](https://grafana.com/docs/grafana/latest/visualizations/dashboards/build-dashboards/best-practices/)
- [Metabase BI dashboard best practices](https://www.metabase.com/learn/metabase-basics/querying-and-dashboards/dashboards/bi-dashboard-best-practices)
- [Atlassian workflow statuses and resolutions](https://support.atlassian.com/jira-cloud-administration/docs/what-are-issue-statuses-priorities-and-resolutions/)
- [Primer ActionList](https://primer.style/product/components/action-list/)、[Primer content](https://primer.style/product/getting-started/foundations/content/)、[Primer buttons](https://primer.style/product/components/button/)
- Starred repo snapshots used in research: `vbenjs/vue-vben-admin@9f5b1cd`, `filamentphp/filament@38b1d2b`, `GibbonEdu/core@42b1d2b`, `chatwoot/chatwoot@bc7ae88`。
