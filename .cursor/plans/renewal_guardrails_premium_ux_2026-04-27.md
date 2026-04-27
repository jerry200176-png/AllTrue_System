# PRD：續報防呆與 3A 級高質感營運後台

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 續報防呆與高質感營運後台 UX 改版 |
| 版本 | v1.0 |
| 狀態 | PLAN，待使用者批准進 ARCH/UX |
| 目標角色 | 主任、老師、行政、未來多分校管理者 |
| Risk Tier | T3（續報/堂數/繳費）+ T2（複雜工作流與 UI） |

## 2. 目標與業務背景

目前續報、加購、月結續約、結案、不續報、補課/調課等流程已能運作，但使用者容易遇到「不知道該按哪個」、「按了之後不知道會改哪筆課」、「新舊課程/帳單/堂數關係不透明」等問題。這類錯誤會造成多排課、重複提醒、繳費狀態混亂與客服成本。

本次目標是把高風險流程改成大廠常見的「先預覽、再確認、可追蹤、可復原」模式，並讓畫面從一般後台升級成有品牌感、層次感與即時回饋的高質感營運中心。

KPI：
- 續報/加購流程的錯誤操作率降低 70%（以 undo / reverted / 重複課表 bug 數量近似衡量）。
- 主任完成「續報一筆課程」的步驟數 ≤ 4 步。
- 所有高風險操作完成後 1 秒內顯示結果摘要與下一步 CTA。
- 前端主要流程 Lighthouse 可用性/無障礙自檢無 Critical。

## 3. 範圍

### In Scope

- 續報/加購/不續報/結案流程重設為 guided wizard。
- 建立「續報預覽」：會產生哪一筆新課程、起訖日、堂數、金額、繳費狀態、是否結案舊課、是否新增帳單。
- 建立操作結果 receipt：成功後顯示舊課/新課/帳單/課表異動摘要。
- 防止重複續報：同一學生、同科目、同時段已有進行中或待繳新批次時，先提示而非直接新增。
- 複雜流程 UX audit：課程管理、學生管理、通知中心、智慧課表、學習評量、出缺勤。
- 3A 級視覺語言：營運 HUD、玻璃層卡片、狀態色、動效、空狀態與 loading skeleton。
- 測試、文件、部署與 health check。

### Out of Scope

- 不刪除既有歷史 `CoursePackage` / `StudentClass` / `Invoice` 資料。
- 不重寫整個前端框架，不引入大型 UI framework。
- 不改繳費/續課提醒的列入條件，除非另有明確 PRD 與測試。
- 不做 WebGL/3D 遊戲引擎式渲染；3A 風格只取 HUD、層次、光影、動效語言。

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（產品規劃） | `[PLAN]` | R |
| AI Agent（架構） | `[ARCH]` | R |
| AI Agent（UX） | `[UX]` | R |
| AI Agent（實作） | `[FEATURE]` | R |
| AI Agent（測試） | `[TEST]` | R |
| AI Agent（審查） | `[REVIEW]` | R |
| AI Agent（文件） | `[DOCS]` | R |
| AI Agent（部署） | `[OPS]` | R |
| 使用者 | CEO | I |

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置 PR | PR #138 0 元核帳與課表重複修復 | 已完成 |
| 前置 PR | PR #139 移除過時建立課程方案入口 | 已完成 |
| 業務規則 | `docs/DIRECTOR_PAYMENT_ALERT_RULES.md` 不可擅改提醒列入條件 | 需遵守 |
| 技術前提 | 本地無 PHP，PHPUnit 以 GitHub Actions 為準 | 已知 |

## 5. User Stories + Acceptance Criteria

### US-001：主任安全續報堂數制課程

As a 主任，I want 續報前先看到新舊課程與費用影響，so that 我不會不小心建立錯的課程。

AC：
- 續報前顯示「舊課程狀態」「新課程預計建立內容」「是否結案舊課」三段摘要。
- 若同學生同科目已有待繳新批次，系統提示「可能已續報」，提供前往查看，不直接再建一筆。
- 成功後顯示 receipt，包含新課程 ID、上課日期範圍、需收金額、繳費狀態。

### US-002：主任安全續報月結課程

As a 主任，I want 月結續約清楚生成下一期帳單，so that 不會以為已繳狀態自動延續。

AC：
- 續約前顯示新到期日、下一期帳單月份、應收金額、繳費狀態。
- 成功後 receipt 顯示 Invoice 期別與是否未繳。
- 若該期 Invoice 已存在，系統提示已建立，不重複建立。

### US-003：主任快速辨識複雜頁面當前重點

As a 主任，I want 進入頁面後立刻知道今天要處理什麼，so that 不需要在一堆表格裡找重點。

AC：
- DirectorDashboard / CourseManagement / StudentsList 頁首有 HUD 區：待收款、待續報、今日異常、課表衝突。
- 每個卡片都有狀態色、數字、主要 CTA。
- loading 時使用 skeleton，不顯示空白閃爍。

