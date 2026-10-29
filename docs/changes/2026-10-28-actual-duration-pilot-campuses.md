## 2026-10-28 — feat(billing): 「依實際上課時長換算」只開放新莊分校、新莊中平分校新建（#3834）
<!-- release-notes: staff_update=staff-2026-10-28-actual-duration-pilot-campuses -->
- 建課或把既有課改成「依實際上課時長換算」時，後端只接受試辦分校（新莊分校 11、新莊中平分校 13）；其他分校選這個選項會看到「此分校尚未開放」，請改用「每次上課扣一堂」。名單由 `PERF_ACTUAL_DURATION_CAMPUS_IDS`（預設 `11,13`，`*` 全開，空白全關）決定。
- 已經是「依實際上課時長換算」的課不受影響：照常編輯、照常扣堂，沒有任何餘額改變。
- `actual-duration-activation` 的唯讀 `verify_backend` 多印出 `effective-campus-ids`，部署後用來核對名單。
