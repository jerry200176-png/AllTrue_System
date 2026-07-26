# PB-09 — Legacy name+phone sunset

| Field | Value |
|-------|-------|
| Phase | 3 |
| Risk class | T3 |
| Dependencies | PB-08 + **Founder explicit approval** |
| Blocks | None（terminal） |

## Scope

- Per-campus or global flag `parent_binding_legacy_bind=false`.
- LINE/Portal legacy path returns guidance to use pairing code / contact school.
- Optional staff-only override to create BindingRequest on behalf of parent.
- Update all parent-facing help、`PARENT_UPDATES.yml` if parent-visible、LineIntegration.
- Confirm KPIs met（pairing share、unattributable failures、wrong-bind）.

## Non-scope

- Deleting historical attempt rows； forcing OTP； removing contact phone fields（still needed for ops/contact）.

## Acceptance criteria

1. Anonymous legacy bind cannot create new relationships.
2. Existing relationships unaffected.
3. Support playbook published； directors trained.
4. Founder sign-off recorded in ADR（Status Accepted + sunset date）.
5. Emergency re-enable flag tested.

## Tests

- Feature：legacy rejected； pairing still works； flag re-on restores legacy（emergency）.
- E2E smoke post-deploy.

## Rollback

- Re-enable `parent_binding_legacy_bind` immediately； announce to directors.
