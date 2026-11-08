## 2026-11-08 — fix(course-management): Preserve chronological contract order across history and working courses
<!-- release-notes: staff_update=staff-2026-11-08-contract-chronology -->
<!-- Pending approved staff copy; no announcement YAML has been added. Release coverage remains blocked until approval. -->
- Order the loaded contracts within each student group by declared first class date, oldest first, with stable ID ties and unknown dates last.
- Render contiguous working/history segments in that order while retaining historical disclosure, pending reconciliation, original accounting actions, and pagination.
- This display correction changes no contract quantities, session ownership, attendance, permissions, or financial records. Case reconciliation remains separate.
