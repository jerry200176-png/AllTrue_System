# Scoped Phase-C for shipped in-app batch 2026-09-29

## Exact scope and authority

One fixed per-ID record (353) in the existing single-target writer, bound to its
exact product merge and a successful deploy run. No general allowlist, actor,
permission, evidence requirement, writer or environment gate changes. Lifecycle
write is existing product-loop closeout under the R1 rule
(`INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md`, production discipline). This
companion is mechanically R3/T3 (workflow path); the product fix is R1/T1.
Nine candidates were reviewed; eight are deliberately excluded (below).

## Included

| In-app | Issue | PR | rev (merge) | Deploy run | Reply claims |
|---|---|---|---|---|---|
| 353 | #3201 | #3320 (R1/T1) | `3056e8ccd9240b6e7086565308516f7832765f8c` | 36527079538 (head `86938751a`, success) | Historical (completed / settled / pending-reconciliation) course badges in Students now carry a "歷史" marker. Does not claim the reporter's specific Math 0-session course was identified, nor any data, count, filter, payment or renewal change. Reporter acceptance pending. |

Ancestry: `rev` is an ancestor of the deploy head and of production head
`89ebc0d1c3f293441d710b4ce19da9037cdbc097` (detail dump 2026-09-29T14:52Z).

## Excluded

| In-app | Issue | Merge | Reason |
|---|---|---|---|
| 352 | #3234 | `89a6c5205` | Product fix declared R3/T3 (writes via close/pause endpoint). Issue records production user-path NO with no safe fixture; R2/R3 need direct affected-path evidence. Reporter already has a public deploy note. |
| 351 | #3206 | `2b6a52f82` | PR declared R3/T3 (billing prompts). Reporter's clarification question unanswered; production affected-path not observed. |
| 350 | #3212 | `7ae45b00d` | PR declared R3/T3 (billing center). Original receipt locator never supplied; only the query exposure is proven, production path not observed. |
| 347 | #3197 | `315171951` | Only message wording; issue states the disputed capacity rejection stays unverified and this does not close it (R2). |
| 330 | #3103 | `0ab77b41b` | R2 backend default change; issue #3103 still holds for production assertion (needs-decision label). |
| 325 | #3075 | `b7250094c` | Billing semantics (protected); issue records no production tutoring fixture, business assertion never ran. |
| 322 | #3072 | `a98a6c9c3` | Billing/discount capability (R3); no production assertion; source campus differs from approved acceptance identity. |
| 318 | #3068 | `08038b8ac` | Issue holds for source-campus (11) evidence or explicit scope decision; campus-16 slice only. |

## Gates and execution

Exact-head required checks and independent review; fresh per-ID state before any
write. After merge, dispatch 353 only via existing manual `target_bug_id`;
preserve ancestor and service evidence checks. Verify: resolved, bounded public
reply, correct revision/deploy evidence, reporter acceptance not fabricated.
Production user-path observation is NO; reporter accepted remains PENDING. No
request-file push, no app deploy dispatch.

## Recovery

Revert this companion through a normal PR if metadata is wrong; no business or
schema mutation. Later reporter failure follows the existing reopen path.
