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

For a qualifying R0/R1 fix, `resolved` means engineering delivery with a public request to retry, **not** reporter acceptance. Reporter acceptance remains `PENDING` until reporter-verify or the existing timeout path. If the reporter says the problem persists, reopen investigation. No new production fixture or status schema is implied.

## Anti-metrics (never sole success)

- File/doc count  
- Issue close count  
- CI green alone  
- “Agent said done”

## Reporter-verify timeout (in-app)

After `resolved` + public ask-to-retest:

- Wait **7 calendar days** for reporter reply.  
- If no reply and no regression signal: may move to `closed` with note `closed_by_timeout` citing this contract.  
- **Command (manual):** `php artisan bugs:close-stale-resolved --dry-run` then apply — see [`docs/sop/BUG_REPORTER_TIMEOUT.md`](../sop/BUG_REPORTER_TIMEOUT.md). Not auto-scheduled.  
- Excludes resolves lacking `[resolution_evidence]` (legacy / unverified).  
- If reporter reports still broken: reopen to `in_progress` (do not game close).

## Independent verification

Prefer a second agent/role assuming the fix is wrong before merge of high-risk changes. Same-session self-check is Partial only.
