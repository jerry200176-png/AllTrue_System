# PII data inventory, classification & retention (#889)

> **Status**: v1 inventory (2026-08-07). Scope: tables observed to hold student/parent/staff PII in `backend/app/Models/` and confirmed migrations. Retention decisions below are **proposed defaults**, not yet owner-ratified — treat the retention column as a recommendation pending Founder sign-off, not an enforced policy.

## Why this exists

AllTrue handles minors' (students') personal data plus their parents' contact/identity data. There was no single data map of what PII lives where — this is a first pass, built by reading models/migrations, not a live data audit. It should be re-verified against the actual production schema before being treated as authoritative.

## Inventory

| Table / Model | PII fields | Classification | Who can read it | Proposed retention |
|---|---|---|---|---|
| `Student` | Name, birth date, school, contact notes | Child PII (highest sensitivity) | director/teacher (own campus), super_admin | Retain while enrolled + 2 years post-withdrawal (billing/legal), then archive-and-purge |
| `User` (staff) | Name, email, phone, avatar | Staff PII | self, super_admin, director (own campus) | Retain while employed + statutory payroll record period |
| `ParentSession` / `StudentLineBinding` | LINE user ID (hashed via `SecurityAuditEvent::ref()` in audit trail, but raw in binding table itself), phone used for binding verification | Parent PII | system, super_admin (via DB only — no public read API found) | Purge on unbind/account deletion request; see `PRIVACY_REQUEST_SOP.md` |
| `ParentFeedback` / `ParentFeedbackReply` | Free-text parent messages (may contain PII incidentally) | Parent PII | director/teacher (own campus), super_admin | Retain with the enrollment record; redact on deletion request |
| `bug_reports` / `bug_report_attachments` | Reporter identity, screenshots (may capture on-screen student names/data) | Mixed (staff PII + incidental child PII in screenshots) | reporter, super_admin | Retain per `docs/GUIDE_BUG_CLOSURE_GATE.md` cadence; screenshots are the highest incidental-PII risk item in this table |
| `StudentIdentityGroup` / `StudentIdentityMember` / `StudentIdentityAuditLog` | Cross-campus identity linkage (student matching) | Child PII | super_admin only (per existing cross-campus isolation work, #1401 family) | Follows `Student` retention |
| `PaymentReport` / billing tables | Parent/guardian name on receipts, payment amounts | Financial PII | director (own campus), super_admin | Retain per tax/accounting statutory period (recommend confirming with an accountant — not a technical decision) |
| `security_audit_events` (#1420) | Hashed references only (HMAC), no raw PII by design | Not PII (by construction) | DB-credential-only, no API | 180 days (already configured, see `SECURITY_AUDIT_EVENTS.md`) |

## What this inventory does NOT cover (explicitly out of scope for this pass)

- Third-party processors (LINE, Sentry, GitHub, hosting) — each holds a copy of some of the above; a vendor-DPA review is a separate, larger task.
- Backup/Google Drive manifest retention (`docs/OPERATIONS_RUNBOOK.md §P`) — backups likely retain PII longer than the live DB; not reconciled here.
- Actual production row counts / real retention audit — this is a schema-level map, not a data-level audit.

## Next steps (for owner decision, not auto-executed)

1. Ratify or adjust the retention column above.
2. Decide whether backup retention needs a separate PII-aware policy.
3. Feed this into `PRIVACY_REQUEST_SOP.md` (#903) as the lookup table for "where do I search when a parent asks what data we hold on their child."
