## 2026-11-12 — fix(ops): retain safe diagnostic counts for failed bug verification
<!-- release-notes: silent_ship=silent-2026-11-12-failed-scheduler-evidence -->
<!-- silent-reason: Internal bounded scheduler diagnostics only; no UI, workflow, thresholds or data change. Failed runs stay unhealthy. -->
- A failed bug-verification run may retain a complete allowlisted projection of condition codes, states and counts from its existing output. Missing, unsafe or unsupported output stays unavailable; raw logs remain private and unchanged. This does not establish recovery of the failing nightly check.
