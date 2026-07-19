# Next verified P0/P1 — stranded #1062 classify (no bulk repair)

**Date:** 2026-07-19  
**While:** #1342 waits on directors (not impl WIP)  
**Canonical issue:** [#1062](https://github.com/jerry200176-png/AllTrue_System/issues/1062)  
**Baseline:** [`ORPHAN_SESSION_BASELINE.md`](phase2/ORPHAN_SESSION_BASELINE.md)

## Pre-checks (before any write)

| Question | Current answer |
|----------|----------------|
| Active risk quantity | Pi Health `orphan_session_count` ≈ **1681** stranded prepaid units (not TD-016 Stop=1 orphans) |
| Root cause | Count-mode prepaid not forward-materialized (G-010); Kernel lacked forward-gen job historically |
| False positive | Low-volume Sundays / dormant contracts / cadence gaps — must split via `sessions:audit-stranded --json` + forward-gen **dry-run** skip reasons |
| Canonical Issue | **#1062** (Track A PCR); dormant outreach **#1152**; duplicates **#1130** separate |
| Code fix vs data repair | **Separate:** guards/forward-gen code ≠ bulk session insert; execute needs CEO GO per `1062-track-a-pcr.md` |

## This round (autonomous, read-only)

1. Refresh stranded JSON on Pi (workflow or SSH read-only).  
2. Publish redacted cohort split (active / dormant / cadence / unknown).  
3. **Do not** repair 1681 or 50 cross-SC rows.  
4. Cross-SC ~50：confirm against #1130/#1134 inventory; root-cause note only.

## Out of scope

- `sessions:generate-forward --execute`  
- Mass cancel / mass duplicate repair  
- Reopening #1262
