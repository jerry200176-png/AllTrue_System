# In-App #297 Phase-B — Admin-date automatic grade promotion

**Status:** APPROVED — Phase-B.1 implementation authorized  
**GitHub:** #2906 / in-app #297  
**Phase-A (production tip):** manual preview/confirm UI + writer  
**Phase-B.1 (this slice):** scheduled preview + staff/director reminder — **no auto-confirm**

## Founder decisions (binding)

| Topic | Decision |
|---|---|
| Phase-B.1 behavior | Automatic scheduled **preview** + staff/director **reminder** only |
| Auto-confirm | Architecture may keep `GRADE_PROMOTION_AUTO_CONFIRM` flag; **default false**; **not authorized** in Phase-B.1; Phase-B.2 requires separate Founder GO |
| Campus scope | Explicit allowlist; **empty = fail closed**; initial rollout **campus 9 only** |
| Date/time | `Asia/Taipei`; configured admin promotion date; preserve Phase-A preview/confirm + season idempotency |
| Notification | Use existing staff/director in-app `Notifications` path; scheduler failures → durable ops evidence (Notification + BugReport) |
| Email | **Not** in this phase |
| Writer | Reuse `GradePromotionService` only — no second mutation path |
| H3 | Graduate without course Stop in promotion step (unchanged) |
| Non-scope | Historical backfill, TrueFit, auth (#299), billing |

## Implementation surface

- `config/grade_promotion.php` — `auto_confirm`, `campus_allowlist`
- `GradePromotionScheduledPreviewService` — admin-date gate, allowlist, preview, notify
- `grade-promotion:scheduled-preview` — scheduler command (08:00 Asia/Taipei)
- `SchedulerEvidence` job `grade-promotion-scheduled-preview`

## Phase-B.2 (not authorized)

Auto-confirm on admin date requires separate Founder GO after staging/operational evidence.
