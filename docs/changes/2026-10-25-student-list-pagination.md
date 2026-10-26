## 2026-10-25 — fix(students): load complete filtered lists
<!-- release-notes: staff_update=staff-2026-10-25-student-list-pagination -->
- Read every bounded page of matching students while retaining campus, search, grade and status filters. Reject incomplete responses and ambiguous full compatibility pages through the existing retry state. Refs #4490; applies the paged-read completeness guard in regression lesson R153.
