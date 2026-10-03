# Scoped Phase-C for shipped in-app 295 (2026-10-03)

One fixed per-ID record in the existing single-target writer, bound to the
exact merge commit. No gate, actor, permission or writer change. Mechanically
R3/T3 (protected workflow path); Founder GO 2026-10-03 (「都GO」).

| In-app | Issue | Basis | rev (merge) | Deploy run | Reply claims |
|---|---|---|---|---|---|
| 295 | #2904 | PR #3433 | `a3cd1d2a8a4292ff35b942d56b61d4be48784449` | 37098271478 | parent-last threads older than 14 days with no staff reply leave 待回覆; a new parent message re-opens; manual dismiss unchanged |

Evidence: `ParentFeedbackAwaitingReplyTest` (CI) + production `deployment.json`
backend_sha = frontend_build_sha = `9be6ad241555ea9b11e999c6274f631ec351da2e`
(rev is an ancestor). No production UI check; reply says so. Reporter
acceptance pending.

Not included: in-app 319 (#3069). #3449 removed an N+1 but the main
`/class-sessions` aggregate cost is unchanged, so 319 gets a progress
follow-up, not Phase-C.
