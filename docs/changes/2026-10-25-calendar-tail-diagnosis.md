## 2026-10-25 — fix(ops): verify the reported calendar tail without changing lessons
<!-- release-notes: silent_ship=silent-2026-10-25-calendar-tail-diagnosis -->
<!-- silent-reason: Internal case-bound read-only evidence; no staff-facing behavior change. -->
- For the existing calendar cancellation/leave case, read the current tail preview and student-slot guard in a verified read-only transaction. Target, campus, preview or read-mode failures make the evidence incomplete; plans and conflict identifiers remain encrypted. Refs #4496.
