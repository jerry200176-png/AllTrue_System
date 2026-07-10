# ADR-003 — Layering rules & controller `DB::` ratchet

> **Status:** Accepted (2026-07-10). Supersedes nothing; complements #966 (god-controller decomposition), #967, #957 (canonical read model).
> **Naming:** per `docs/INDEX.md`, `ADR_` = architecture decision record.

## Context

The high-risk domain controllers hold raw persistence logic inline:

| Controller | `DB::` call sites (2026-07-10 baseline) |
|------------|------------------------------------------|
| StudentClassController | 44 |
| AttendanceController | 38 |
| ClassSessionController | 31 |
| FinanceController | 16 |
| AlertController | 9 |
| SwipeRfidController | 3 |

Consequences observed in production incidents: campus-isolation filters easy to omit on a raw `DB::table()` (mis-binding family, R18/R60); business rules untestable without HTTP; the same query truth forked across controllers (G-009 payment divergence, #959); and duplicate-materialization races that only a DB constraint finally stopped (#957 D1).

## Decision

1. **Layering (target):** `Controller → Service (domain) → Model/Repository`. Controllers validate input, resolve auth/campus scope, call one service method, shape the response. No business branching or multi-table writes in controllers.
2. **Campus isolation is a service concern**, asserted once per domain operation — never re-derived ad hoc in a controller `DB::` call.
3. **Reads converge on the #957 read models** (PaymentStatusView, SessionUsageView, ScheduleCalendarView) rather than per-controller queries, so "payment status" / "sessions used" have one definition.
4. **`DB::` ratchet, not a big-bang ban:** existing call sites are grandfathered; **new** `DB::` usage in the protected controllers is flagged in CI (advisory, baseline-gated — same model as missing-tests-warn). The count above is the ceiling; it may only go down. Decomposition PRs under #966 lower the baseline as they extract services.

## Enforcement

`scripts/controller-db-ratchet.mjs` compares current `DB::` counts in the protected controllers against `scripts/controller-db-baseline.json`. CI job is **advisory** first (warn on increase) to avoid blocking unrelated work; promote to **required** once the baseline trends down and the team is used to it (mirrors the PHPStan advisory→required path, #545).

## Consequences

- New persistence logic must land in a service with a unit test — raises testability, closes the "untestable blob" gap.
- The ratchet gives an objective, PR-visible signal that decomposition is progressing (baseline number is the KPI for #966).
- No behavior change and no rewrite risk on day one; the protected modules stay under their R1/R6 guardrails.

## Related

- #966 (epic: decompose StudentClass 5k / ClassSession 2.6k LOC) — this ADR is its ruleset.
- #957 (canonical materialization + read models) — the read-convergence half.
- #959 (payment truth divergence) — resolved by rule 3.
- `docs/AI_REGRESSION_LESSONS.md` R18/R60 (campus isolation), R1/R6 (protected-module guardrails).
