# Evidence Contract

## What counts as done

| Claim | Required evidence |
|-------|-------------------|
| Code fixed | PR merged to main + linked tests |
| Deployed | Workflow success + production SHA/hash matches merge |
| Behavior fixed | Prod API/UI observation **or** bundle marker + targeted API check |
| In-app bug engineering-complete | Public comment + `resolved` + **API evidence** (below) |
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

## Anti-metrics (never sole success)

- File/doc count  
- Issue close count  
- CI green alone  
- “Agent said done”

## Reporter-verify timeout (in-app)

After `resolved` + public ask-to-retest:

- Wait **7 calendar days** for reporter reply.  
- If no reply and no regression signal: may move to `closed` with note `closed_by_timeout` citing this contract.  
- If reporter reports still broken: reopen to `in_progress` (do not game close).

## Independent verification

Prefer a second agent/role assuming the fix is wrong before merge of high-risk changes. Same-session self-check is Partial only.
