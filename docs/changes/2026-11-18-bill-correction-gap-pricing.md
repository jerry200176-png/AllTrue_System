## 2026-11-18 — fix(billing): 更正帳單漏收課只加開立後才新增的課，折扣或固定價帳單拒絕
<!-- release-notes: silent_ship=silent-2026-11-18-bill-correction-gap-pricing -->
<!-- silent-reason: 更正帳單仍預設關閉（分校清單為空），這是啟用前的後端修正，教職員看不到變化。 -->

- 月結帳單的範圍內只計入開立之後才建立的課；開立時就存在的課加起來對不上帳單金額（折扣、免費、固定價）就拒絕，改請總部處理。
- 重送同一個更正，回覆只依已存的更正紀錄；預覽過期但已收狀態對不上時回 409。

Release-Impact: internal
