# PB-09 — Legacy name+phone sunset（KPI gate）

| Field | Value |
|-------|-------|
| Phase | 3 |
| Risk class | T3 |
| Dependencies | PB-08 + **Founder re-approval after KPI gate** |
| Blocks | None（terminal） |
| ADR | https://github.com/jerry200176-png/AllTrue_System/pull/1434 |
| Status | backlog / blocked — **no hard calendar date** |

## Scope

- Per-campus or global flag `parent_binding_legacy_bind=false` **only after gate**.
- LINE/Portal legacy path returns guidance to use pairing code / contact school.
- Optional staff-only override to create BindingRequest on behalf of parent.
- Update parent-facing help、`PARENT_UPDATES.yml` if parent-visible、LineIntegration.
- Confirm KPIs； collect evidence pack for Founder.

## Sunset gate（all required）

1. pairing + BindingRequest share of new binds **≥ 80%**
2. for **30 consecutive days**
3. legacy-related support / manual remediation rate **< 10%**
4. no open P0/P1 identity, PII, or cross-campus incidents
5. revoke → session invalidation + migration rollback **verified**
6. **Founder explicitly re-approves**
7. **Do not** set an automatic effective date in this issue

## Non-scope

- Deleting historical attempts； forcing OTP； removing contact phone fields； auto-sunset without Founder.

## Acceptance criteria

1. Gate checklist attached to PR with metrics evidence.
2. Anonymous legacy bind cannot create new relationships after flag off.
3. Existing relationships unaffected.
4. Emergency re-enable flag tested.
5. Support playbook published.

## Tests

- Feature：legacy rejected； pairing still works； flag re-on restores legacy（emergency）.
- E2E smoke post-deploy.

## Rollback

- Re-enable `parent_binding_legacy_bind` immediately； announce to directors.
