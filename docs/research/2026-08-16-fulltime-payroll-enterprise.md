# 正職薪資下一包：期間鎖定後的加給／調整／核准

**Date:** 2026-08-16. **Decision:** TD-077 行政加給 + TD-078 底薪畫面先上；現金加扣款下一 PR。主任補登／確認，總部核准。R2。

鎖定拒 `review_required`；已鎖月拒改底薪。主任底薪 `pending`，總部寫入立即 `approved`。扣除案件已雙人核准。行政加給 0–10% 進倍率。

Comparables（2026-08-16 **documented**；Browser Run 429 故 live **attempted**）：Workday 期間關閉／retro 進當期／NRPPT；Gusto Request approval 鎖編輯；Odoo 確認後 Cancel→Draft；Frappe HR Additional Salary（star `frappe/erpnext`；**source-code verified** `hrms@4eecaef6983049899e78a9e845beff5856eac893` submit/cancel、`docstatus==1`）。GPL 不複製程式。既有扣除案件為核准 shape。

未核准加給 → review 擋鎖定；倍率 cap 10%；鎖定月 422。主任改底薪顯示待核准；總部改了立刻計入。
