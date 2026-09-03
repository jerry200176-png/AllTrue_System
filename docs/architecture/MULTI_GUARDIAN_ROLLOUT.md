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

## Founder gates (stop here for decision)

- **Migration activation on production** (run migrate)
- **Identity / permission semantics** for Portal login when multiple guardians have different phones (Phase 5)
- **Production rollout** of `PERF_MULTI_GUARDIAN` and later cutover of legacy `parent_phone` / `LineID`

## Next (auto-continue after this PR)

5. LINE / Portal bind to Guardian identity (link SLB ↔ student_guardians)
6. Notify / prefs / authZ fully per-guardian (beyond SLB)
7. Backfill command + reconciliation + broader regression
8. Small-campus flag rollout → Founder cutover approval
