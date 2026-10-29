## 2026-10-29 — feat(billing): 把合約剩餘堂數轉給同分校的手足，依已繳金額等比例搬走（in-app #412，後端）
<!-- release-notes: silent_ship=silent-2026-10-29-sibling-transfer-backend -->
<!-- silent-reason: 只有後端 API，畫面（主任的「轉給手足」按鈕）在下一個 PR 才出現，教職員這次看不到差異 -->
- 新增 `POST /api/v1/student-classes/{id}/sibling-transfer`（僅主任／超級管理員，限同分校）：把一份已全額繳清的堂數制合約的剩餘堂數，轉成手足名下的一份新合約，金額 = 已繳金額 ×（轉出堂數 ÷ 總堂數），四捨五入後兩邊加起來剛好等於原本已繳的錢；錢走既有轉課的 transfer_out / transfer_in 成對帳款，原收據不動。
- 新增 `contract_sibling_transfers` 一張新表（只新增、無改動既有表）記錄來源合約、新合約、金額、操作者；同一個送出代碼重送不會重複轉，同一份合約只能轉一次。
- 回滾＝還原這個 PR；新表留著不影響舊版程式。
