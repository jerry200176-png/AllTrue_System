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
| Production exposure | **Mitigated in application code**; framework remains on the vulnerable 8.x line until #977 |
| Recommended action | Keep app-level mitigation and regression test; no blind L10 bump. Durable fix remains Laravel migration |
| Deadline | Re-check on any new multi-file upload validation; framework migration #977 |

## Explicit non-actions

- Do not major-upgrade Laravel solely for #3/#5; #3 now has an app-level mitigation while #977 remains the durable fix.
- Do not mark Dependabot alert #3 “fixed” without a framework version change; keep it open with this mitigation and accepted residual risk until #977.
