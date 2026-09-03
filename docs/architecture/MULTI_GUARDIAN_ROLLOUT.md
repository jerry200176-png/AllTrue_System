# Multi-Guardian rollout progress

| Field | Value |
|-------|-------|
| Date | 2026-09-03 |
| Status | Phase 1–4 landed (dark); Phase 5–8 next |
| Flag | `PERF_MULTI_GUARDIAN` / `perfflags.multi_guardian_enabled` **default false** |

## Done

1. **Additive model** — `guardians`, `student_guardians` (migration rollback = drop tables)
2. **Legacy `parent_phone` kept** — StudentContactPhone unchanged when flag off
3. **Dual-write / dual-read** — write when tables exist; read primary guardian only when flag on
4. **Staff CRUD** — `/api/v1/students/{id}/guardians` + StudentsList section (visible only when flag on)
5. **LINE consistency (pre-GSR)** — tuition fan-out; prefs per `ParentSession.line_user_id`; no `Student.LineID` overwrite
6. **LINE → Guardian dual-write** — verified LINE bind upserts Guardian + StudentGuardian (`source=line_binding`) without changing Portal login rules

## Founder gates (stop here for decision)

- **Migration activation on production** (run migrate)
- **Identity / permission semantics** for Portal login when multiple guardians have different phones (Phase 5 auth cutover)
- **Production rollout** of `PERF_MULTI_GUARDIAN` and later cutover of legacy `parent_phone` / `LineID`

## Next after Founder decisions

5b. Portal login / session scoped to Guardian identity (needs semantics decision)
6. Notify / prefs / authZ fully per-guardian (beyond SLB)
7. Backfill command + reconciliation + broader regression
8. Small-campus flag rollout → Founder cutover approval
