# Evidence Contract

## What counts as done

| Claim | Required evidence |
|-------|-------------------|
| Code verified | Targeted regression and required CI passed for the fix |
| Merged | PR merged to main; this alone does not prove deployment |
| Deployed | Canonical deployment workflow succeeded |
| Production version verified | Public production SHA/hash contains the fix; this alone does not prove the affected user path |
| Production user-path verified | Direct observation of the affected production API/UI path; record `NO` when absent |
| R0/R1 engineering delivery | Exact production SHA containing the change + deterministic affected-path regression + production health/version, provided no schema, identity/permission, billing, migration, or production data mutation/repair is involved. Record direct production user-path verification separately; absent means `NO`, not `YES`. |
| R2/R3 or protected behavior fixed | Direct affected production API/UI path evidence, plus existing risk-specific approval, rollback, and acceptance requirements; low-risk evidence is not a substitute. |
| In-app bug engineering-complete | Public comment + `resolved` + **API evidence** (below) |
| Reporter accepted | Reporter-verify succeeded; timeout closure is a separate operational outcome, not affirmative reporter acceptance |
| In-app bug closed | Reporter-verify **or** timeout below |
| Governance control live | File on main + ≥1 CI/Rule/code enforcement |

## API: `POST /api/v1/bugs/{id}/status` → `resolved`

Enforced in `BugReportService` (not free-text “done”):

| Field | Rule |
|-------|------|
| Public reply | ≥1 comment with `is_internal_note=false` already on the bug |
| `production_revision` | Git SHA 7–40 hex **or** |
| `evidence_exception_reason` | ≥20 chars, **super_admin only** (no deploy cases) |
| `deploy_run_id` | Optional Actions run id |
| Resolver / time | `changed_by` + status log `created_at`; encoded in `[resolution_evidence]{...}` note |

Missing evidence → **422**, status unchanged. Internal-only comments do **not** satisfy public reply.

For a qualifying R0/R1 fix, `resolved` means engineering delivery with a public request to retry, **not** reporter acceptance. Reporter acceptance remains `PENDING` until reporter-verify; a policy-qualified timeout is operational closure without affirmative acceptance. If the reporter says the problem persists, reopen investigation. No new production fixture or status schema is implied.

## Anti-metrics (never sole success)

- File/doc count  
- Issue close count  
- CI green alone  
- “Agent said done”

## Reporter-verify timeout (in-app)

After `resolved` + public ask-to-retest:

Source of truth is the code; the values below are checked against it by `scripts/tests/test_evidence_contract_timeouts.py` (#3918).

- **Daily automatic run (Founder 2A, 2026-10-08):** `bug-reporter-timeout.yml` runs `php artisan bugs:close-stale-resolved --auto` every day. It closes `resolved` reports with no reporter reply for `CloseStaleResolvedBugsCommand::AUTO_DAYS` = **14** calendar days, with the standard plain public reply. Resolved queue only (never the awaiting-reporter queue). If more than `CloseStaleResolvedBugsCommand::AUTO_MAX_PER_RUN` = **20** reports are eligible, the whole run refuses and changes nothing.  
- **Manual run:** `php artisan bugs:close-stale-resolved --dry-run` then apply with `--reviewed-ids` (each ID individually reviewed) — see [`docs/sop/BUG_REPORTER_TIMEOUT.md`](../sop/BUG_REPORTER_TIMEOUT.md). `--days` defaults to 7 and may not be lower than `BugReportService::REPORTER_TIMEOUT_MIN_DAYS` = **7** calendar days.  
- If no reply and no regression signal: may move to `closed` with note `closed_by_timeout` citing this contract.  
- Excludes resolves lacking `[resolution_evidence]` (legacy / unverified).  
- If reporter reports still broken: reopen to `in_progress` (do not game close).

### Awaiting-reporter timeout (`triaged`)

- Applies to `triaged` bugs whose latest **non-internal** comment is a staff question (author is not the reporter), at least **14 calendar days** old, with no reporter reply after it. The 14 days is a fixed constant (`BugReportService::AWAITING_REPORTER_TIMEOUT_DAYS` = **14**), independent of the workflow/command `--days`.
- Same individually reviewed apply (`--reviewed-ids`) as above. The public comment and the `closed` status (note `closed_by_timeout — awaiting reporter reply 14 days`) commit atomically after an eligibility recheck.
- Reopen: a reporter's public comment on a `closed_by_timeout` bug moves an awaiting-timeout closure to `triaged` and a resolved-timeout closure to `in_progress` (note `reopened_by_reporter_reply`). Staff comments and reporter-verified closes never reopen.

## Independent verification

Prefer a second agent/role assuming the fix is wrong before merge of high-risk changes. Same-session self-check is Partial only.
