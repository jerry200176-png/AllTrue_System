# Scoped Phase-C for shipped in-app batch 3 2026-10-02

## Exact scope and authority

Three fixed per-ID records (293, 358, 352) in the existing single-target
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
| 293 | #2809 | Founder decision 2026-10-02: tell reporter RFID is live and close. PR #3334 (swipe counts as attendance + deduction, only against a real session, forgot-swipe-out backfill, deduction-failure staff alert) and PR #3410 (swipe photo pushed to verified parents via LINE; needs the reader to upload). rev is the later merge (#3410). Supersedes the older presence-only plan. | `9eb132c0e6193db31c28edc7b191553821a485ee` | 36961012794 | Swipe counts as attended and deducts one lesson when it matches a real session; otherwise self-study, no deduction. Photo push exists but only fires when the reader uploads. Reply does not claim teacher manual attendance is unchanged (not stated in the PR bodies). Screen not operated in production. Acceptance pending. |
| 358 | #3216 | PR #3366; Founder decision 2026-10-02: school and grade only; phone and notes are NOT added to the course overview (stay on the student page because teachers also see the overview) | `ace82171977af109cdecd357d3ccbf812be68396` | 36961012794 | Course lookup student header shows school and grade; phone/notes explicitly not added. Screen not operated in production. Acceptance pending. |
| 352 | #3234 | PR #3261; close confirmation and action done in place on course search, shared with Student Management; existing close rules unchanged | `89a6c52051a95d3591afa30c2ee40526b3413f6b` | 36961012794 | Close from course search in the same page without jumping to Student Management. Screen not operated in production. Acceptance pending. |

Ancestry: every rev is an ancestor of production head
`44ab1b3698cccfa1d12f367c65e12d3e53f1d3fe` (`git merge-base --is-ancestor`),
so all three use deploy run 36961012794.

## Excluded

None.

## Gates and execution

Exact-head required checks; fresh per-ID state before any write. After merge,
dispatch one ID at a time via existing `bug_id`. Reporter acceptance is not
fabricated. This PR dispatches nothing. Known limit: the swipe-photo push
needs reader-side upload and its post-deploy live LINE test was unchecked in
#3410; the 293 reply says so.

## Recovery

Revert through a normal PR if metadata is wrong; no business or schema
mutation. Later reporter failure follows the existing reopen path.
