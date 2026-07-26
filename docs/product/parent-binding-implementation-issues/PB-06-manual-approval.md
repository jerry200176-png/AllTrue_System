# PB-06 — Manual approval（BindingRequest）

| Field | Value |
|-------|-------|
| Phase | 2 |
| Risk class | T2 |
| Dependencies | PB-04, PB-03 |
| Blocks | PB-08 |

## Scope

- `binding_requests` table + state machine.
- Parent submit（campus + claimed name； minimal PII）.
- Staff approve/reject/expire job； approve atomically creates relationship（no dupes）.
- Inbox integration for pending + SLA.
- Dedupe key + rate limit.

## Non-scope

- Making approval the primary path for all binds； auto-approve heuristics； OTP.

## Acceptance criteria

1. Submit does not reveal whether student exists.
2. Approve twice is idempotent（one relationship）.
3. Reject/expire parent-visible pending clears appropriately.
4. SLA breach elevates Inbox priority.
5. Campus isolation on review APIs.

## Tests

- Feature：submit/dedupe/approve/reject/expire； IDOR； double approve.
- Inbox case created once per dedupe key.

## Rollback

- Flag `parent_binding_requests=off`； stop submits； pending left for staff cleanup tool.
