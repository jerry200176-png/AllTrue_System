# Dependabot inventory — alerts #3 / #5 (2026-07-18)

**Scope:** AllTrue `laravel/framework` 8.x-dev. Complements `DEPENDABOT_TRIAGE_2026-07-18.md`.

## Alert #5 — Temporary Signed URL path confusion (GHSA-crmm-hgp2-wgrp)

| Item | Result |
|------|--------|
| Search | `temporarySignedRoute`, `temporaryUrl`, `URL::temporarySigned` under `backend/app` + `backend/routes` |
| Hits | **0** |
| Production exposure | **None found** for local-disk temporary signed URLs |
| Recommended action | Document residual risk; no code change. Durable fix = Laravel upgrade (#977) |
| Deadline | Re-check on any new file-download signing feature; otherwise next framework migration |

## Alert #3 — `files.*` validation bypass (GHSA-78fx-vch4 / CVE-2025-27515)

| Item | Result |
|------|--------|
| Search | `files.*` wildcard validation rules |
| Hits | **0** |
| Related upload rules (explicit, not `files.*`) | `ImportController` `file`; `AuthController` `avatar` image; `BugReportController` `attachments.*`; `ChatController` `file` — all use concrete `file`/`image`/`mimes` rules |
| Production exposure | **Low** for this specific advisory (wildcard `files.*` not used) |
| Recommended action | No blind L10 bump. Keep explicit rules; add regression note if introducing array file uploads |
| Deadline | Re-check when adding multi-file array validation; framework migration #977 |

## Explicit non-actions

- Do not major-upgrade Laravel solely for #3/#5 given zero reachable hits.
- Do not mark Dependabot alerts “fixed” without version change; keep open with this inventory as accepted residual until #977.
