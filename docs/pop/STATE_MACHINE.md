# POP Operations State Machine

> **Authority:** [ADR-POP-007](adr/ADR-POP-007-state-machine.md)

## States

| State | Description |
|-------|-------------|
| `draft` | Created, not planned |
| `planned` | Plan generated |
| `awaiting_approval` | Submitted for approval |
| `approved` | Approved, not scheduled |
| `scheduled` | In queue for executor |
| `claimed` | Executor claimed token |
| `running` | Execute in progress |
| `verifying` | Post-execute verification |
| `succeeded` | Verify + invariants passed |
| `verification_failed` | Verify or invariant failed |
| `failed` | Execute failed |
| `rolled_back` | Rollback completed |
| `cancelled` | Cancelled before execute |
| `expired` | Approval or token expired |
| `closed` | Terminal archive |

## Legal transitions (summary)

```
draft → planned
planned → awaiting_approval | cancelled
awaiting_approval → approved | cancelled | expired
approved → scheduled | cancelled | expired
scheduled → claimed | cancelled | expired
claimed → running | failed | cancelled
running → verifying | failed
verifying → succeeded | verification_failed | failed
succeeded → closed | rolled_back (if rollback requested)
failed → rolled_back | closed
verification_failed → closed | rolled_back
rolled_back → closed
cancelled → closed
expired → closed
```

Illegal transitions must return HTTP 409 / CLI exit code 9.

## Concurrency

- `concurrency_key` derived from catalog `blast_radius` + scope.
- Only one `running` operation per key unless policy allows parallel.
