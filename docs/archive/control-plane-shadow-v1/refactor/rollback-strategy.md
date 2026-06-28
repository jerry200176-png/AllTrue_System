> **HISTORICAL CONTEXT ONLY**
> This document does **not** override [`CONTROL_PLANE_CONTRACT.md`](../CONTROL_PLANE_CONTRACT.md) (I1–I5).

# Refactor Rollback Strategy

> Phase 0 artifact. Defines triggers, conditions, and revert procedures for shadow refactor layers.

---

## Rollback Triggers

| Trigger | Detection | Severity |
|---|---|---|
| Session count mismatch rate | Shadow vs legacy `UsedSessions`/`RemainingSessions` diff in logs | High |
| Payment status divergence | `payment_status` shadow ≠ index API response | High |
| Schedule duplication | Duplicate `ClassSession` on same `(StudentClassID, SessionDate, StartTime)` after shadow enable | Critical |
| Shadow diff in production | `REFACTOR_DOMAIN_DIFF` log entries when `REFACTOR_CONSISTENCY_CHECK=true` | Medium (investigate) |
| Command layer accidental activation | Any write via `use_command_layer=true` without promotion checklist | Critical |

---

## Safe Rollback Conditions

Rollback is safe when **all** of the following hold:

1. Legacy controller/service paths remain the **sole writers** (Phases 0–5 design guarantee).
2. `REFACTOR_USE_COMMAND_LAYER=false` (default).
3. No migration was applied as part of the refactor phase being reverted.
4. No production endpoint was switched to command-layer execution.

---

## Rollback Methods

### Method 1: Single-commit revert (preferred)

Each phase is designed as one self-contained PR. Revert the phase commit:

```bash
git revert <phase-commit-sha> --no-edit
```

Phases 0–3 modify **zero** existing production logic. Phase 4 adds append-only controller hooks gated by `consistency_check=false`.

### Method 2: Disable flags (no deploy needed)

Set in production `.env` or deploy with flags off:

```env
REFACTOR_SHADOW_ENABLED=false
REFACTOR_USE_COMMAND_LAYER=false
REFACTOR_LOG_SHADOW_DIFF=false
REFACTOR_CONSISTENCY_CHECK=false
```

Shadow/command/read-model code becomes inert. Legacy path unchanged.

### Method 3: Legacy path fallback

No adapter switch exists until Phase 7 (future). Controllers always execute legacy logic first; shadow is compare-only.

---

## Verification After Rollback

1. Run architecture tests: `php artisan test --filter=Architecture`
2. Run golden regression suite: `SessionDeductionMinutesEngineTest`, `StudentClassPaidStatusTest`
3. Health check: `curl -sk https://daan.lifenet.com.tw/api/v1/health`
4. Smoke: RFID swipe, director login, today class-sessions list

---

## Environment Variable Matrix (Phase 5)

| Variable | Default | Purpose | Rollback action |
|---|---|---|---|
| `REFACTOR_SHADOW_ENABLED` | `false` | Enable shadow comparison in instrumentation | Set `false` |
| `REFACTOR_USE_COMMAND_LAYER` | `false` | Command execution (must stay false until Phase 7+) | Set `false` |
| `REFACTOR_LOG_SHADOW_DIFF` | `false` | Verbose shadow diff logging | Set `false` |
| `REFACTOR_CONSISTENCY_CHECK` | `false` | Production divergence detection hooks | Set `false` |

Config file: [`backend/config/refactor.php`](../../backend/config/refactor.php)

---

## Per-Phase Revert Safety

| Phase | Revert scope | Single-commit safe? |
|---|---|---|
| 0 | Docs + test scaffold | Yes |
| 1 | Domain mirrors + artisan command | Yes |
| 2 | Commands + config | Yes |
| 3 | Read models + parity test | Yes |
| 4 | Consistency checker + optional hooks | Yes (hooks no-op when flag off) |
| 5 | Docs only | Yes |

---

## Related Documents

- [`safety-baseline.md`](safety-baseline.md)
- [`diff-reporting.md`](diff-reporting.md) (Phase 4)
- [`master-refactor-plan.md`](master-refactor-plan.md) (Phase 5)
