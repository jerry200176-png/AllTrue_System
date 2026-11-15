## 2026-11-15 — fix(payroll): 拿掉手動教師總倍率（P0）
<!-- release-notes: staff_update=staff-2026-11-15-fulltime-no-manual-multiplier -->
- 正職薪資要件不再接受「手動輸入教師總倍率」：倍率一律由各項加總（假日、平日下午、正課 20 科、行政加給、特殊表現、扣除）。以前手動填的總倍率會整個蓋掉系統算的、清不掉、還會一直帶到之後每個月。
- 未鎖定的月份不再套用舊的手動總倍率；已鎖定的月份照鎖定當時的數字，不變。舊資料留在資料庫，沒有刪。
- 要調整假日或平日下午倍率，改用「倍率調整」（填原因與起訖日，主任確認、總部核准）。
- 移除兩支 API：`POST finance/teacher-eligibility/salary-profiles/multiplier`、`POST finance/teacher-eligibility/salary-profiles/{id}/approve`。
