## 2026-11-06 — fix(schedule): count-mode contracts no longer drop a Sunday slot stored as 0 (#4994)
<!-- release-notes: staff_update=staff-2026-11-06-count-mode-sunday -->
- `ContractSessionSchedule::buildSessionsForCount()` 先用 `isoWeekday()` 把舊格式的星期日（0）轉成 7，與 `buildSessionsFromWeeklySchedule()` 一致；沒填星期的時段仍略過。原本星期日的時段會被跳過，排出的課比買的堂數少。
