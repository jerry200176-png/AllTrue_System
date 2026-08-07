---
owner: jerry (CEO)
review_cycle: quarterly
last_reviewed: 2026-08-08
---

# PII Data Inventory & Classification

> Addresses [#889](https://github.com/jerry200176-png/AllTrue_System/issues/889). Reference model: GDPR Art. 30 records-of-processing + data-minimization principle, PDPA data inventory practice. This is a **data map**, not a policy engine — enforcement (masking, retention jobs) is tracked separately per row below where not yet implemented.

## 1. Field-level inventory (core tables)

Generated from the live production schema (`Schema::getColumnListing()`, 2026-08-08), not from memory — column names below are exact.

| Table | PII columns | Classification | Who can access (today) | Notes |
|---|---|---|---|---|
| `Student` | `name`, `Phone`, `parent_name`, `parent_phone`, `RFID`, `LineID`, `TelegramID`/`TelegramID1`/`TelegramID2`, `Notify_Token`, `SchoolName` | **High** (child + guardian identity/contact) | `director`/`teacher` scoped to `auth_campus_ids`; `super_admin` unrestricted | `notes` is free-text and unclassified — may contain incidental sensitive detail (medical/behavioral); no automatic scan today |
| `User` | `Name`, `LoginName` (often an email/phone), `phone`, `PSW` (hashed), `pin_hash`, `LineID`, `TelegramID*`, `AvatarUrl`, `Notify_Token`, `RfidLegacy` | **High** (staff identity + credentials) | Self + `super_admin` | `PublicAvatarUrl::forBrowser()` already prevents raw storage-path/URL leakage (CLAUDE.md §頭像) — the one field with an enforced masking layer today |
| `Teacher` | `T_Name`, `Phone`, `RFID`, `LineID`, `TelegramID*`, `Notify_Token` | **High** | `director`/`teacher` (own campus), `super_admin` | `Teacher.id === User.id` (G-001) — same physical person, two rows; access rules should be reasoned about on both |
| `StudentClass` | none directly, but `Memo` is free-text and has held names/amounts in bug reports seen this session | **Medium** (financial + incidental identity via `Memo`) | scoped by campus + role | `Memo` is the same unclassified free-text risk as `Student.notes` |
| `payment_reports` | `reported_by_name`, `account_last5`, amounts, `void_reason` (free-text) | **High** (financial) | `super_admin` full; reporter's own submissions | `account_last5` is a partial bank account number — already minimized (last-5 only, not full account) |
| `bug_reports` | `description`, `client_info` (userAgent, screen size, timestamp — **not** IP), attachments (screenshots may contain arbitrary on-screen PII) | **Medium** | reporter + `super_admin` | Attachments are the highest-risk sub-case: a screenshot can contain anything visible on screen at capture time (student names, phone numbers, financial figures) |
| `chat_messages` | `body` (free text), `sender_name_snapshot`, `media_url`/`media_name` | **Medium–High** (free text, unclassifiable at rest) | thread members + `super_admin` | No content scanning; relies entirely on access control |
| `StudentSingIn` | links `StudentID`+`TeacherID`+timestamps — attendance pattern is itself sensitive (reveals a child's real-world schedule) | **Medium** | campus-scoped | |

## 2. Cross-cutting risk already known and mitigated

- **Historical incident**: a SQL dump leaked into the repo (the trigger for this issue). Current state per `docs/security/RUNBOOK_SECURITY_CREDENTIAL_FINGERPRINT_AUDIT.md` and the `secret-scan.yml` / `gitleaks scan` CI gate — enforced going forward on every PR. This inventory does not re-audit git history; that is `gitleaks`'s job, already running.
- **Free-text fields are the largest unmanaged surface**: `Student.notes`, `StudentClass.Memo`, `bug_reports.description`, `chat_messages.body`, `payment_reports.void_reason`. None of these are classified or scannable today — they rely entirely on RBAC (campus + role scoping), not content-level controls. This is normal for a system this size, but is the honest answer to "what's NOT covered" rather than claiming completeness.
- **Screenshots (`bug_report_attachments`)** are the least controllable PII surface: whatever was on-screen when a teacher/director captured a bug report. No retention limit is currently enforced on these files.

## 3. Retention & deletion — current state (not yet a policy, this is the gap)

There is **no explicit retention/deletion policy** implemented today. Nothing in this codebase auto-deletes `Student`, `User`, `payment_reports`, `chat_messages`, or `bug_report_attachments` rows/files by age. This inventory intentionally does **not** invent retention periods (e.g. "delete after 3 years") — that is a business/legal decision requiring input on Taiwan's PDPA requirements for a minor-serving education business, not something to assert unilaterally. See §5 open decision.

## 4. Test data / production dump boundary

- Local dev explicitly does **not** connect to production DB (`docs/WSL2_DEV_SETUP.md` §4: "本地開發不需要連接到生產 DB"). Backend tests run only via GitHub Actions CI against an isolated per-job MySQL service container with factory/seeded data, never a production dump.
- No `.sql`/`.dump` files are permitted in the repo — enforced by `gitleaks scan` + `Block secret file types` CI checks (already running on every PR, confirmed via this session's own PR checks).
- Read-only production data access this session (billing verification, Hermes audit, etc.) went through `php artisan tinker` SELECT-only queries over SSH, never exporting/copying data locally — matches the existing "維運分診...禁止跑測試" allowance already documented for bug-report Phase A/C work (`CLAUDE.md` G-012).

## 5. Open decision needed (owner)

Per this repo's own governance convention (G-009/G-011-style red lines), **the retention/deletion period itself is not something to invent from AI judgment** — it depends on: (a) Taiwan PDPA requirements for education businesses serving minors, (b) how long financial records must legally be kept for tax/audit purposes, (c) parent/school contractual expectations. Recommend: confirm with actual legal/accounting guidance, then this doc's §3 gets filled in with real numbers and an enforcement job (e.g. a scheduled command similar to `sessions:audit-stranded`) gets built against it.

## 6. Acceptance against #889

- [x] PII inventory doc complete — this file.
- [x] High-sensitivity fields identified with current access-control note per table.
- [ ] Retention/deletion policy — **explicitly not resolved**, needs owner input on legal requirements (§5). Not claiming false completeness here.
- [x] Production dump usage — already controlled (§4), not a new control, just documented.