## 5b. UI/UX 精緻化

| 面向 | 規格 |
|---|---|
| 版面層次 | 建立「Command HUD」：頁首 4 個高優先狀態卡 + 下方操作區；重要數字 24–32px，輔助文字 12–14px |
| 色彩一致性 | 使用狀態色：安全綠、提醒琥珀、危險紅、資訊藍；玻璃效果只用在容器，不用在正文密集表格 |
| 互動回饋 | 高風險按鈕先進 preview，不直接提交；提交時按鈕 loading；成功後 receipt + toast |
| 空狀態設計 | 每個列表空狀態包含圖示、原因、下一步 CTA，例如「沒有待續報課程，查看所有課程」 |
| 載入狀態 | 列表用 skeleton row，HUD 用 skeleton card；禁止整頁空白 |
| 防呆設計 | 所有 destructive / financial / schedule-changing 操作採 preview → confirm → receipt |
| 響應式 | 桌面優先；平板 2 欄；手機 1 欄；觸控目標 ≥ 44px |
| 無障礙 | 對比 ≥ 4.5:1；支援 `prefers-reduced-motion`；動效可降級 |
| 3A 風格 | 借用遊戲 HUD、深度、微光、動效與任務式流程；不使用影響可讀性的重度特效 |

## 6. 功能需求 FR

| ID | 需求 |
|---|---|
| FR-001 | 系統應提供續報 preview，在提交前回傳新舊課程、堂數、金額、帳單、課表異動摘要 |
| FR-002 | 系統應阻擋或警示同學生同科目同時段的重複續報 |
| FR-003 | 堂數制續報成功後應清楚標示「新批次課程」並引導查看新批次 |
| FR-004 | 月結續約成功後應清楚標示下一期 Invoice 與未繳狀態 |
| FR-005 | 高風險操作成功後應產生 receipt，可供主任回看 |
| FR-006 | 課程管理與學生管理應顯示高風險狀態 badge：低堂數、未繳、已結案、暫停、待確認 |
| FR-007 | 系統應建立複雜流程 UX audit 清單，標出 P0/P1/P2 改善項 |
| FR-008 | 前端應建立 Premium UI tokens：背景、卡片、陰影、狀態色、動效時長 |
| FR-009 | 所有新增動效應尊重 reduced motion |

## 7. 非功能需求 NFR

- Preview API P95 < 800ms。
- 續報提交 API P95 < 1200ms，不含前端動畫。
- 前端初始 bundle 不增加超過 15%。
- 不新增 unauthenticated endpoint。
- 所有跨分校資料查詢必須帶校區範圍。

## 8. 技術方向

- 後端：在課程續報相關 API 前增加 preview/validation 層，避免直接寫入。
- 資料：優先沿用 `StudentClass`、`ClassSession`、`Invoice`、`Payment`；receipt 若需要持久保存再評估新增輕量 audit table。
- 前端：先改最常用入口 `StudentsList` / `CourseManagement`；建立 reusable `PreviewConfirmModal` 與 `ActionReceipt` 類型元件。
- UX：先建立 design tokens，不一次重寫所有頁面。
- 分 PR：先續報防呆，再複雜流程 audit，再 premium shell/HUD。

## 8b. Decision Log

| 日期 | 決策 | 考慮過的替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-27 | 續報採 preview → confirm → receipt | 直接在現有 modal 加更多提示 | 大廠 billing/plan change 都先預覽金額與生效日期，防錯效果更強 |
| 2026-04-27 | 3A 視覺採 HUD + motion + glass-lite | 引入 WebGL/3D 特效 | 後台需要可讀性與效能，重度特效不適合密集資料 |
| 2026-04-27 | 分三批 PR | 一次大改所有頁面 | 降低 CI/部署風險，方便逐步驗收 |

## 9. 資安與存取控制

STRIDE 快評：
- Spoofing：所有 preview/submit API 仍使用現有 Bearer token。
- Tampering：submit 前後端都重新驗證，不信任前端 preview 結果。
- Repudiation：receipt/audit 需記錄操作人、時間、新舊狀態。
- Information Disclosure：只回傳該主任校區可見學生/課程。
- Denial of Service：preview 查詢需限制範圍與分頁。
- Elevation of Privilege：teacher 不可執行主任續報/繳費操作。

## 10. QA 驗收

Happy Path：
- 堂數制 0 堂續報 → preview → 建新批次 → receipt → 新批次可查看。
- 月結續約 → preview 下一期帳單 → 建 Invoice → receipt 顯示未繳。

Edge：
- 同學生同科目已有待繳新批次 → 顯示警告，不重複建立。
- 月結同 billing period Invoice 已存在 → 不重複建立。
- 已結案課程不可新增未來堂次。

