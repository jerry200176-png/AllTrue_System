# Scoped Phase-C for shipped in-app 319 2026-10-07

## Exact scope and authority

One per-ID record (319) in the existing single-target writer, bound to merge
`5b90abf6305aa051fd5a5fa4e0e6c04fe8e0c48b`. That merge is contained in production deploy run 37563232975 (success, head
`1d8b893e`, `git merge-base --is-ancestor`). No allowlist gate, actor,
permission, evidence requirement, writer or environment change. Mechanically
R3/T3 (protected workflow path); needs Founder GO before merge.

## Included

| In-app | Issue | Basis | rev (merge) | Deploy run |
|---|---|---|---|---|
| 319 | #3069 | #3449 (batched same-day schedule repair queries, loaded-state display) + #3492 (per-row latest sign-in/learning-record lookups batched; ClassSessionIndexLatestRowsTest). | `5b90abf6305aa051fd5a5fa4e0e6c04fe8e0c48b` | 37563232975 |

Evidence level: engineering tests (merged PR CI) and production version check
only. No production UI check; the reply says so, and reporter acceptance is
pending. The reply gives a no-names reopen path.

## Gates and execution

Exact-head required checks; fresh per-ID state before any write. After merge,
dispatch via the existing `bug_id` request. This PR dispatches nothing.

## Recovery

Revert through a normal PR if metadata is wrong; no business or schema
mutation. A later reporter failure follows the existing reopen path.
