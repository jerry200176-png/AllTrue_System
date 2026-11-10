# 正職教師薪資要件：主任規則對齊 v2

## 1. 文件資訊

| 項目 | 內容 |
|---|---|
| 功能名稱 | 正職教師薪資要件—假日假與常態排課規則對齊 |
| 版本／日期 | v2／2026-08-09 |
| 狀態 | PLAN/ARCH，已獲准進入 DEV |
| 目標角色 | 主任、總部財務、正職教師薪資報表使用者 |
| 風險等級 | T2 Product workflow／薪資高風險變更 |

## 2. 目標與業務背景

主任定稿：假日假是「維持資格、不創造時數」；假日前常態排課滿16小時者，請假不扣假日倍率或每週16段獎金，常態不足16小時者不因假日假產生10%倍率。平日下午只看固定存在於學生課表的常態排課，排除補課／臨時加課；每日扣4小時低消，常態5.5小時為0.75段，固定到22:00的完整段不因當日到21:30被截短。

KPI：8小時常態＋8小時假日假必為0%；16小時常態因請假仍為10%；5.5小時常態必為0.75段；補課／臨時課不增加倍率；既有16段／40小時及月4週／4,000元案例零回歸。

## 3. 範圍

### In Scope

- 假日倍率改用請假前常態假日排課基準；假日假不加到倍率時數。
- 假日假作為週16段獎金的中性例外；既有16段與40小時門檻保留。
- 平日下午只計固定常態正課，排除補課／臨時加課／輔導／不可安排時段。
- 重疊常態課表先合併有效時間區間，再扣每日4小時低消，最高5%。
- 報表顯示常態基準、實際出勤、假日假、有效段數與排除原因。

### Out of Scope

- 科目數、升學成果、年度績優、扣除案件規則。
- 教師刷卡／學生點名來源整體重構。
- 已結算薪資快照的歷史重算；如需追溯另立資料修復計畫。

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| 實作 Agent | `[FEATURE]` | R |
| 測試與 QA Agent | `[TEST]` | R |
| 資安／程式審查 Agent | `[REVIEW]` | R |
| 文件 Agent | `[DOCS]` | R |
| 部署／驗收 Agent | `[OPS]` | R |
| 使用者／主任／總部 | 人類協作者 | I |

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 前置 PR | 既有正職薪資要件與重疊課表修正 | 已完成 |
| 外部服務 | 無新增第三方服務；沿用 Alltrue 課表、ClassSession、StudentSingIn、薪資事件 | 已存在 |
| 資料前提 | 需辨識常態／補課／臨時課；現有 `schedules.type=extra` 可辨識補課，未辨識者不得猜測 | 盤點中 |
| 發布控制 | GitHub CI 綠燈後由 `deploy.yml` 自動部署 | 已存在 |

## 5. User Stories／驗收條件

- US-001 主任可看到假日常態排課與假日假分開計算：16＋請假仍10%；8＋假日假仍0%；假日假不湊滿16。
- US-002 主任可看到平日常態排課計算：4／5／5.5／6小時分別為0／0.5／0.75／1段；補課與臨時課不計；固定至22:00的段不因當日21:30結束被截短。
- US-003 主任可查核原因：回應含基準時數、出勤、假日假、有效段數、排除原因與缺少欄位。

## 5b. UI/UX 精緻化

| 面向 | 規格 |
|---|---|
| 版面 | `TeacherEligibilityPage` 每個項目獨立卡片；主結果先顯示狀態／倍率，再顯示計算基準與明細。 |
| 色彩 | 符合使用 success；規則正常但0%使用 neutral；資料缺失使用 warning；沿用 design tokens。 |
| 互動 | 重新整理、查詢期間、明細展開均有 loading；API 失敗顯示 inline 錯誤與重試。 |
| 空狀態 | 無假日顯示「本期間無需計算假日」；無常態課表顯示資料來源與缺失，不顯示孤立0。 |
| 防呆 | 常態時數與假日假分欄；補課／臨時課以排除原因呈現。 |
| 響應式／無障礙 | 手機單欄無水平溢出；展開按鈕可鍵盤操作、有 `aria-expanded`；對比度≥4.5:1、觸控目標≥44px。 |

