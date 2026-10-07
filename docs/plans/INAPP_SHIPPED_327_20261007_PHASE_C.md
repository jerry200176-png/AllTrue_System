# Scoped Phase-C for shipped in-app 327 2026-10-07

## Exact scope and authority

One per-ID record (327) in the existing single-target writer, bound to merge
`59daf9a39f1b66d6b6063f185f203955ec882a8f`. That merge is contained in production deploy run 37563232975 (success, head
`1d8b893e`, `git merge-base --is-ancestor`). No allowlist gate, actor,
permission, evidence requirement, writer or environment change. Mechanically
R3/T3 (protected workflow path); needs Founder GO before merge.

## Included

| In-app | Issue | Basis | rev (merge) | Deploy run |
|---|---|---|---|---|
| 327 | #3084 | #3499: cross-campus busy collector now uses the booking guard's live-row rules. AvailabilityCapacityTest with mutation checks. | `59daf9a39f1b66d6b6063f185f203955ec882a8f` | 37563232975 |

Evidence level: engineering tests (merged PR CI) and production version check
only. No production UI check; the reply says so, and reporter acceptance is
pending. The reply gives a no-names reopen path.

## Gates and execution

Exact-head required checks; fresh per-ID state before any write. After merge,
dispatch via the existing `bug_id` request. This PR dispatches nothing.

## Recovery

Revert through a normal PR if metadata is wrong; no business or schema
mutation. A later reporter failure follows the existing reopen path.
