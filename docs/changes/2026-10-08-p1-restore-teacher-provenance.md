## 2026-10-08 — fix(calendar): 「回正班老師」只出現在真的代課的堂次 (#3780 P1)
<!-- release-notes: staff_update=staff-2026-10-08-restore-teacher-provenance -->
- 後端「回正班老師」先確認這堂有未解決的代課通知（`substitute:<堂次 id>`），沒有就回 409，不動排程、評量老師與授課堂數；換合約老師後釘在前任老師的已上課堂次不會再被改寫。
- `class-sessions` 輸出 `substitute_notice`／`contract_teacher_id`；行事曆與課程管理的代課選擇器只在真代課時顯示「回正班老師」，回正班後顯示「已回復正班老師」且不再提供代課撤銷；只有堂次、沒有合約列的課也帶正確合約老師。
