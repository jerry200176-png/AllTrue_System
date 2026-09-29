# Scoped Phase-C for verified / decided in-app batch 2026-09-30

## Exact scope and authority

Seven fixed per-ID records (350, 325, 351, 296, 318, 328, 292) in the existing
single-target writer, each bound to an exact merge commit on `origin/main`.
No allowlist gate, actor, permission, evidence requirement, writer or
environment change. Lifecycle write is existing product-loop closeout
(`INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md`). This companion is mechanically
R3/T3 (protected workflow path). Founder GO 2026-09-30 (in-session): close
verified/decided reports. Production read-only checks were made 2026-09-30 by
the main session as Super Admin in the production desktop app (build stamp
2026-09-29 23:58); nothing was saved.

## Included

| In-app | Issue | Basis | rev (merge) | Deploy run | Production evidence / reply claims |
|---|---|---|---|---|---|
| 350 | #3212 | fix PR #3319 | `7ae45b00d2933bc0dffd2b6be19ead180a1719ad` | 36527079538 (head `86938751a`) | Receipt records, status "voided records" lists a voided receipt of a settled (history) course; only payment-detail action, no re-void. PASS. Reporter's receipt not identified; acceptance pending. |
| 325 | #3075 | fix PR #3088 | `b7250094c679baa99a10c09281b478bd4955a950` | 35444435960 (head `b7250094c`) | Course lookup, class type tutoring: course at 0 per session / 0 total with a no-payment-needed badge and no payment-report button. PASS. Course-create form not exercised (stated in reply). |
| 351 | #3206 | fix PR #3318 | `2b6a52f82b1ee0427e26d79db50f4b34b5cc4642` | 36527079538 (head `86938751a`) | Same observation as 325. Course-create form not exercised. |
| 296 | #2905 | fix PRs #3015 / #3110 | `55c2b64196712e1bb7b7aa2b4da9f5b59f652485` (#3015; #3110 `231f0db32` also in prod) | 35441352677 (head `5e500102e`) | Edit student, school field opens suggestion panel with custom-name hint. PASS; no save performed. |
| 318 | #3068 | feature PR #3091 | `08038b8acc62b35af0b9d8bdf0901ec13c597db6` | 35443369856 (head `8cdb0927c`) | Print button present in production class-calendar header (observed at a different campus than the reporter's, 2026-09-29); earlier campus-16 acceptance run 35616952002 passed. Reply says so and asks reporter to retry. Acceptance pending. |
| 328 | #3102 | Founder decision 2026-09-30 | `99022e290ea742f85ebad3a943076fd01c3e9735` (#3079 horizontal-overflow fix) | 35441352677 (head `5e500102e`) | Keep horizontal layout for 1:3 cells; no new change; closure with explanation. |
| 292 | #2808 | Founder NO-GO (9/16), re-affirmed | `d18e26b91` (custom ambient tracks merge, defines the 60% cap and three tracks) | "" (no deploy binding, like 315) | Volume cap stays 60%; no new tracks; closure with explanation. |

Ancestry: every rev is an ancestor of its deploy head and of production head
`01692a87995a9998c26a4503d52600ae0c8d45a5` (bug-queue-dump run 36645195194,
2026-09-29T23:26Z). Nothing excluded.

## Gates and execution

Exact-head required checks; fresh per-ID state before any write. After merge,
dispatch one ID at a time via existing `bug_id`. Verify: resolved, bounded
public reply, revision/deploy evidence, reporter acceptance not fabricated
(fix closures 350/325/351/296/318 remain pending reporter acceptance). This PR
dispatches nothing.

## Recovery

Revert through a normal PR if metadata is wrong; no business or schema mutation.
Later reporter failure follows the existing reopen path.