## 6. 功能需求

- FR-001：假日倍率只比較每個假日的 `regular_scheduled_hours` 與16小時門檻，不把 `holiday_leave_hours` 加入門檻。
- FR-002：假日假保留週16段獎金例外，但不創造假日倍率；既有40小時門檻照算。
- FR-003：平日下午只納入固定學生課表的常態正課；排除補課、臨時加課、輔導、不可安排。
- FR-004：同日重疊或相接常態區間合併後再扣4小時低消，最高5%。
- FR-005：來源分類缺失時輸出缺少欄位，不以實際出勤或時數猜測。
- FR-006：既有週16段／40小時、月4週／4,000元及其他獎金零行為回歸。

## 7. 非功能需求

- 單一分校100名教師、12週期間查詢 API P95 <2秒，禁止新增未受控 N+1。
- 相同輸入的結果可由 response metrics 重現；缺失資料 fail-closed，不自動創造獎金。
- 沿用登入、分校隔離、PIN 與後端計算邊界；前端不可覆蓋倍率。

## 8. 技術方向

- 沿用正職薪資要件查詢 API 與政策服務，將假日常態基準、實際出勤、假日假拆成不同輸入／metrics。
- 平日下午基準使用 `schedules` 常態分類；`ClassSession`／`StudentSingIn` 保留作週工時與實際出勤佐證。
- `teacher_payroll_events` 保存假日／假日假事件，不把假日假轉成出勤時數。
- 沿用 `TeacherEligibilityPage`，增加基準與排除原因，不另建第二套報表。
- 若未來資料無法辨識臨時課，使用 nullable additive 欄位並進資料缺失路徑，不靠備註或到班狀態推測。

## 8b. Decision Log

| 日期 | 決策 | 替代方案 | 理由 |
|---|---|---|---|
| 2026-08-09 | 假日假維持資格、不創造倍率 | 直接加到16小時 | 主任明確說常態8小時不能因假日假產生倍率。 |
| 2026-08-09 | 假日倍率採常態基準 | 只看實際出勤 | 請假不能扣掉原本達標倍率，也不能用出勤＋假日假湊時數。 |
| 2026-08-09 | 平日下午只計常態排課 | 合計所有 schedules | 補課／臨時加課不屬常態。 |
| 2026-08-09 | 以 metrics 保存可追溯理由 | 只回傳倍率 | 主任需能查核薪資判定。 |

## 9. 資安與存取控制

沿用既有 finance／director／總部角色與分校 scope；薪資、教師、學生到班資料視為敏感資料。後端重算並輸出規則版本／來源 metrics；前端不可提交倍率。STRIDE 審查須確認 token、篡改、稽核、跨校資訊洩漏、查詢濫用與權限提升均無新增 HIGH。

## 10. QA 驗收

### 必測案例

- [ ] 16段且40小時符合；15.5段或39.5小時不符合。
- [ ] 輔導不計16段。
- [ ] 常態假日16小時＋假日假：10%，週16段獎金不扣。
- [ ] 常態假日8小時＋8小時假日假：0%。
- [ ] 平日4／5／5.5／6小時：0／0.5／0.75／1段。
- [ ] 常態固定至22:00、當日到21:30：完整段。
- [ ] 補課／臨時加課／輔導／不可安排不增加平日倍率。
- [ ] 重疊時段只計一次，倍率不超過5%。
- [ ] 月週獎金最多4週／4,000元。
- [ ] 欄位缺失列缺少資料，不把缺失當0%。

### UI／安全

- [ ] 假日卡片分開顯示常態、出勤、假日假與原因。
- [ ] 平日卡片顯示低消、有效段數與排除原因。
- [ ] loading、空狀態、錯誤重試、手機、鍵盤與對比度符合5b。
- [ ] PHPUnit、前端 build、權限與靜態掃描全綠。

