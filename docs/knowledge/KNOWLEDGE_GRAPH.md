# Knowledge Graph (stub)

Minimal edge table — not a graph DB. Every high-risk fix should add one row.

**Notation:** If there is no separate GitHub Issue, write `n/a (in-app-only)` — never leave Issue blank/`—`.

| Issue | In-app | PR | Commit | Test | ADR/Lesson | Pattern | SOP | Release/Deploy | Prod verify | User reply | Lifecycle |
|-------|--------|----|--------|------|------------|---------|-----|----------------|-------------|------------|-----------|
| #1282 | #201/#202 | #1298 | `6c197455` | calendarDropRouting + reschedule tests | R73 | (calendar drag) | CHAT_BUG §3.7 | Deploy 29629352958 | version.json `6c197455` | resolved + ask retest | resolved (await verify / 7d timeout) |
| #1296 | #203 (stale path) | #1297 | `1dd0de2b` | StaleScheduleExceptionBusyTest | R72 | stale cancel occupancy | | Deploy | | | superseded by renewal fix |
| n/a (in-app-only) | #203 (renewal) | #1299 | `662960e5` | SameStudentExcludeBusyTest | R74 | Same-student renewal self-conflict | CHAT_BUG §3.7 | Deploy 29630243852 | version.json `662960e5`; avail exclude 13:00 free | public reply #468 ask retest | resolved (await verify / 7d timeout) |
| n/a (in-app-only) | #173 (supersede B) | #1241+#1307 | `8caa4065` + repair run 29633391473 | RepairSupersedeRenewalSession173Test | RM-173 | duplicate_after_renewal | 173-supersede-b-pcr | Deploy prior + GHA repair | API S1/S3–S6 PASS; correction_id=1 | public #474 | resolved |
| n/a (in-app-only) | #204 (leave vacated) | #1305 | `8caa4065` | CourseLeaveCascadeDateLogicTest + leave-cascade-preview | R75 | Leave cascade silent vacated week | CHAT_BUG §3.7 | Deploy 29632836284 | version.json `8caa4065`; preview vacated for SC1953 | public reply #473 | resolved (await verify / 7d timeout) |

## Trace validation (#203 renewal)

| Hop | ID / path | OK |
|-----|-----------|----|
| In-app | #203 | yes |
| GH Issue | `n/a (in-app-only)` (formal) | yes |
| PR | #1299 | yes |
| Test | `backend/tests/Feature/SameStudentExcludeBusyTest.php` | yes |
| Lesson | `AI_REGRESSION_LESSONS` R74 | yes |
| Pattern | `BUG_PATTERN_REGISTRY` ↔ R74 | yes |
| Deploy | Actions `29630243852` | yes |
| Prod | `662960e5` | yes |
| Reply | comment 468 | yes |
| Lifecycle | resolved ≠ closed | yes |

Backlinks: PR #1299 description/body should mention in-app #203 + R74 (see GitHub).
