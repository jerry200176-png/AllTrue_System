## 2026-10-08 — feat(courses): a paused monthly course's 預排 date can be cancelled one at a time (in-app #340)
<!-- release-notes: staff_update=staff-2026-10-08-paused-projected-cancel -->
- `ensure-projected` 新增 `cancel` 旗標：暫停中（Stop=1、無結案原因、未提前結清）的月結課，預排日期可直接寫成「已取消」堂次；只寫一筆取消列，不扣堂、不動 Charge／Paid／SessionCount／帳單。已有正式堂次的日期回 409、不更動。啟用中的課程帶此旗標一律拒絕。前端課程管理對應顯示「取消這一堂」。Refs #3215