## 11. 上線與維運

由 feature branch → PR CI → merge `main` → `.github/workflows/deploy.yml`；驗證 `/api/v1/health`、`version.json`、`deployment.json` 與 post-merge smoke。規則不新增永久前端旗標；若回歸，以 `git revert` 回上一個已部署 SHA，不直接改 production。新增欄位只能 nullable additive migration。

Observability：監看 `/api/v1/health`、部署 SHA、`teacher_eligibility` 計算錯誤與「8小時＋8小時假日假非0%」；任一異常阻擋發布驗收。

## 12. 里程碑與優先級

- P0 `[FEATURE]`：政策輸入、假日基準、常態排課分類與平日計算。
- P0 `[TEST]`：政策、controller、API contract、回歸與 UI smoke。
- P0 `[REVIEW]`：資料隔離、權限、靜態掃描與規則逐條審查。
- P1 `[DOCS]`：資料來源指南、CHANGELOG、STAFF_UPDATES。
- P1 `[OPS]`：CI、部署、health、正式 smoke。
- P2 `[FEATURE]`：歷史已結算月份重算工具另立計畫。

## 13. 風險／假設／開放問題與外部研究

外部研究只採用模式，不複製程式碼：Odoo 將 work entries、Time Off、Attendances、Planning 與 salary rules 分層（[Payroll](https://www.odoo.com/documentation/17.0/applications/hr/payroll.html)、[Salary Rules](https://www.odoo.com/documentation/19.0/applications/hr/payroll/salaries.html)）；Frappe HR 將 Leave／Attendance 與 Payroll 作為可整合但分開模組（[frappe/hrms](https://github.com/frappe/hrms)）；SAP 以 payroll control record 限制生效資料變更並保留有效期（[SAP control record](https://help.sap.com/docs/successfactors-platform/implementing-business-rules-in-sap-successfactors/get-payroll-area-control-record)）。Alltrue 採資料來源分離、規則分項與可追溯 metrics。

| 風險 | 等級 | 本專案採行方式 |
|---|---|---|
| 假日假誤加成倍率 | 高 | 只比較常態基準，假日假單獨輸出。 |
| 補課／臨時課混入 | 高 | 明確排除 `type=extra` 等來源；分類缺失不猜。 |
| 規則修改無法追溯 | 高 | 保存規則版本與基準 metrics；不自動重算已結算快照。 |
| 舊資料缺新分類 | 中 | nullable additive 欄位；缺失資料走明確缺失狀態。 |

假設：若無法重建請假前常態基準，系統列出缺少基準資料；若歷史重算需求出現，標記為 `[BLOCKED: 需另立已結算薪資資料修復計畫]`。其他欄位盤點與既有資料映射為 `[AI-RESOLVABLE]`。

## 14. Definition of Done

- [ ] 假日政策：PHPUnit 通過16小時請假保留、8小時不能創造倍率案例。
- [ ] 平日政策：PHPUnit 通過4／5／5.5／6小時、固定22:00完整段案例。
- [ ] 排除與重疊：PHPUnit 斷言補課／臨時課不計、重疊只計一次、上限5%。
- [ ] API metrics：contract test 斷言基準、假日假、有效段數、排除原因與規則版本。
- [ ] UI/UX：UI smoke 逐條對照5b，無 layout shift，鍵盤可用。
- [ ] 資安：`npm run ci:preflight`、權限測試與靜態掃描無新增 HIGH。
- [ ] 文件：CHANGELOG、STAFF_UPDATES、資料來源指南含生效日與主任規則。
- [ ] 部署：`deploy.yml` 成功，health 200，SHA 一致，post-merge smoke 全綠。

## 執行狀態

使用者已確認開始實作；本文件作為本 PR 的 PLAN/ARCH。任何超出上述範圍的歷史薪資重算或資料修復，另立計畫，不在本次直接處理。
