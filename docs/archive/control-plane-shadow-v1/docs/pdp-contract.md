> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](CONTROL_PLANE_CONTRACT.md) (I1–I5).
> **Decision:** INCIDENT stack only (I3). **Execution:** [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml) only (I1).
> Do not use this file for runtime operations.


# PDP Contract — Control Plane Runtime (Single Authority)

> **Authority:** `ci-artifacts/pdp.json`  
> **Decision kernel:** `scripts/platform/policy-engine.py` (pure function, input JSON only)

## Hard rules

1. Only `ci-artifacts/pdp.json` is authoritative for policy decisions.
2. Only `policy-engine.py` computes `block_merge`, `allow_staging`, `allow_production`, `runtime_flags`, `slo.freeze_deploy`.
3. All other components are **read-only execution** — verify signature, read field, act.

## Execution flow

```text
Collectors (sop, decision, exec) → assemble input JSON
  → policy-engine.py (pure)
  → platform-gate-assemble.py (sign + write ci-artifacts/pdp.json)
  → control-plane-verify.sh (validate only)
  → pdp-exec-gate.sh (if block_merge → fail CI)
```

## Runtime

- Laravel `FeatureFlagService` reads `ci-artifacts/pdp.json` → `pdp_v3.runtime_flags`
- No hash rollout, no local flag evaluation, no snapshot authority files

## Promotion

`promotion-enforce.py` allows production **only if**:

- PDP signature valid
- `pdp_v3.allow_production == true`
- staging artifact exists, signed, fresh (max age from PDP)

## Forbidden

- `pdp-runtime-snapshot.json` as decision source
- `runtime-pdp-artifact.py` writing policy (metrics only)
- `flag-engine.py` hash rollout (deprecated)
- Git-diff-based policy decisions
- YAML `if:` chains deciding deploy/merge

## Verification stack

`control-plane-verify.sh`:

1. `drift-nullifier.py` — secondary authority scan
2. `git-policy-audit.py` — schema + forbidden derived files
3. `control-plane-lock.py`
4. `pdp-read.py --verify`
5. `policy-fork-detector.py`
6. `pdp-replay-guard.py`

## Tests

```bash
python3 scripts/platform/test_control_plane_attacks.py
```
