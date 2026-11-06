## 2026-11-06 — feat(exams): set 段考 dates once per school (#4970 step 1)
<!-- release-notes: staff_update=staff-2026-11-06-school-exam-periods -->
- New table `school_exam_periods` (additive). 段考成績 gets a 各校段考日期 tab: directors set 段考一/二/期末 date ranges once per school per semester (optional grade scope); the school list comes from active students' school names, with spelling variants grouped. Teachers read only. New `GET/POST exam-periods`, `PATCH/DELETE exam-periods/{id}`, `GET exam-periods/schools`, `GET students/{id}/exam-periods`. All campus-scoped.
