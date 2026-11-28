## 2026-11-28 — chore(nightly): 每天數一次月結「可能重複收費」和「可能漏收」 (#5708)
<!-- release-notes: silent_ship=silent-2026-11-28-nightly-monthly-billing-candidates -->
<!-- silent-reason: 只新增夜間唯讀檢查（只回報、不擋），不變更產品畫面、資料或操作。 -->

- 夜間檢查新增 `monthly_period_billed_twice`：月結合約，同一位學生、同一科、同一個月份出現在兩張以上有效帳單（金額大於 0、未作廢）。
- 夜間檢查新增 `monthly_lessons_without_bill`：已結束的月份（2026-08 起）有上過課，卻沒有標記該月份的有效帳單。免費、家教、確認不收、已退費、套裝合約不算。
- 兩個都只回報筆數，由人判斷；永遠不擋上線，不改任何資料。
