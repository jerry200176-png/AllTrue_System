# Scoped Phase-C for in-app 365 2026-10-07

## Exact scope and authority

One per-ID record (365) in the existing single-target writer, bound to merge
`59daf9a39f1b66d6b6063f185f203955ec882a8f` (#3499), first deployed by run
37465434964 and an ancestor of production `1d8b893e`. No allowlist gate,
actor, permission, evidence requirement, writer or environment change.
Mechanically R3/T3 (protected workflow path); needs Founder GO before merge.

## Included

| In-app | Issue | Basis | rev (merge) | Deploy run | Reply claims |
|---|---|---|---|---|---|
| 365 | #3229 | Close as explained, no code defect: the latest authorized read-only probe 36130927849 (2026-09-25) shows no other-campus session for this teacher in the 2026-09-26 window, only a campus-9 10:00–12:00 session, so no cross-campus hint is correct. The earlier campus-16 03:00–17:00 row no longer exists. rev #3499 is the current live availability logic. | `59daf9a39f1b66d6b6063f185f203955ec882a8f` | 37465434964 | Data checked; no cross-campus lesson existed in that window, so no hint is correct; no data changed. Screen not operated in production. Acceptance pending. |

## Gates and execution

Exact-head required checks; fresh per-ID state before any write. After merge,
dispatch via the existing `bug_id` request. Reporter acceptance is not
fabricated. This PR dispatches nothing.

## Recovery

Revert through a normal PR if metadata is wrong; no business or schema
mutation. A later reporter failure follows the existing reopen path.
