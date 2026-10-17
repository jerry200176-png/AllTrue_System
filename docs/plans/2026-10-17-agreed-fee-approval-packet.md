# Agreed-fee correction — exact-scope decision packet

Fee feature OFF; receipt-reissue capability independently OFF. Draft publication and nonproduction exact-head checks are authorized and underway. Codex owns remaining engineering after an unchanged-head/conflicting-writer check and transparent #4132 handoff; no human-owner pickup is required. No production access, merge, deployment, migration execution, activation, credentials or live-data repair is authorized.

The smallest next protected decision is the prerequisite merge packet below, after all exact-head checks pass. It does not authorize downstream stacked PRs or activation. No merge has occurred. If merging enters an automatic production deploy/migration path, a merge-only decision is insufficient: keep Draft until that deployment boundary is explicitly handled.

## Exact candidate dependencies and ownership

The fee slices remain local and unpushed; the adopted #4132 prerequisite is published as Draft. The scratch linear graph proves review boundaries; it is not permission to publish stacked PRs. After a dependency merges, create each subsequent PR fresh from current main, transplant its exact delta, reconcile upstream changes and rerun its scoped checks. No gate exemptions or dropped safety cases are proposed.

| Order | Immutable candidate | Required predecessor | Scope / measured gate lines |
|---|---|---|---|
| Base | `45eaab46d47ad0dbd4e03062cfa830dadf752010` | Existing #4217 | Reversal/guidance baseline; five permitted hosted workflows passed |
| Owner | `fc1699f0b6f34a77cea44f695cf9a177d52fc950` | Base | Local mirror of #4132 owner head `45cae614c4645325af6ff0f38e3fb79ce693b2f7`, exact owner scope on base; 699 lines |
| Compatibility | `fe96d13642579986c048cdcc97082c55a53c6d5a` + `95fa029e118352c2bff6a3fd73829f0b3e5a642e` | Owner | Kind/reference replay guard and retention-safe down; 65 lines combined. Transferable owner-side fix is `a3d928fee906ebbb1d82633c398b5b4204576a57` on original #4132 head |
| Shared history | `ca0c5b787f31a852fab0d73b287063c713ed5ce3` | Compatibility | Extension migration, history guard, isolated synthetic harness and schema tests; 263 lines |
| Fee API | `b67f9f8a3e870e32500112c0c4a4d377e30ab9b0` | Shared history | Default-OFF fee-only service/controller, authorization, reversal prerequisite and complete API/concurrency regression cases; 692 lines |
| Staff entry | `8211ccc467a6fe5621b46670eec70d24a57ac178` | Fee API | Server capability, ledger dialog and actual cross-kind collision checks; 208 lines |
| Display | `5c85eaa663709d7672a3ca1979725602639c7508` | Staff entry in this proof graph | Exact #4214 source `200214f4753a655eb106d1d9cbb1473becded020`; 93 lines. Can land independently after fresh-main conflict review |

Sizes use the actual presubmit exclusions, including its existing PHPStan-baseline exclusion. The largest new fee slice is 692; no line compression, exception or removed regression was used. Concurrency case bodies and data providers remain byte-identical; only reusable helper methods moved to a non-discovered abstract test harness. All production/test files in the sliced code head equal the independently tested combined checkout; only this documentation differs.

