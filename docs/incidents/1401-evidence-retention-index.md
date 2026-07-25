# Evidence Retention Index — INC-1401 (LINE Parent Authorization)

**This document contains no PII and no restricted evidence.** It is a
sanitized index and placement instructions for a human (Founder or
designated custodian) to file the real evidence outside this public
repository. Nothing in this file should ever be edited to contain a
student name, phone, LINE user ID, token, message content, or raw record.

## Proposed restricted location

```
AllTrue Restricted/Incidents/2026/INC-1401 LINE Parent Authorization/
├── 01-timeline/
├── 02-technical-evidence/
├── 03-impact-audit/
├── 04-notification-decision/
├── 05-regulatory-reporting/
└── 06-remediation/
```

Exact storage system (e.g. a restricted Drive folder, an internal
document vault) and access-control list are for the Founder to designate
— not yet established as of this writing.

## What belongs in each folder (instructions, not content)

- **01-timeline** — dated entries: when the vulnerable binding logic was
  introduced (git evidence: commit `49880c80`, 2026-04-10, is the earliest
  point confirmable from this repo's history — the feature may predate
  this repo's own git history, which cannot be ruled out), when it was
  fixed (`7e757c9b`, 2026-07-24), when this closure/impact-audit work was
  done, and any future notification/regulatory dates.
- **02-technical-evidence** — a copy of `docs/incidents/1401-closure-packet.md`
  (already PII-free, safe to duplicate here as-is) plus any raw log
  excerpts a human pulls directly from the Pi during manual investigation
  — those raw excerpts likely DO contain PII and must go here, never in
  this repository.
- **03-impact-audit** — the full, unredacted output of the `audit:1401-impact`
  workflow run (PR #1416) once triggered. The workflow's own job log
  output is already aggregate-only (counts/booleans/timestamps/hashes,
  no PII by design) — file a copy/screenshot of that job log here for
  permanent retention, since GitHub Actions logs eventually age out of the
  UI's default retention window.
- **04-notification-decision** — the completed version of the notification
  decision template in the closure packet (§8), any legal/compliance
  sign-off, and (if notification proceeds) a record of who was notified
  and when — this folder WILL contain PII once filled in; that is exactly
  why it lives here and not in the repository.
- **05-regulatory-reporting** — if a regulatory reporting obligation
  applies (jurisdiction-dependent; not assessed by this session — legal
  judgment required), the assessment, any filed report, and correspondence
  go here. **This session takes no position on whether reporting is
  required and does not submit anything automatically or on your
  behalf.**
- **06-remediation** — links to PR #1400 (fix), #1415 (regression test),
  #1416 (impact audit), the P1 auditability-gap issue, and their
  resolution/merge status over time.

## What this session did NOT do

- Did not create the restricted folder structure itself (no access to any
  such system from this session).
- Did not move, copy, or reference any real student data.
- Did not decide the notification or regulatory-reporting questions —
  both remain open Founder/legal decisions.

## Human action required

1. Create the restricted folder structure above in whatever system you
   designate (Drive, internal vault, etc.).
2. Copy `docs/incidents/1401-closure-packet.md` into `02-technical-evidence/`.
3. After the PR #1416 audit is run, copy its job log output into
   `03-impact-audit/` before it ages out of GitHub's retention window.
4. Fill in `04-notification-decision/` and `05-regulatory-reporting/` only
   with human judgment — this session does not pre-fill either.
