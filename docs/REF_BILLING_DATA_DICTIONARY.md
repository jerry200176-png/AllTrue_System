# 帳務字典（Billing Data Dictionary）

> 資料治理 G1（Founder 2026-11-16，#4991）。一個數字只有**一個名字、一個意思、一個來源**。
> 畫面不自己算；要顯示就讀下表的來源。狀態用詞（未繳／已收／需核對…）另見 `docs/plans/2026-10-30-billing-copy-ux-spec.md` §2。

## 數字

| 名字 | 意思（一句） | 唯一來源 | 誰能改、怎麼改 |
|---|---|---|---|
| **合約金額** | 堂數制合約講好的總學費 | `StudentClass.Charge` | 主任：合約變更（`ContractAmendmentService`，留紀錄） |
| **單價** | 一堂（或一小時）多少錢，以**上課那天**生效的為準 | `StudentClassPricingService::forDate()` | 主任：改單價（`student_class_pricing_amendments` 留紀錄，不覆蓋舊的） |
| **應收** | 這張帳單要收多少 | `InvoiceAmountReconciliationService::resolve()` 的 `total_amount`（存在 `Invoice.TotalAmount`） | 不能直接改數字。開錯：作廢帳單再開；第 2 步上線後用「更正帳單」 |
| **已收** | 這張帳單實際收到多少＝收款紀錄加總，扣掉撤銷與退費 | `Payment` 的列（`Invoice::netPaidSql()`） | 主任：登記收款、撤銷收款。每一筆都留著，不刪 |
| **未繳** | 應收 − 已收，最小是 0 | 一張帳單：上面兩個相減。一份合約：`ContractMoneyVerdict`（`BillingPayableResolver` 的 `outstanding`） | 不能改；它是算出來的 |
| **預估** | 月結這個月**還沒定案**前的金額＝已上堂數 × 當日單價 | `MonthlyBillingService::summarizePeriod()` | 不能改；改上課紀錄或單價它就跟著變 |
| **餘額** | 先收的錢還沒抵掉的部分，留到下個月抵 | 還沒有。第 3 步（月底定案）新增一張只增不改的餘額帳 | — |
| **繳費狀態** | 這份合約現在是未繳、繳了一部分、待確認入帳、已收、免費、不收了、還是需核對 | `ContractMoneyVerdict::forCourse()` | 不能直接改；改上面的數字它就跟著變 |

## 四條規則

1. **錢的證據是收款紀錄。** `Invoice.Status`、`Invoice.PaidAmount`、`StudentClass.Paid` 都只是記號，會過期；對不上時以收款紀錄為準（Founder Q2、R28）。
2. **0 元帳單不是收款證據**（#3991）。真的免費要標「免費／贈送」或「全額折扣」。
3. **已定案的帳單金額不會自己變。** 要改就走作廢或更正，留下原因和誰改的。
4. **畫面不自己算錢。** 前端只有「建課／續課預覽」可以用 `coursePricing.js` 試算；其他一律顯示伺服器給的數字。

## 不要再用的講法

| 不要用 | 統一為 |
|---|---|
| 未收、未付、未結清、尚未收款 | 未繳 |
| 已繳、已付、已收清 | 已收 |
| 應繳、應付 | 應收 |
| 估算、試算（指月結未定案金額時） | 預估 |

目前程式裡還有舊講法（2026-11-17 盤點：畫面檔「未收」28 處、「應繳」10 處、「估算」8 處）。改一個畫面就把那個詞加進 `frontend/src/components/__tests__/BillingVocabularyGuard.test.js`，讓它不會再回來。

## 怎麼知道資料乾不乾淨

超級管理員的「個人資料 › 安全性」有「帳務資料品質」表（`GET /api/v1/admin/billing-data-quality`，#5395）：每個分校六種問題各幾筆。數字往下走才算有改善。
