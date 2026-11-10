## 2026-11-09 — fix(agents): retain CI progress in continuation summaries
<!-- release-notes: silent_ship=silent-2026-11-09-task-reconcile-ci-progress -->
<!-- silent-reason: development-only read-only observation summary; no approval, permission or production behavior changes -->
- Report check-state changes at an unchanged PR head, including failure, recovery and success-to-skip transitions. Normalize check ordering to avoid false progress.
- Extend the existing isolated regression suite from eight to ten tests; existing CI already runs this suite. Preserve private checkpoint, atomic-save and no-action-replay boundaries.
