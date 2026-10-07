## 2026-10-07 — fix(calendar): 已代課的堂可從行事曆改回正班老師 (in-app #376)
<!-- release-notes: staff_update=staff-2026-10-07-restore-contract-teacher -->
- 行事曆開代課選擇器時同時帶入合約老師與這一堂的老師（`substituteTeacherIds()`），已代課的堂會出現「回正班老師」。點選與拖曳兩條路徑都修正。
