# TD-059 investigation plan — package pool minutes

**Issue:** [#1343](https://github.com/jerry200176-png/AllTrue_System/issues/1343)  
**TD:** `docs/TECH_DEBT.md` TD-059  
**Gate:** No production schema change until impact numbers land.

## Hypothesis

`SessionDeductionService` records partial makeup via `session_deduction_ledger.minutes`, but `PackageDeductionService` still mirrors pool usage as `delta=±1`. Shared-package + longer/shorter makeup can drift pool remaining vs personal minutes.

## Read-only metrics (Actions → Pi)

Workflow: `.github/workflows/ops-portfolio-td059-leave-audit.yml`  
Artifact: `td059-audit.json`

| Metric | Decision use |
|--------|----------------|
| multi_member_packages / courses | Exposure size |
| partial_minute_deducts_on_package_members | Whether #613 minutes path hit packages |
| partial_sessions_minutes_ne_contract_with_package_delta | Drift signal |

## Go / no-go

| Result | Action |
|--------|--------|
| multi_member≈0 **or** partial_minute_deducts=0 | Keep P3; document deferral on #1343; no schema |
| partial deducts >0 **and** minutes≠contract with package delta | Raise to P1; ARCH design (integer minutes dual-write + backfill + rollback) before impl |
| Ambiguous | Add cheap telemetry; do not migrate |

## Non-goals this investigation

- Migration / dual-write implementation
- Changing 1:1 makeup minutes (already shipped #1337)
