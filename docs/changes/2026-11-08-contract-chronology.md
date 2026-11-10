## 2026-11-08 — fix(course-management): Preserve newest-created contract order across history and working courses
<!-- release-notes: staff_update=staff-2026-11-08-contract-chronology -->
- Correct the outer contract list to match the existing API's newest-created order (ID descending), with stable ties and unknown IDs last. A newly created backdated contract remains first; declared opening dates do not determine contract order.
- Render contiguous working/history segments in that order while retaining historical disclosure, pending reconciliation, original accounting actions, and pagination.
- Keep the declared start as「開課」on working rows and historical cards; missing or backdated starts remain truthful display metadata, and closure dates are not substituted for them.
- This display correction changes no contract quantities, session ownership, attendance, permissions, or financial records. Case reconciliation remains separate.
