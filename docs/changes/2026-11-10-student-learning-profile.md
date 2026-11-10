## 2026-11-10 — feat(students): existing-data learning profile (#4970 step 3a)
<!-- release-notes: staff_update=staff-2026-11-10-student-learning-profile -->
- Expand the existing student timeline entry into a read-only 學習檔案 with actual progress/content, attendance, unit assessments and published/closed school exam scores. Subject/date filters, source limits and the next school exam use existing records only; no schema, endpoint or business writes.
- Keep campus/student authorization and parent refusal; teacher school scores additionally require an active course, matching canonical subject and visible grade/round. Missing teacher identity fails closed. Cancelled teacher lessons remain hidden; directors see their cancellation marker for review.
- Reuse the shared Dialog/Tabs/Card components with large-text layouts, keyboard navigation and focus return. Unavailable school dates do not erase usable class/score history.
- This is a populated first slice of #4970, not the template library, learning plans, Gantt, unit completion or full-history KPI delivery. In-app #505 remains resolved independently; no production writeback or reporter acceptance is inferred.