**Current prerequisite:** [#4132](https://github.com/jerry200176-png/AllTrue_System/pull/4132), exact remote head `663d6f80108b36f5acdf1cb544a4ef151240513a`, is Codex-owned, based on current main `8fc89ccb6e41009e607866735b89ab39643d9296`, with 699 total changed lines across seven files. Ordinary fast-forward updates preserve the original history; no force-push. Its staff UI/tests/announcement are preserved for the later entry slice. The original graph above is retained evidence, not the current remote prerequisite.

**Schema owner:** #4132 alone owns `2026_10_15_120000_create_invoice_corrections_table.php`. Codex has adopted the replay/retention fixes and independent review corrections on the existing branch. The fee work owns only `2026_10_17_120000_extend_invoice_corrections_for_agreed_fee.php`. Do not publish a duplicate create-table migration. Receipt note trimming remains #4132 behavior, isolated from fee-only operations; fee requests never call it. New literal `billing.receipt_correction_reissue_enabled = false` blocks the entire receipt-intent branch, including replay, before its transaction. It is not environment-driven. Fee-only correction does not depend on enabling it. Ordinary receipt requests retain their established path.

## Behavior proposed for later product/schema approval

The authenticated director must have the student's campus; empty scope grants nothing. The existing super-admin exception remains. Positive count-mode single-subject trials are eligible; tutoring, free/zero fees, monthly and shared/package obligations are not. Never-paid or valid fully reversed payment history only; pending/confirmed reports, partial/unmatched/ambiguous payments, legacy paid markers and ambiguous ownership block.

Preview/apply POSTs at `/api/v1/student-classes/{id}/agreed-fee-correction/{preview|apply}` use scope, explicit original invoice for billed, positive integer new_charge, reason, stable UUID and a signed state-bound preview. Authenticated actor and fresh campus authorization bind preview/replay. One transaction writes permanent correction history, mandatory audit and Charge; it does not change entitlement, Rate, usage, dates or receipt/payment history.

- **Billed:** replace exactly one sole live unpaid obligation with one unpaid bill. Preserve original amount/items/payment/report history; mark original void and retain a replacement link. Charge and original invoice amount are separately disclosed.
- **Never billed:** require no invoice of any status, external course item or report history; update Charge and correction/audit only, with no invoice. This product behavior needs explicit adoption.
- Both create zero payments, reports, receipts, refunds or notifications. Pending 8000 cannot be repurposed as receipt8800: reject the old report explicitly, then correct the agreement, then use the separate normal report/receipt workflow if authorized. The synthetic 500/8000/8800/7200 amounts are not authority over any real account.

The shared extension adds kind/default `receipt_reissue`, campus, old/new Charge, original invoice total and hashes; enforces unique course/key; makes original/replacement invoice IDs and amount physically nullable to represent never-billed history. Nullability is not conditional on kind at the database layer; application writers/readers enforce the kind-specific references. This is an ALTERATION to a billing table, not merely additive DDL. Missing schema or duplicate legacy course/key stops adoption before ALTER; there is no automatic deduplication or historical account repair.

## Completed evidence and true remaining gates

Latest focused evidence: exact prerequisite20 tests/189 assertions; combined fee/receipt51/400 (including fee success while receipt capability is OFF, then explicit synthetic future-activation collision); schema adoption2/9; two UI files/six tests. Independent GPT-6-astra reviewed exact head663d6f80108b36f5acdf1cb544a4ef151240513a and found no remaining scoped blockers. Review repaired fresh campus authorization, pending-report lock inversion, payment-payload replay mismatch and note0 identical retries. Baseline RED logs show200vs404/409 and500vs422 respectively. Prior broad backend evidence remains valid: 4085 non-concurrency cases plus12 separate-process MySQL concurrency cases, zero failures/errors, with11 skips and one incomplete. No broad suite was repeated for this continuation. The extracted harness separately passed all12 MySQL concurrency cases/271 assertions; a TCP-readiness race in the reproduction script was corrected before that rerun.

Locked PHPStan2.3.1 passes with zero errors on base, fee and combined heads. The earlier two ScheduleController diagnostics reproduce only with the preinstalled mismatched2.2.16 and do not constitute a new gate failure. Dependency manifests/locks are unchanged. Local Composer2.5.5 reports six existing advisories without severity metadata; raw audit exit is not the CI policy result. Replaying the exact ci.yml HIGH/CRITICAL parser passes with warnings. This neither upgrades dependencies nor waives security review; a new-head hosted audit remains required and is authoritative for its then-current metadata.

Five verified #4217 workflow_dispatch runs at45eaab46 passed: CI38995584529 (full PHPUnit, Vite, Vitest and Playwright), Security38995591766 (PHPStan), Docs38995590148, Secret38995588484 and Presubmit38995586517. Skipped ordinary draft event contexts do not erase those passes. They establish baseline evidence only, never hosted evidence for these new candidates.

Remaining gates are exact-head hosted checks for adopted #4132, the protected prerequisite merge decision, then fresh-main downstream slices with their own hosted checks/review. Separate Founder decisions still govern billing schema cutover, deployment and activation. Do not wait for another implementation owner. Local preparation is not blocked by the earlier PHPStan/tool-version or raw audit-exit classifications. No production inventory/engine/load or actual-case agreement facts have been accessed or verified.

## Deployment risk and rollback boundary

Default OFF prevents fee use but does not make billing DDL harmless. Schema changes can lock the shared table, encounter duplicate legacy keys, or expose nullable-history assumptions in another consumer. Before any production deployment, approve the exact migration IDs/order, confirm target-engine compatibility and backup/restore evidence through the protected deployment process, and ensure the kind-aware reader is present before fee history can exist. Do not merge a migration-containing slice into an automatic deploy path without that separate decision.

Safe code rollback disables the fee feature and may revert fee UI/API, **while retaining the kind-aware receipt reader, history guards, shared schema and permanent rows**. Reverting the reader to pre-discriminator #4132 is unsafe after fee history exists, even with the fee flag OFF. `down()` deliberately refuses retained fee/null history; dropping history, inventing invoice IDs or restoring a backup is not an automatic rollback. Any correction of real financial data requires separately verified facts, precise targets, an immutable repair scope and protected approval.

## Smallest prerequisite decision packet

1. [#4217](https://github.com/jerry200176-png/AllTrue_System/pull/4217), exact `45eaab46d47ad0dbd4e03062cfa830dadf752010`: reversal/guidance repair, no new fee schema/API. Its five exact-head workflows passed as recorded above.
2. [#4132](https://github.com/jerry200176-png/AllTrue_System/pull/4132), exact `663d6f80108b36f5acdf1cb544a4ef151240513a`: create permanent history table, kind-safe reader, guarded receipt-intent code. Default-OFF means no new receipt-producing authority is enabled by this preparation. These two PRs are independent current-main prerequisites; suggested order4217 then4132. Main has not moved and neither PR is merged.

#4132 exact-head permitted dispatches: CI39000928350; Presubmit39000930512; Secret39000932273; Docs39000934405; Security/PHPStan39000936204. Final conclusions are recorded in the handoff evidence; running or skipped contexts must never be called passed. The separate schema-migration dry-run, rollback readiness and provenance checks are also required evidence.

Protected schema scope: only `2026_10_15_120000_create_invoice_corrections_table.php`, no backfill, no live SQL, no ALTER of existing tables in4132. It is still a billing table, excluded from generic additive authorization. Populated-table down refuses to erase history; retain the kind-aware reader. Deployment-engine/backup facts are unverified. This packet does not approve migration execution, activation, real-account fixes, receipt issuance or notification.

After these dependencies merge under the protected process, rebase the preserved schema/API/UI deltas onto fresh main without stacked PRs, keep both capability defaults OFF, rerun exact-head CI/review, and integrate the separate4214 display fix (`200214f4753a655eb106d1d9cbb1473becded020`). The full combined prepared tree is `28340368` and refreshed slice graph `7ccc259a`; earlier immutable evidence remains preserved.
