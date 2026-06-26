# Bug Fix Plan — #172 計費模式轉換（堂數制↔月結）收據/發票未對帳（F2）

> ⚠️ 高風險帳務。實作前須 CEO 確認業務規則（見 §13 開放問題）；依 `DIRECTOR_PAYMENT_ALERT_RULES.md`「擅改前必問」。

## 0. 根因確認（Root Cause）
| 欄位 | 內容 |
|---|---|
| 嚴重度 | P1 |
| 根因類型 | 邏輯/流程缺口（計費模式變更未連動對帳）|
| 根因摘要 | 課程 `ScheduleMode` 由堂數制改月結時，舊 `Invoice`/`payment_report`/已開收據未連動作廢或標示「已被取代」；收據未綁 `billing_period` |
| 錯誤行為 | 黃玟睿數學收據 R-000901 顯示 8000（源自最初 8 堂發票）；改月結 4000 後舊收據仍可被截到、語意衝突。主任已手動作廢 #901/#903，但無自動對帳 |
| 預期行為 | 模式變更時，舊 invoice/report 標記需重對帳；收據顯示計費模式快照 + 結算期間，作廢者明示 |
| 影響範圍 | 角色 director；資料 Invoice/Payment/payment_reports/收據；G-009 雙真相 |
| **歷史比對** | F2（#149 鄭馨月結收據0堂、#554 收據未列預期堂次、#594、#509 rate_unit）；in-app #172 |
| B1 偵查來源 | 整合 B1：唯讀 SQL（payment_reports #901/#903 voided、Invoice #934 void、StudentClass 2188 現為堂數制5堂5000）+ PaymentReportController::receipt 追蹤 |

## 1. 文件資訊
功能：收據/核帳｜狀態：待業務規則確認｜嚴重度：P1｜角色：director｜關聯 in-app #172、GitHub #934、家族 F2

## 2. 業務背景與影響
課程改計費方式後，舊收據金額與新模式不一致，家長/主任混淆、增加客訴與帳務爭議。修復後：模式變更連動對帳、收據語意清晰、作廢收據明示。

## 3. 範圍
- In Scope：(a) 計費模式變更時連動標記/作廢該課 open invoice 與未結 payment_report；(b) 收據綁 `billing_period` + 顯示計費模式快照與「已作廢/已被取代」狀態。
- Out of Scope：不自動更改任何「實際收款金額」（須人工/業務決策）；不動 PRICING_CONTRACT 費率；不動 G-009 雙真相 OR 邏輯本身。

## 4. RACI
R/A = AI Agent；人類 I（CEO 定義模式變更對帳規則 + 確認本案金額）。

## 4b. Dependencies
可能需 migration（收據/報告加 mode 快照或 billing_period 欄）；視最終設計。

## 5. Acceptance Criteria
### AC-001：模式變更連動
- AC-001-a：課程由堂數制改月結（或反向），系統將該課 open invoice / 未結 report 標記為需重對帳（不靜默保留舊金額）。
### AC-002：收據語意
- AC-002-a：收據回傳含 `schedule_mode` 快照與 `billing_period`；report 非 confirmed/已作廢時，收據端點明確回「已作廢」而非顯示舊金額。

## 6. 功能需求 FR
- FR-001：`StudentClass.ScheduleMode` 變更時觸發連動對帳（標記/作廢）。
- FR-002：`PaymentReportController::receipt` 對非 confirmed/voided report 回明確狀態；confirmed 收據含模式快照 + billing_period。

## 7. 非功能需求 NFR
不適用（流程/顯示）。

## 8. 技術方向
檔案：`StudentClassController`（模式變更點）、`PaymentReportController::receipt`、`Invoice`/`PaymentReport` model。取捨：對帳採「標記 needs-reconcile + 由主任於收費頁確認」半自動（避免 AI 擅自改金額，符合 P0）。

## 8b. Decision Log
2026-06-27：選「半自動標記 + 人工確認」而非「全自動作廢重開」——理由：實際金額屬業務真相，P0 禁止 AI 擅改帳務；業界 SaaS 做法亦為「降級給 credit、升級即時 proration，且發票明示抵扣/新收費」，核心是**透明標示**而非自動吞改。

## 9. 資安與存取控制
涉帳務（敏感）：模式變更/對帳須 super_admin/director 權限，沿用既有 RequireSuperAdmin/角色檢查；審計 log 記錄誰於何時改模式。

## 10. QA 驗收
- Happy：堂數制→月結，舊 invoice 標記、收據顯示新模式。
- Edge：多筆 report、部分已收款、跨月結算。
- Error：越權變更被擋。
### Revert-proof 驗證
- [ ] 測試：模式變更後舊 report 仍可被當有效收據顯示舊金額 = fail（修復後應標作廢/取代）。

## 11. 上線與維運
PR → CI → merge → deploy.yml（若含 migration 走 migrate --force 後部署）。**阻塞**：D1 Actions 額度 + D2 業務規則未定。回滾：含 migration 須 down() 可逆（RULE_MIGRATION_COMPAT）。

## 12. 優先級
P1。`[DEV]`+`[TEST]`+`[REVIEW]`(帳務/權限)+`[OPS]`。

## 13. 風險 / 假設 / 開放問題
- 業界做法（WebSearch 2026-06）：方案變更—升級即時 proration、降級給 prorated credit；**發票須明示舊方案未用額度的 credit 與新收費**；規則一致性優先於彈性（payproglobal/turnstile/recurly/kinde）。「作廢舊發票」屬內部會計程序，需明確 SOP。
- **開放問題（須 CEO 定）**：1) 黃玟睿 6 月數學正確模式與金額？2) 模式變更時舊收款是 credit 沖抵還是作廢重開？3) 是否需 migration 存 billing_period/mode 快照？
- 假設：不自動改實際收款金額。

## 14. Definition of Done
- [ ] FR-001/002 測試綠
- [ ] Revert-proof：作廢 report 顯示舊金額之測試 fail
- [ ] （若 migration）down() 可逆 + migration-dryrun 綠
- [ ] CHANGELOG + AI_REGRESSION（F2 不變式）
- [ ] in-app #172 resolved + 公開回覆（⛔ 待 D1+D2）
