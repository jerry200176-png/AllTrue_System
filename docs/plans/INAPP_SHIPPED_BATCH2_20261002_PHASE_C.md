# Scoped Phase-C for shipped in-app batch 2 2026-10-02

## Exact scope and authority

Six fixed per-ID records (368, 370, 371, 372, 373, 330) in the existing
single-target writer (372 and 373 share one merge), each bound to an
exact merge commit on `origin/main`. No allowlist gate, actor, permission,
evidence requirement, writer or environment change. Lifecycle write is
existing product-loop closeout (`INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md`).
This companion is mechanically R3/T3 (protected workflow path).

Evidence level: engineering tests (merged PR CI) and production version check
only. Production `deployment.json` backend_sha = frontend_build_sha =
`7888fd85e353c12c45f61d49d3834fdbda277a46`, Founder-approved deploy run
36955378940 (success). No production UI check was performed this round;
replies say so, and reporter acceptance is pending for every ID.

## Included

| In-app | Issue | Basis | rev (merge) | Deploy run | Reply claims |
|---|---|---|---|---|---|
| 370 | #3357 | PR #3382; Founder decision 2026-09-30 (planning estimate from fixed schedule; manual exception kept; billing by confirmed attended sessions) | `2eae2c608c6f1252d03db9668bb5e469ebc48935` | 36955378940 | Course edit form shows an auto-estimated monthly planning count, editable; planning only, billing still by confirmed attended sessions. Screen not operated in production. Acceptance pending. |
| 371 | #3421 | PR #3422; frontend wiring only; contract-split request deferred as a product decision | `f369738a53f8152b1c061b13f866e82b80de025f` | 36955378940 | Both buttons work again; in-page contract split stated as deferred (not claimed fixed). Screen not operated in production. Acceptance pending. |
| 372 | #3423 | PR #3424; same root cause as 373 (one PR) | `a49f307f3b46a5a9794d5e96feb5182f790921e6` | 36955378940 | Used-up count courses no longer hold weekly 1:3 seats, so create/renew is no longer blocked by them; courses with future sessions still block. Screen not operated in production. Acceptance pending. |
| 373 | #3423 | PR #3424; same root cause as 372 (one PR) | `a49f307f3b46a5a9794d5e96feb5182f790921e6` | 36955378940 | Same as 372, reply phrased for the 'slot shown full' report. Screen not operated in production. Acceptance pending. |
| 368 | #3355 | PR #3337; shipped capability; no data was moved by the agent (separate one-case repair #3331 not executed and not claimed) | `868d3865fe858c3cd5820e74e34e2aafa27a2106` | 36955378940 | Director can move a single session to another contract of same student/subject via calendar single-session dialog; reporter must perform the move. Screen not operated in production. Acceptance pending. |
| 330 | #3103 | PR #3152; omitted auto_approve now fails closed to pending; form unchecked by default | `0ab77b41b6346fcb1beeacb2609b03b5a9fd4244` | 35530419241 | Make-up no longer auto-approves the assessment; explicit opt-in remains. Screen not operated in production (director acceptance was blocked by missing smoke credentials). Acceptance pending. |

Ancestry: every rev is an ancestor of production head `7888fd85e353c12c45f61d49d3834fdbda277a46`
(`git merge-base --is-ancestor`). Deploy 36955378940 has head `7888fd85e`; 330
uses its own earlier successful deploy run 35530419241 (head `0ab77b41b`).

## Excluded

| In-app | Issue | Reason |
|---|---|---|
| 295 | #2904 | PR #2980 adds a manual 「標記不需回覆」 action; it does not make ended conversations leave the to-do list by themselves. Exact affected conversation and teacher-home user path remain unproven and the 2026-09-24 reconciliation says not to dispatch Phase C. Partial against the report. |

## Gates and execution

Exact-head required checks; fresh per-ID state before any write. After merge,
dispatch one ID at a time via existing `bug_id`. Reporter acceptance is not
fabricated. This PR dispatches nothing. 371's in-page contract-split request
and 368's separate one-case repair (#3331) remain open and are not claimed.

## Recovery

Revert through a normal PR if metadata is wrong; no business or schema mutation.
Later reporter failure follows the existing reopen path.
