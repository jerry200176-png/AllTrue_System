# Bug intake → production (wrapper)

## Freshness gate (mandatory)

Never triage from an old `bug-queue-dump` artifact. A queue dump is only valid
for 15 minutes and must be paired with the detail dump for the same bug ID.

Required sequence:

1. Run `bug-queue-dump.yml` (prefer `-f target_bug_id=<id>`) and download
   `meta.json` plus `open-bugs.json`.
2. Confirm the target ID is present exactly once with status `new`, `triaged`,
   or `in_progress`; do not infer the target from `max_id` or an old report.
3. Run `bug-detail-dump.yml` with that exact `bug_id`.
4. Confirm attachments, the reporter's complete bug history, all comments, and
   all status logs are present before reading the symptom or proposing a fix.
5. Run `scripts/validate-bug-intake-evidence.py`; a failure is a stop-the-line
   condition, not a reason to continue with best-effort triage.

The validator intentionally fails when the queue is stale, the IDs differ, the
bug is no longer open, or the reporter-history evidence is absent. This prevents
a previously resolved bug from being mistaken for the newest report.

Canonical long-form:

1. [`CHAT_BUG_SYSTEM.md`](../CHAT_BUG_SYSTEM.md) §3.6–§3.7  
2. [`GUIDE_BUG_CLOSURE_GATE.md`](../GUIDE_BUG_CLOSURE_GATE.md)  
3. [`AI_REGRESSION_LESSONS.md`](../AI_REGRESSION_LESSONS.md) R51/R53 + module index  

Success = Evidence Contract, not CI alone. Reporter-verify timeout = Evidence Contract §Reporter-verify timeout.
