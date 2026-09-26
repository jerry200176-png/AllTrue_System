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
| Search | `files.*` / array file validation rules |
| Hits | **1 reachable endpoint** — `BugReportController` previously used `attachments.*` |
| Mitigation | Individual `attachments` entries now validate under fixed attribute `attachment`; wildcard file validation is not used |
| Related upload rules | `ImportController` `file`; `AuthController` `avatar`; `ChatController` `file` — concrete rules; BugReport now validates each uploaded object individually |
| Production exposure | Application mitigation implemented, **pending exact-SHA deployment verification**; framework remains on the vulnerable 8.x line until #977 |
| Recommended action | Keep app-level mitigation and regression test; no blind L10 bump. Durable fix remains Laravel migration |
| Deadline | Re-check on any new multi-file upload validation; framework migration #977 |

## Explicit non-actions

- Do not major-upgrade Laravel solely for #3/#5; #3 now has an app-level mitigation while #977 remains the durable fix.
- Do not mark Dependabot alert #3 “fixed” without a framework version change; keep it open with tracked residual risk until #977.

## 2026-09-12 continuation evidence (not yet deployed)

- Reused PR #2764 exact head `235857f4138fe4bf0a6884df41c427e3622c2e85` product diff; original branch/worktree retained. Follow-up agent-start session `8a8818ed4a6a4a58bfeba6ef659ba27b`, base `41d959b9c9f298c66a2efbd19307be3d49f0c381`.
- Independent review found that reading only the file bag silently skipped non-file and mixed entries. Two regression cases reproduced HTTP 201 instead of the required 422 before correction. Validation now reads all merged attachment inputs, while still using a fixed validation attribute; invalid requests create no report or attachment rows. Original MIME, 5 MiB and five-file limits remain unchanged.
- Maintainer advisory: [Laravel file validation bypass](https://github.com/laravel/framework/security/advisories/GHSA-78fx-h6xr-vch4). This is an endpoint mitigation, not a framework upgrade or evidence of exploit activity.
- R2/T2 reversible validation repair; no identity/role/credential policy change, no historical repair, no alert dismissal. Rollback via normal revert/deploy; retains stored reports and attachments.
