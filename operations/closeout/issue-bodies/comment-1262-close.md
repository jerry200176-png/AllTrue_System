## Production evidence — closing

**Fix deployed:** PR #1263 merge `9fab6e3e` is on `main` and Pi ancestry.

### Overnight cycles

| Date | close-orphans | remaining | mdt_before_nightly | mdt_after_nightly | unclassified | Source |
|------|---------------|----------:|-------------------:|------------------:|-------------:|--------|
| 2026-07-18 | 02:30+08 | 83 @~03:47 | 0 | **83** | 0 | Pi Health `29629447963` |
| 2026-07-19 | affected_rows=102 | **0** | **0** | **0** | **0** | Pi Health `29673056613` |

### Exit gate

- 07-18 residuals all `mdt_after_nightly` (classification correct).
- 07-19 post-overnight: zero remaining / after_nightly / unclassified.
- Nightly churn (102) = expected hygiene.
- Residual out of scope: `active_leave_intervals_missing_sign_out=6`.

Closing as **completed**. Do not re-implement #1263.
