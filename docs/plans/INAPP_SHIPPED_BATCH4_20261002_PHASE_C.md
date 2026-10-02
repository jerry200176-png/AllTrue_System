# Scoped Phase-C for shipped in-app batch 4 2026-10-02

## Exact scope and authority

Three fixed per-ID records (364, 322, 300) in the existing single-target
writer, each bound to an exact merge commit on `origin/main`. No allowlist
gate, actor, permission, evidence requirement, writer or environment change.
Lifecycle write is existing product-loop closeout
(`INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md`). This companion is mechanically
R3/T3 (protected workflow path).

Evidence level: engineering tests (merged PR CI) and production version check
only. Production `deployment.json` backend_sha = frontend_build_sha =
`44ab1b3698cccfa1d12f367c65e12d3e53f1d3fe`, deploy run 36961012794 (success).
No production UI check was performed this round; replies say so, and reporter
acceptance is pending for every ID.

## Included

| In-app | Issue | Basis | rev (merge) | Deploy run | Reply claims |
|---|---|---|---|---|---|
| 364 | #3228 | PR #3428 | `44ab1b3698cccfa1d12f367c65e12d3e53f1d3fe` | 36961012794 | calendar filter now uses the same rule as the day grid (no empty teacher column); out-of-hours lesson data unchanged |
| 322 | #3072 | PR #3168 | `a98a6c9c374f54eecbc9e112c63662832f813883` | 36961012794 | finance-gated discount on course create (director/admin only; also renewal/purchase); no production discount transaction created |
| 300 | #2909 | PR #2972 | `54330988218646a4707e8af3a3f4cc53c1c1ecd4` | 36961012794 | learning-review notification sync after approve; not every screenshot notification claimed fixed |

Ancestry: every rev is an ancestor of production head
`44ab1b3698cccfa1d12f367c65e12d3e53f1d3fe` (`git merge-base --is-ancestor`),
so all three use deploy run 36961012794.

Notes: 364 already received a post-deploy follow-up today (run 36976911105);
the out-of-grid session row is a separate data question and no data changed.
322 acceptance boundary: synthetic-fixture engineering tests only; no
production discount transaction was created and none is claimed. 300: the
reply does not claim every notification in the original screenshot is fixed
and does not cover low-session alerts; follow-up comment 811 already asked
the reporter to retry. GitHub issue #2909 is closed, which is not acceptance.

## Excluded

None.

## Gates and execution

Exact-head required checks; fresh per-ID state before any write. After merge,
dispatch one ID at a time via existing `bug_id`. Reporter acceptance is not
fabricated. This PR dispatches nothing.

## Recovery

Revert through a normal PR if metadata is wrong; no business or schema
mutation. Later reporter failure follows the existing reopen path.
