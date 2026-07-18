# Runbook — Data Repair execution states (#1309)

## States (operator-facing)

| State | Meaning | Operator action |
|-------|---------|-----------------|
| `NOT_APPLIED` | Mutation did not commit | Safe to fix code and retry after dry-run |
| `APPLIED_AND_VERIFIED` | Correction ledger + post-verify OK | Done; further execute → `ALREADY_APPLIED` |
| `APPLIED_POSTVERIFY_FAILED` | **Data written**; verify step failed | **Do not re-run execute.** Inspect `session_corrections`, fix verify, run verify-only |
| `ROLLED_BACK` | Rollback committed | New Founder Decision before re-apply |
| `ALREADY_APPLIED` | Open correction exists | No-op; not a failure |

## Authoritative source

`session_corrections` row for the decision_reference is authoritative over Actions red/green.

## Incident

Actions 29633391473: supersede B mutation OK, post-verify `Class "DB" not found` → false failure signal. Fixed: FQCN facade + state labels + preflight ALREADY_APPLIED short-circuit. Issue #1309.
