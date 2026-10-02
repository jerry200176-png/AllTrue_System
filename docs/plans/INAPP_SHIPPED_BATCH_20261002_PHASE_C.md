# Scoped Phase-C for shipped in-app batch 2026-10-02

## Exact scope and authority

Two fixed per-ID records (316, 331) in the existing single-target writer, each
bound to an exact merge commit on `origin/main`. No allowlist gate, actor,
permission, evidence requirement, writer or environment change. Lifecycle write
is existing product-loop closeout (`INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md`).
This companion is mechanically R3/T3 (protected workflow path). Both changes
implement Founder decisions of 2026-09-30 recorded in their PR bodies.

Evidence level: engineering tests (merged PR CI) and production version check
only. Production `deployment.json` backend_sha = frontend_build_sha =
`298ba1a594c3c3f989333713ec6712d971101c95`, deployed 2026-10-01T12:37Z. No
production UI check was performed this round; replies say so.

## Included

| In-app | Issue | Basis | rev (merge) | Deploy run | Reply claims |
|---|---|---|---|---|---|
| 316 | #3066 | PR #3365, Founder decision 2026-09-30 | `c2356c3ac0b7c681e74c1f7c17d72f28985c073a` | 36862070318 (head `298ba1a59`) | Shared-package rows stay listed with a "multi-subject shared" badge; duplicate rule, cancel and deduction unchanged. Screen not operated in production. Acceptance pending. |
| 331 | #3104 | PR #3367, Founder decision 2026-09-30 | `6cc2213a49c48bd432b5d221aa6b74bcd4a8c2df` | 36862070318 (head `298ba1a59`) | Two analytical reference cards (with/without tutoring, divided by 8 once); payroll and existing cards unchanged. Screen not operated in production. Acceptance pending. |

Ancestry: both revs are ancestors of the deploy head and production head
`298ba1a594c3c3f989333713ec6712d971101c95`.

## Excluded

| In-app | Issue | Reason |
|---|---|---|
| 358 | #3216 | PR #3366 shows school and grade only (Founder decision); reporter also asked for phone and notes, which stay out. Partial against the report; Founder to confirm closure wording. |
| 369 | #3356 | PR #3333 fixes calculation/renewal display, but the reporter's own record may still need the Founder-gated audited monthly correction, not done; issue lists it as unverified. |

## Gates and execution

Exact-head required checks; fresh per-ID state before any write. After merge,
dispatch one ID at a time via existing `bug_id`. Reporter acceptance is not
fabricated (both remain pending). This PR dispatches nothing.

## Recovery

Revert through a normal PR if metadata is wrong; no business or schema mutation.
Later reporter failure follows the existing reopen path.
