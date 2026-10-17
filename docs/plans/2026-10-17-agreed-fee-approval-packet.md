# Agreed-fee correction — exact-scope decision packet

Prepared locally, feature OFF. This packet requests no production access or actual account correction. The next proposed approval is **draft publication and nonproduction exact-head checks only**, for the candidates below, with #4132 owner coordination. Merge, deployment, migration execution, activation, credentials and live data repair remain separate protected decisions.

## Exact candidate dependencies and ownership

All candidates are local, unpushed. The scratch linear graph proves review boundaries; it is not permission to publish stacked PRs. After a dependency merges, create each subsequent PR fresh from current main, transplant its exact delta, reconcile upstream changes and rerun its scoped checks. No gate exemptions or dropped safety cases are proposed.

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

**Schema owner:** #4132 alone owns `2026_10_15_120000_create_invoice_corrections_table.php`. Its author must adopt the replay/retention changes; owner acceptance has not been obtained. The fee work owns only `2026_10_17_120000_extend_invoice_corrections_for_agreed_fee.php`. Do not publish a duplicate create-table migration. Existing receipt note trimming and receipt-producing controller remain #4132 behavior, isolated from fee-only operations; fee requests never call them.

## Behavior proposed for later product/schema approval

The authenticated director must have the student's campus; empty scope grants nothing. The existing super-admin exception remains. Positive count-mode single-subject trials are eligible; tutoring, free/zero fees, monthly and shared/package obligations are not. Never-paid or valid fully reversed payment history only; pending/confirmed reports, partial/unmatched/ambiguous payments, legacy paid markers and ambiguous ownership block.

Preview/apply POSTs at `/api/v1/student-classes/{id}/agreed-fee-correction/{preview|apply}` use scope, explicit original invoice for billed, positive integer new_charge, reason, stable UUID and a signed state-bound preview. Authenticated actor and fresh campus authorization bind preview/replay. One transaction writes permanent correction history, mandatory audit and Charge; it does not change entitlement, Rate, usage, dates or receipt/payment history.

- **Billed:** replace exactly one sole live unpaid obligation with one unpaid bill. Preserve original amount/items/payment/report history; mark original void and retain a replacement link. Charge and original invoice amount are separately disclosed.
- **Never billed:** require no invoice of any status, external course item or report history; update Charge and correction/audit only, with no invoice. This product behavior needs explicit adoption.
- Both create zero payments, reports, receipts, refunds or notifications. Pending 8000 cannot be repurposed as receipt8800: reject the old report explicitly, then correct the agreement, then use the separate normal report/receipt workflow if authorized. The synthetic 500/8000/8800/7200 amounts are not authority over any real account.

The shared extension adds kind/default `receipt_reissue`, campus, old/new Charge, original invoice total and hashes; enforces unique course/key; makes original/replacement invoice IDs and amount physically nullable to represent never-billed history. Nullability is not conditional on kind at the database layer; application writers/readers enforce the kind-specific references. This is an ALTERATION to a billing table, not merely additive DDL. Missing schema or duplicate legacy course/key stops adoption before ALTER; there is no automatic deduplication or historical account repair.

## Completed evidence and true remaining gates

New focused evidence: owner compatibility 16 tests/155 assertions; combined fee/receipt47/364; schema adoption2/9; two UI files/six tests. Independent GPT-6-astra review confirms the replay defect and regression gap are resolved. Prior broad backend evidence remains valid: 4085 non-concurrency cases plus12 separate-process MySQL concurrency cases, zero failures/errors, with11 skips and one incomplete. No broad suite was repeated for this continuation. The extracted harness separately passed all12 MySQL concurrency cases/271 assertions; a TCP-readiness race in the reproduction script was corrected before that rerun.

Locked PHPStan2.3.1 passes with zero errors on base, fee and combined heads. The earlier two ScheduleController diagnostics reproduce only with the preinstalled mismatched2.2.16 and do not constitute a new gate failure. Dependency manifests/locks are unchanged. Local Composer2.5.5 reports six existing advisories without severity metadata; raw audit exit is not the CI policy result. Replaying the exact ci.yml HIGH/CRITICAL parser passes with warnings. This neither upgrades dependencies nor waives security review; a new-head hosted audit remains required and is authoritative for its then-current metadata.

Five verified #4217 workflow_dispatch runs at45eaab46 passed: CI38995584529 (full PHPUnit, Vite, Vitest and Playwright), Security38995591766 (PHPStan), Docs38995590148, Secret38995588484 and Presubmit38995586517. Skipped ordinary draft event contexts do not erase those passes. They establish baseline evidence only, never hosted evidence for these new candidates.

Remaining gates are concrete: #4132 owner adoption, permission to publish the listed draft deltas/run nonproduction checks, exact-head hosted checks/review for each fresh-main slice, then separate Founder decisions for billing schema cutover, deployment and activation. Local preparation is not blocked by the earlier PHPStan/tool-version or raw audit-exit classifications. No production inventory/engine/load or actual-case agreement facts have been accessed or verified.

## Deployment risk and rollback boundary

Default OFF prevents fee use but does not make billing DDL harmless. Schema changes can lock the shared table, encounter duplicate legacy keys, or expose nullable-history assumptions in another consumer. Before any production deployment, approve the exact migration IDs/order, confirm target-engine compatibility and backup/restore evidence through the protected deployment process, and ensure the kind-aware reader is present before fee history can exist. Do not merge a migration-containing slice into an automatic deploy path without that separate decision.

Safe code rollback disables the fee feature and may revert fee UI/API, **while retaining the kind-aware receipt reader, history guards, shared schema and permanent rows**. Reverting the reader to pre-discriminator #4132 is unsafe after fee history exists, even with the fee flag OFF. `down()` deliberately refuses retained fee/null history; dropping history, inventing invoice IDs or restoring a backup is not an automatic rollback. Any correction of real financial data requires separately verified facts, precise targets, an immutable repair scope and protected approval.
