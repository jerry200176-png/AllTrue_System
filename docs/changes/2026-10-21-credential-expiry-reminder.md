## 2026-10-21 — ci(security): weekly credential expiry check
<!-- release-notes: silent_ship=silent-2026-10-21-credential-expiry-reminder -->
<!-- silent-reason: ops workflow change; no product UI change -->

`secret-rotation-reminder.yml` gains a weekly job that reads `docs/ops/credential-expiry.yml` (names, owners, `expires_on`, where to rotate; no values) and opens one issue per credential 14 days before it expires. Undated entries are listed in the run summary. Sunrise Cafe needs the same registry (separate PR).
