# PB-06 — Manual approval（BindingRequest）+ parent self-serve

| Field | Value |
|-------|-------|
| Phase | 2 |
| Risk class | T2 |
| Dependencies | PB-04, PB-03 |
| Blocks | PB-08 |
| ADR | https://github.com/jerry200176-png/AllTrue_System/pull/1434 |
| Status | backlog / blocked on PB-04 |

## Scope

- `binding_requests` table + state machine.
- **Parent self-serve submit**（Founder）：requires authenticated LINE `ParentIdentity`； campus + claimed name； minimal PII.
- Safe generic external response； never reveal student existence.
- Rate limit + dedupe； staff masked evidence； staff may create on behalf of parent.
- Staff approve/reject/expire job； approve atomically creates relationship（no dupes）.
- Inbox integration for pending + SLA.

## Non-scope

- Making approval the primary path for all binds； auto-approve heuristics； OTP； anonymous submits.

## Acceptance criteria

1. Unauthenticated submit rejected.
2. Submit does not reveal whether student exists.
3. Approve twice is idempotent（one relationship）.
4. Reject/expire clears pending appropriately for parent.
5. SLA breach elevates Inbox priority； campus isolation on review APIs.

## Tests

- Feature：auth required； submit/dedupe/approve/reject/expire； IDOR； double approve； enumeration resistance.
- Inbox case created once per dedupe key； payload has no full phone.

## Rollback

- Flag `parent_binding_requests=off`； stop submits； pending left for staff cleanup tool.
