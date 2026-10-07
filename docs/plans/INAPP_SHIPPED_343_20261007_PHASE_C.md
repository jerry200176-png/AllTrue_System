# Scoped Phase-C for shipped in-app 343 2026-10-07

## Exact scope and authority

One fixed per-ID record (343) in the existing single-target writer, bound to
an exact merge commit on `origin/main`. No allowlist gate, actor, permission,
evidence requirement, writer or environment change. Lifecycle write is the
existing product-loop closeout (`INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md`).
Mechanically R3/T3 (protected workflow path); needs Founder GO before merge.

Same-occurrence evidence: the coordinator viewed attachments #289/#290 on the
Founder's request (2026-10-07, comment on #3196). StudentClass 2819 has a
cancelled 08/14 ClassSession at 13:00 next to a 10:00 template, which is the
#3495 root cause. IDs only, no PII.

Evidence level: engineering tests (merged PR CI, `PausedCourseProjectionTest`)
and production version check only. Merge `afce6ff6f6b0f38396a2e872fe855eb53610375c`
(#3495) first shipped in deploy run 37505389591 and is an ancestor of the
current production head `1d8b893e` (`git merge-base --is-ancestor`). No
production UI check was performed; the reply says so, and reporter acceptance
is pending.

## Included

| In-app | Issue | Basis | rev (merge) | Deploy run | Reply claims |
|---|---|---|---|---|---|
| 343 | #3196 | PR #3495: a cancelled row occupies its date in `buildSessionDatesSplit`, so a cancelled lesson whose start time differs from the template no longer leaves a projected 預排 chip on the same date | `afce6ff6f6b0f38396a2e872fe855eb53610375c` | 37505389591 | The same date no longer shows both 預排 and cancelled; no lesson or deduction data changed. Screen not operated in production. Acceptance pending. |

## Excluded

None.

## Gates and execution

Exact-head required checks; fresh per-ID state before any write. After merge,
dispatch via the existing `bug_id` request. Reporter acceptance is not
fabricated. This PR dispatches nothing.

## Recovery

Revert through a normal PR if metadata is wrong; no business or schema
mutation. A later reporter failure follows the existing reopen path.
