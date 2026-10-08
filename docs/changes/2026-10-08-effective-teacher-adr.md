## 2026-10-08 — docs(arch): Effective Teacher ADR + batch resolver
<!-- release-notes: silent_ship=silent-2026-10-08-effective-teacher-adr -->
<!-- silent-reason: 只新增架構決策紀錄、名詞與批次查詢函式，畫面與計算結果尚未改變；修正科目數的 PR 另外公告。 -->
- 新增 `docs/adr/ADR-EFFECTIVE-TEACHER.md` 與 GLOSSARY「Effective Teacher」：誰實際上了這一堂，只由 `SubstituteScheduleService::teacherForOccurrence()` 判斷；薪資、統計、評量與行事曆都讀它。
- 新增批次 `teachersForOccurrences()`（同一規則，一個 occurrence 只算一次）。
