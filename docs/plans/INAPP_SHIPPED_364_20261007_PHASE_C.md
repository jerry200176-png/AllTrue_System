# Scoped Phase-C for shipped in-app 364 2026-10-07

## Exact scope and authority

One fixed per-ID record (364) in the existing single-target writer, bound to
an exact merge commit on `origin/main`. No allowlist gate, actor, permission,
evidence requirement, writer or environment change. Lifecycle write is the
existing product-loop closeout (`INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md`).
This companion is mechanically R3/T3 (protected workflow path) and needs
Founder GO before merge.

History: the same entry was proposed in #3448 and closed without dispatch by
the Founder decision of 2026-10-02 (report kept reporter-pending). This PR
re-proposes it for that gate; it does not override the earlier decision.

Evidence level: engineering tests (merged PR CI) and production version check
only. The fix merge `44ab1b3698cccfa1d12f367c65e12d3e53f1d3fe` (#3428) was
deployed by run 36961012794 and is an ancestor of the current production head
`9bfa8cba` (`git merge-base --is-ancestor`). No production UI check was
performed; the reply says so, and reporter acceptance is pending.

## Included

| In-app | Issue | Basis | rev (merge) | Deploy run | Reply claims |
|---|---|---|---|---|---|
| 364 | #3228 | PR #3428: the 只看有課老師 filter now uses the same matcher as the 08:00–22:00 day grid, so a teacher whose only row falls outside the grid no longer shows as an empty column | `44ab1b3698cccfa1d12f367c65e12d3e53f1d3fe` | 36961012794 | Filter matches the grid; out-of-grid source rows were not changed and still need a separate data check. Screen not operated in production. Acceptance pending. |

## Excluded

- 343 (#3196): fixed by #3495, but attachments #289/#290 have not been viewed
  to confirm the reported row is the same occurrence. Waits for the Founder.

## Gates and execution

Exact-head required checks; fresh per-ID state before any write. After merge,
dispatch via the existing `bug_id` request. Reporter acceptance is not
fabricated. This PR dispatches nothing.

## Recovery

Revert through a normal PR if metadata is wrong; no business or schema
mutation. A later reporter failure follows the existing reopen path.
