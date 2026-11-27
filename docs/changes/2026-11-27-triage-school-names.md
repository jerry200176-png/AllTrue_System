## 2026-11-27 — 唯讀金鑰可讀「分校學生就讀的學校名稱＋人數」
<!-- release-notes: silent_ship=silent-2026-11-27-triage-school-names -->
<!-- silent-reason: Read-only agent endpoint; no screen or staff workflow changes. -->

- 新增 `GET /api/v1/triage/school-names?campus_id=`（Founder GO 2026-11-27）：回傳該分校在學學生的學校名稱與人數，以及已登記的段考日期。不含任何學生資料；不像校名的文字只計入 `withheld_student_count`，不回傳。開關 `TRIAGE_SCHOOL_NAMES_ENABLED`。
