# Scoped Phase-C for shipped in-app 334 2026-10-07

## Exact scope and authority

One per-ID record (334) in the existing single-target writer, bound to merge
`f3aa9efca6b235d0ddf049a29a0fd396eea1bd97`. That merge is contained in production deploy run 37563232975 (success, head
`1d8b893e`, `git merge-base --is-ancestor`). No allowlist gate, actor,
permission, evidence requirement, writer or environment change. Mechanically
R3/T3 (protected workflow path); needs Founder GO before merge.

## Included

| In-app | Issue | Basis | rev (merge) | Deploy run |
|---|---|---|---|---|
| 334 | #3139 | #3500: director learning-record form opens the clicked ClassSession instead of defaulting to the first live session of the day (learningRecordSessionPolicy helpers + tests). | `f3aa9efca6b235d0ddf049a29a0fd396eea1bd97` | 37563232975 |

Evidence level: engineering tests (merged PR CI) and production version check
only. No production UI check; the reply says so, and reporter acceptance is
pending. The reply gives a no-names reopen path.

## Gates and execution

Exact-head required checks; fresh per-ID state before any write. After merge,
dispatch via the existing `bug_id` request. This PR dispatches nothing.

## Recovery

Revert through a normal PR if metadata is wrong; no business or schema
mutation. A later reporter failure follows the existing reopen path.
