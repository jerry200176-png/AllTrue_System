## 2026-11-09 — test: restore normal learning visual fixture pagination
<!-- release-notes: silent_ship=silent-2026-11-09-learning-visual-pagination-fixture -->
<!-- silent-reason: synthetic test fixture only; no production behavior or staff workflow changes -->
- Return the existing controller's empty OFFSET pagination contract in the normal learning pilot, so its strict page reader shows the normal empty queue instead of a load error.
- Add real Vue browser regressions at 375px and 1280px for the normal empty queue and existing error state. Keep the production parser, APIs and PNG baselines unchanged.
- Found while reviewing PR #5141 visual artifacts; the separate students/course-settings baseline differences remain outside this fix.
