# 月結已上堂次收費與續約期間修正

SourceRef: alltrue:bug_report:369，附件 metadata 312 已在私有證據中檢視，公開文件不包含學生個資或截圖。Founder 在既有月結修正任務明示「按確認已上堂數」及要求改善續約日期／金額操作。R3/T3；只準備實作、測試與 review，正式啟用與個案資料修復各需受保護核准。

Planner / Integration Owner：Codex，agent-start session 0abe5a2bd5664b64b1a312685bb272f7，基準 34711fad5ce41b2658b6d9c4f36c56c6b1dc8788。沿用月結核對與修正計畫；本修訂處理 in-app 新證據顯示的未上課預估收費及隱藏開始日。

## 證據與原因

- MonthlyBillingService 無 billable sessions 一律 fallback 到 stored Charge，把明確只有 scheduled／cancelled／leave 的期別也顯示為預排費用。缺乏任何歷史堂次則無法確認實際費用，不應自動把既有合約改為零。
- InvoiceAmountReconciliationService 已是未繳且 net applied=0 的 readonly 金額權威；Paid/partial 金額保持 audit truth。本修正只增加明確 zero-confirmed evidence 的投影，不改寫既有 Invoice/Payment 或已收款歷史。
- renewMonthly 起日是 source EndDate+1，preview 的 proposed start 卻顯示來源 StartDate；preview billing_period 從 newEnd 取值，execute 從 newStart 取值，跨月不一致。settlement_day=31 以 startOfMonth+30 天計算，短月溢到下月。
- 來源已有合約日期外的 attended/completed/late 時仍可續報，既有已上堂次不會被移轉，讓使用者以為已拆約。須引導受控核對；不改排課鏈或自動移轉。

## 邊界與選擇

重用 MonthlyBillingService、InvoiceAmountReconciliationService 與既有 renewal-preview/renewal-confirm/renew-monthly。抽出小型 MonthlyRenewalPeriodService，統一新期起日、月份、月底 clamp 及越界已上課 blocker；不創造第二個 pricing authority。只顯示未收款月結的實際 confirmed fee；明確零堂不得以预排價登記付款。未知／空歷史維持 legacy fallback 並由現有核對介面處理；共用方案、堂數制、paid/partial invoices 及跨月明訂服務週期不重算。

原預排 Charge／discount 仍是預估，續約 modal 必須改為預估金額文案；不得標為實收。新期起訖及 billing month 在確認前顯示，載入失敗／尚在載入／blocked 時不能送出。StartDate 保留既有連續期間算法，不強制所有月結合約從每月一日開始，也不默默改寫學生歷史。

## 驗收、發布與恢復

1. 有 scheduled/cancelled 等明確非 billable 堂次、零 confirmed 的月結：readonly summary、未繳 Invoice、帳單與收款登記一致為零，未上課不能登記預估款；完全缺歷史、count/package/paid/partial 口徑維持。
2. 後續 attended 才增加 actual fee，取消／請假不收費；資料投影不改寫付款或帳單。既有 pending report 確認前亦須重新檢查，超過已確認堂次應收時拒絕且不寫 Invoice/Payment/Paid；部分付款仍可，明訂跨月服務週期沿用 Invoice authority。
3. 新期 preview/execute 的 start/billing month/due date 一致，短月最後一天不溢出。越界已上堂次時 readonly preview blocked，直接 renew-monthly 同樣拒絕且無新合約／取消資料寫入。
4. Modal 顯示確切期間、月份、估算標籤；不同日期的舊 preview 不可啟用提交；errors/blockers 提供可讀原因。桌面／手機檢視及對應 component regression。
5. 隔離 PHP/Vue tests、PHPStan、完整 CI；部署 SHA、受影響 production path 分開記錄，未部署不標記 in-app resolved。無 migration；程式回復以既有部署 rollback，個案金融修復保持另一受控 PR／不可變 Manifest。

相關工程準備：PR #3326 核對介面；既有合約帳務更正的替代 PR（supersedes #3327）。正式資料尚未修正。
