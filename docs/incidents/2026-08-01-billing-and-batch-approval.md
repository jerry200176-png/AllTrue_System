# 2026-08-01 帳單金額與批次核准事件檢討

## 摘要

兩個 in-app 回報同時暴露了「畫面看似同一筆資料，實際使用不同真相來源」的問題：

- #213：木柵學生高瑞璞的 2026-07 國文課程，課堂顯示 5 堂 × NT$1,500 = NT$7,500，但帳單列表沿用歷史 `Invoice.TotalAmount = NT$6,000`。
- #212：使用者勾選 15 筆後，前端未把選取 ID 傳給批次核准 API；後端依頁面/分校篩選重新查詢，因而核准了 63 筆。

## 根因

1. 月結課程的預存 `StudentClass.Charge` / `Invoice.TotalAmount` 與 `ClassSession` 實際堂次沒有由同一個 read model 統一提供。
2. 帳單 API 沒有回傳「原始金額、計算金額、計算來源、對帳差異」，UI 因而只顯示一個容易被誤信的數字。
3. 批次 mutation 的前端選取狀態與後端查詢範圍是兩套契約；後端允許沒有 `ids` 的請求，造成 fail-open。
4. 驗收只檢查 API 成功，沒有同時驗證「勾選數量 = 實際變更數量」及「課堂、帳單、繳費入口金額一致」。

## 已完成的修正

- `MonthlyBillingService::summarizePeriod()` 明確依帳單月份計算已上課堂，不使用今天的月份。
- 未付款的月結帳單若發現差異，讀取 API 顯示實際堂次計算金額，同時保留 `stored_total_amount`、`computed_total_amount`、`amount_source` 與差異旗標；沒有直接覆寫歷史資料。
- 課程管理的帳單金額顯示差異警示，讓主管能看到「實際堂次」與「原帳單」兩者。
- 批次核准必須提供明確、去重的 `ids`；選取資料與權限/狀態不完全一致時，整批 422 且不寫入任何資料。
- 增加兩個回歸測試：月結帳單 5 堂應顯示 NT$7,500；批次核准只能變更明確選取的那一筆。

## 尚未自動做的事

本次不直接改寫 production `Invoice` 歷史列，也不刪除或沖銷資料。上線後若仍需要正式更正帳單，必須先產生 read-only repair dry-run、完整備份、列出影響帳單/付款/收據，並由董事長另行核准；已付款帳單應以調整/沖銷文件處理，不以覆寫金額掩蓋稽核軌跡。

## 防再犯驗收門檻

- 每個批次 mutation 回應的 `approved` 必須等於 request `ids` 數量；不一致即拒絕。
- 每個月結帳單 read model 必須能回答：期間、堂數、單價/計算來源、預存金額、計算金額、差異狀態。
- 課程頁、帳單列表、繳費入口與對帳頁使用同一個 billing DTO；禁止各頁自行從 `Charge` 推導。
- production smoke 必須涵蓋 #212 的選取數量與 #213 的 5 堂/NT$7,500 案例，並保存 API 回應與截圖證據。
- 每次 resolved 前必須有測試結果、部署 SHA、health check、業務唯讀驗證與 in-app 回報更新。

## 架構與 UX 後續改善

- 保持 Laravel modular monolith，先建立 billing read model / service 邊界，不進行高風險重寫或拆微服務。
- Billing UI 採用「本期 5 堂 × NT$1,500 = NT$7,500」的公式化呈現，並提供差異 drill-down；付款按鈕在差異未確認時應導向對帳流程。
- 批次操作固定顯示「已選 N 筆」，送出後顯示「要求 N / 實際 N」；server-side 只接受 explicit selection。
- 以 Stripe 的 idempotency、Invoice Ninja/ERPNext 的單據生命週期、Primer/Carbon/Fluent 的可及性與表格設計、Google SRE 的 blameless postmortem/可驗證 action items 作為後續 review 基準。

## Rollback

程式回滾只回到本次部署前的已驗證 main SHA；不使用 reset/rebase，不刪除任何 worktree。資料層不需 rollback，因為本次只增加讀取計算、欄位與 fail-closed 驗證，沒有 production DB write。
