## 2026-10-06 — fix(students): 學生列表月結續約須先完成期間預覽才能送出
<!-- release-notes: staff_update=staff-2026-10-06-renewal-preview-gate -->
- 學生列表的「月結續約」與課程管理一致：新一期期間預覽未完成、被擋下或日期已變更時，不能送出，並提示「請先完成新一期期間預覽與月結核對。」（共用 `canSubmitMonthlyRenewal`；後端不變）。
