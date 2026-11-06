## 2026-11-06 — feat(payroll): part-time 行政加給 manual line (#4971, in-app #508)
- One manual amount (0-100000) plus required reason per part-time teacher per month; own line in the summary and CSV, added to the teacher total. Refused (422) once the month is locked. Create/edit/delete write a security audit event. New additive table `parttime_payroll_admin_bonuses`.