Error：
- 無校區權限 → 403。
- Preview 與 submit 之間資料變更 → submit 回傳需要重新預覽。

UI/UX：
- loading / empty / error / success 四態都可見。
- reduced motion 下不播放大幅動效。

## 11. 上線與維運

部署：feature branch → PR → CI → merge → deploy.yml 自動部署。

Feature Flag：
- `premium_renewal_flow`：先只開主任端續報入口。
- `premium_ui_shell`：先套用 CourseManagement / StudentsList，不一次套全站。

Observability：

| 監控項目 | 指標 / log 關鍵字 | 告警閾值 | 負責 Agent |
|---|---|---|---|
| 續報 preview 失敗 | `renewal_preview_failed` | 1 小時 > 10 次 | `[OPS]` |
| 重複續報阻擋 | `duplicate_renewal_guard` | 每日統計 | `[OPS]` |
| 續報提交失敗 | `renewal_submit_failed` | 1 小時 > 5 次 | `[OPS]` |

回滾：前端可 revert PR；若新增 migration，需提供 down() 與資料保留策略。

## 12. 里程碑與優先級

| 優先級 | 階段 | 內容 | Agent |
|---|---|---|---|
| P0 | ARCH/UX | 續報防呆架構 + 互動稿 | `[ARCH]` / `[UX]` |
| P1 | DEV-1 | 續報 preview/confirm/receipt | `[FEATURE]` |
| P1 | DEV-2 | 重複續報防護與測試 | `[FEATURE]` |
| P2 | DEV-3 | 複雜流程 UX audit + P1 快修 | `[FEATURE]` |
| P2 | DEV-4 | Premium HUD / design tokens | `[FEATURE]` |

## 13. 風險 / 假設 / 開放問題

| 風險 | 等級 | 業界標準解法（來源） | 本專案採行方式 |
|---|---|---|---|
| 續報造成錯帳或重複課程 | 高 | Stripe/SaaS billing：先 preview proration/生效日再 confirm | 所有續報先 preview，不直接寫入 |
| 複雜流程仍讓使用者迷路 | 中 | Jira/bulk action UX：wizard、review step、結果回饋、undo | 高風險流程採 wizard + receipt |
| 3A 視覺影響可讀性 | 中 | 2026 UI 趨勢：glass/motion 要服務結構，不能遮蔽資訊 | glass-lite + reduced motion + contrast gate |
| 稽核不足導致事後查不出誰做了什麼 | 高 | Enterprise workflow：append-only audit trail + trace ID | 續報 receipt/audit 記錄操作者與前後狀態 |

假設：
- 續報錯誤主要來自流程不透明，而不是單一欄位 bug；若 audit 顯示不同，先修高頻 bug。
- 主任主要在桌面使用；若手機使用比例高，需提高 mobile priority。

開放問題：
- `[AI-RESOLVABLE]` 盤點現有續報入口與 API 是否都有唯一 flow。
- `[AI-RESOLVABLE]` 盤點最複雜的 5 個頁面與錯誤率。

## 14. Definition of Done

- [ ] 續報 preview：驗證方式：PHPUnit 覆蓋堂數制/月結/重複/權限情境，CI 0 failures。
- [ ] 續報 receipt：驗證方式：前端 build 通過，Review 對照 FR-005 無缺口。
- [ ] 重複續報防護：驗證方式：新增測試模擬同學生同科目待繳新批次，API 不建立重複課程。
- [ ] Premium UI：驗證方式：`npm --prefix frontend run build` 成功，reduced-motion CSS 存在。
- [ ] UX audit：驗證方式：產出 `.cursor/plans/complex_workflow_ux_audit_*.md`，每項含 impact/effort。
- [ ] 資安：驗證方式：[REVIEW] STRIDE 無 HIGH。
- [ ] 部署：驗證方式：deploy.yml success，`curl /api/v1/health` 回傳 `status=ok`。

## Todos

| 類別 | 任務 | Agent |
|---|---|---|
| 後端 API / 資料 | 續報 preview/submit/audit 設計 | `[ARCH]` / `[FEATURE]` |
| 前端 UI 功能 | wizard、receipt、guard warning | `[FEATURE]` |
| UI/UX 精緻化 | HUD、design tokens、motion、empty/loading/error states | `[UX]` / `[FEATURE]` |
| 測試設計與自動執行 | PHPUnit + frontend build | `[TEST]` |
| 自動化 QA 驗收 | Happy/Edge/Error + UI checklist | `[TEST]` |
| 資安靜態審查 | STRIDE + 校區隔離 | `[REVIEW]` |
| Code Review | 逐條對照 FR | `[REVIEW]` |
| 文件更新 | CHANGELOG + SYSTEM_TECH_GUIDE（若新增 audit/狀態機） | `[DOCS]` |
| 部署與 health check | CI → merge → deploy.yml → health/version | `[OPS]` |
