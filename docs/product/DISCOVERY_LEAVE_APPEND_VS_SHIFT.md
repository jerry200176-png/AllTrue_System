# Product Decision — Leave semantics: KEEP dates + append tail

**Status:** Decided (Founder Decision 2026-07-26) — **implemented**  
**Opened:** 2026-07-18 (Discovery)  
**Decided:** 2026-07-26  
**Related:** in-app #204 (preview); GitHub [#1100](https://github.com/jerry200176-png/AllTrue_System/issues/1100) (A/B boundary after leave extend)  
**Owner:** Product / Founder  

## Current policy (locked)

Ordinary count-based leave uses **`KEEP_FUTURE_DATES_APPEND_TAIL`**:

1. Mark the target `ClassSession` as `leave` (does not consume purchased ordinal).
2. **Do not move** any existing future session dates/times/teachers/classrooms.
3. Append **at most one** tail session to preserve purchased count.
4. Preview (`POST /api/v1/schedules/leave-cascade-preview`) must show `vacated=[]`, `moves=[]`, `future_dates_unchanged=true`, and the append date.

Authority: `CourseLeaveCascadeService::applyLeaveCascade` / `appendTailAfterLeave` / `computeAppendOnlyPlan`.

## Explicit pause / whole-course shift (not ordinary leave)

**`SHIFT_FUTURE_DATES_APPEND_TAIL`** remains available as an explicit capability:

- `CourseLeaveCascadeService::applyExplicitCoursePauseShift`
- Preview with `policy=SHIFT_FUTURE_DATES_APPEND_TAIL`
- Produces vacated weeks by design

There is **no** ordinary leave UI that triggers SHIFT. A dedicated pause/suspend product surface may wire to it later.

## Supersedes

- Prior discovery conclusion that ordinary leave must shift future sessions (2026-07-18 lock).
- AI_REGRESSION_LESSONS §R75 wording that treated vacated weeks as correct ordinary-leave behaviour (see superseded note in that section).

## Historical repairs

Legacy SHIFT leaves that already created silent vacated weeks:

```bash
php artisan repair:leave-vacated-weeks --dry-run
# future-safe apply (prod): ALLOW_PROD_REPAIR=1 php artisan repair:leave-vacated-weeks --apply --force --actor=...
```

See `docs/runbooks/REPAIR_LEAVE_VACATED_WEEKS.md`.

## Exit criteria (done when)

- [x] Written Founder Decision
- [x] Ordinary leave KEEP + append implemented
- [x] SHIFT isolated as explicit capability
- [x] Preview/API/UI copy aligned
- [x] Regression tests + repair scanner
