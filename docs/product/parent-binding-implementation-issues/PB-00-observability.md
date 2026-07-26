# PB-00 — Observability & structured failure reasons

| Field | Value |
|-------|-------|
| Phase | 0 |
| Risk class | T1 |
| ADR | https://github.com/jerry200176-png/AllTrue_System/pull/1434 |
| GitHub status | **ready / next** |
| Dependencies | None |
| Blocks | PB-01, PB-02, PB-03 |

## Scope

- Emit structured `reason_code` on LINE bind failures and Parent Portal login failures.
- PII-safe logging（masked phone only；no raw phone in logs）.
- Optional `binding_attempts` append table **or** structured log fields with correlation id.
- Metrics hooks / queries for baseline KPIs（success rate, reason distribution, missing contact）.
- Report：active students missing contact phone（SQL or artisan command in CI/ops docs — not Pi destructive）.

## Non-scope

- OTP / SMS provider（Founder：本期不做；非 Phase 0 dependency）。
- Changing success bind path behavior or user-facing success copy.
- Pairing credentials, Inbox UI, schema for relationships.
- Throttling changes beyond adding counters（throttle can land in PB-01）.

## Acceptance criteria

1. Every failure path in `handleBindingByName` / `handleBindingById` / `ParentPortalController::login` records a stable reason code from the ADR list.
2. Logs/attempts never contain full phone numbers（masked or hash only）.
3. Success path behavior identical to pre-change（feature tests prove）.
4. Ops can answer：top failure reasons last 7 days；count of students with empty contact phone.

## Tests

- Unit：reason mapping from match outcomes.
- Feature：fail bind → attempt/log has `CONTACT_PHONE_MISSING` vs `PHONE_MISMATCH` vs `STUDENT_NOT_FOUND` correctly **internally**; response body may still be old copy until PB-01.
- Negative：assert log fixture has no `\d{10}` phone patterns for sample binds.

## Rollback

- Feature flag `parent_binding_observability=off` stops writes； drop attempts table only if empty/new.

## Notes

Does not claim security complete — observability only.
