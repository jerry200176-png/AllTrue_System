# 正職薪資下一包：期間鎖定後的加給／調整／核准

**Date:** 2026-08-16  
**Decision:** 行政加給（TD-077）與底薪待核准畫面（TD-078 前端）先上線；現金加扣款下一 PR。  
**Roles:** 主任補登／確認；總部 `super_admin` 核准。 **Risk:** R2。

## Current AllTrue (locally verified)

鎖定拒 `review_required`；已鎖月拒改底薪。底薪 `pending` 才寫入、僅 `approved` 進結算；核准 API 已有、畫面先前當成立刻入帳。扣除案件已是主任確認→總部核准。行政加給 0–10% 尚未進倍率。

## Comparables

- Workday Payroll 官網：期間關閉、retro 差額進**當期**、NRPPT、Final Approval。[Process](https://doc.workday.com/admin-guide/en-us/payroll/payroll-processing/process-payroll/dan1370796934594.html)／[Retro](https://doc.workday.com/admin-guide/en-us/payroll/retroactive-payroll/process-retroactive-payroll/wjo1553036080961.html)（2026-08-16，**documented**）。不導入完整 retro／稅務。
- Gusto [Approvals](https://support.gusto.com/article/240829150046240/set-up-approvals-for-payroll-for-admins)：Request approval 鎖編輯至核准／拒絕；activity log（2026-08-16，**documented**）。FAQ 與同頁 off-cycle 敘述衝突，以「lock until approved」為準。
- Odoo 19 [Payslips](https://www.odoo.com/documentation/saas-19.3/applications/hr/payroll/payslips.html)：確認後金額不可改，須 Cancel→Draft（**documented**）。
- Frappe HR [Additional Salary](https://docs.frappe.io/hr/additional-salary)（star: `frappe/erpnext` 生態）：獨立 submit/cancel 文件、`payroll_date` 進當期。**Source-code verified** `frappe/hrms@4eecaef6983049899e78a9e845beff5856eac893`：`additional_salary.py` validate／on_submit／on_cancel；測試 submit/cancel；json `is_submittable`；查詢 `docstatus==1`。GPL，不複製程式。
- 既有 AllTrue 扣除案件：雙人核准 shape。crater／invoiceninja／filament stars：不作薪資期間關閉來源。

Live Browser Run 對上述頁 **HTTP 429**（2026-08-16T14:02Z）→ live 層 **attempted/unavailable**。

## Adaptation

未核准加給 → policy `review` 擋鎖定；已核准 rate 疊倍率 cap 10%；鎖定月寫入／核准 422。底薪列顯示 pending、總部可核准。現金調整下一 PR（獨立列、鎖定後只在快照）。不加全勤／勞健保。同一老師多職務 **加總 cap 10%**。
