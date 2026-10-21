## 2026-10-21 — 「進行中課程」改由伺服器單一判定（in-app #427）
<!-- release-notes: staff_update=staff-2026-10-21-inapp-427-active-course-rule -->

- `StudentClassActivity` 是唯一判定：`student-classes` 每列帶 `history_reason`／`is_active_course`，學生管理、課程管理直接讀；「已有進行中課程」提示與建課重複守門呼叫同一個 `evaluate()`（同一套自我校正剩餘堂數與收款判定，部分時數以分鐘為準）。已結案／已轉正的試聽、已上完且已收（或免費）的課、已停課的課不再列入提示、不再擋建課（守門放寬）；剩餘堂數與課程卡相同。老師的預檢只看自己可見的課程（`StudentClass::visibleToTeacher`），不透露他人課程。課程管理仍把「待對帳結案」留在工作清單。未改收費或扣堂規則。
