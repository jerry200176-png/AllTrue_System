# ADR-POP-015: Standing-Policy Approval for In-App Report Lifecycle Write-Backs

| Field | Value |
|-------|-------|
| Status | Accepted (Founder 2026-10-14, #4073) |
| Lifecycle | active |
| Implementation | (a) triage: implemented as `inapp-report-triage`, standing policy v3 (#5233, #5117). (b) and (c): implemented earlier (`inapp-report-resolve-after-deploy`, `inapp-report-reporter-timeout`). |
| Date | 2026-10-14 |
| Confidence | 75% |
| Risk | Medium |
| Revisit | 2027-01 |

## Context

The Founder decided on 2026-10-14 to pre-approve exactly three production writes:

- (a) Phase A triage: `new`→`triaged` plus a §3.8 public reply.
- (b) Phase C: `resolved` plus a reply, only for reports that were really fixed and whose deploy was verified.
- (c) The 14-day reporter-timeout close, under the existing eligibility rules.

The canonical record is `FOUNDER_STANDING_DECISIONS.md` 2026-10-14 (II), added by #4069. **This ADR grants nothing until #4069 is on `main`.** Implementation PRs must not merge before then, and the policy must cite that section.

The same decision requires #3943/#3955 to drop the GitHub-runner→SSH write path and use the ADR-POP-008 Pi-local executor. Today POP lets an AI only `draft` and `dry-run`. Every execution needs a human approval in the DB (ADR-POP-002), and a machine identity can never approve. "Pre-approved" therefore cannot work unless something other than a person can approve these three operations. That something must still be a narrow, auditable, revocable rule.

## Decision

1. **Three catalog operations** (`operations/catalog.yaml`), domain `support`, with `execution_authority: pop-pi-local`, `transaction_required: true` and `verification_required: true`. Every operation carries `campus_id`, which must equal each target report's `CampusID` and belong to the requester's campus scope. That scope is checked by the existing `PopOperationService::assertCampusScope()`; an empty or missing scope denies everything.
   - `inapp-report-triage`: params `campus_id`, `bug_id`, `github_issue_url`, `public_reply`, `writeback_proof`, `request_id`.
     - Only for reports the agent classifies as an engineering-required bug.
     - Through policy v4, suggestions and UX friction kept the human `close_as_logged` path. The Founder-authorized v5 extension below adds only `suggestion` and `ux_friction`; duplicates, not-planned and other dispositions remain outside the machine allowlist. Logged suggestions retain the canonical engineering-required classification: logging is not delivery.
   - `inapp-report-resolve-after-deploy`: params `campus_id`, `bug_id`, `github_issue_url`, `public_reply`, `fix_commit_sha`, `deploy_run_id`, `smoke_result_ref`, `writeback_proof`, `request_id`.
     - `smoke_result_ref` is a bounded string (≤200 chars, a repository URL or `run:<id>` form).
     - It is part of the immutable parameter digest, so dry-run, approval, execution and the execution record all see the same value.
   - `inapp-report-reporter-timeout`: params `campus_id`, `bug_ids` (each ID is checked on its own), `request_id`. The public reply is the existing fixed timeout message, not caller text.

   A public reply cannot be unsent, so these operations are `reversible: false`. A status change is recorded in `BugReportStatusLog`, and a mistaken one is corrected by a new status change, never by deleting history.

2. **One approval policy**, `founder-standing-inapp-lifecycle` (`operations/policies/inapp-lifecycle.yaml`), pinned by version at approval time (ADR-POP-004). It approves a request **only if every machine check passes**; missing or unknown evidence denies it (fail closed). When it denies, the request falls back to the catalog's normal human quorum (`super_admin`).
   - **Common checks (all three operations):**
     - The operation is one of the three.
     - The `request_id` has never been seen.
     - Every target report exists in `campus_id`.
     - The daily cap is counted in **target reports**, not requests (default 20 reports per operation per Asia/Taipei day). It is reserved atomically in the same transaction as the approval, under a per-operation/day lock, so concurrent evaluations cannot exceed it.
     - For (a) and (b): `writeback_proof` is the existing 15-minute `BugReportWritebackEvidence` proof from the triage detail API. It binds the report, its comments, attachments and status logs as the agent reviewed them. It is checked at dry-run and re-verified under row locks inside the write transaction. Any change since the review, or an expired proof, fails closed and needs a fresh review.
     - A successful dry-run of the same parameter digest is recorded.
   - **Reply checks, (a) and (b) only:** `public_reply` passes the existing §3.8 validator (from #3943) and contains no PII patterns. `github_issue_url` points to an issue in this repository whose SourceRef matches `bug_id`, and the reply contains that URL.
     - For (b), the reply must also meet the existing Phase-C reply contract: it contains both `確認已修好` and `問題仍存在` (the reporter-verification / retest request).
     - That contract is what lets `listEligibleForReporterTimeout()` later recognise the report.
   - **(a) triage:** the status is `new` at dry-run time, and again under a row lock at execute time.
   - **(b) resolve:**
     - The status is `triaged` or `in_progress`.
     - Execution follows `BugReportService::VALID_TRANSITIONS`: a `triaged` report moves `triaged`→`in_progress`→`resolved` inside the same locked transaction, and an `in_progress` report moves directly to `resolved`. Each step writes its own status log.
     - `fix_commit_sha` is an ancestor of the production manifest's `backend_sha` (or of `frontend_build_sha` for a frontend fix), read on the Pi.
     - `deploy_run_id` names a `deploy.yml` run whose `head_sha` equals that manifest SHA **and whose production deploy job (`Deploy to Production`) concluded `success`**. A run that only did `parent-portal-smoke`, `pop-bootstrap` or classification does not count.
     - After that deploy, the manifest SHA on the Pi equals the run's target, and health returned 200.
     - `smoke_result_ref` is present.
   - **(c) timeout:**
     - The policy counts the **whole** eligible queue (`listEligibleForReporterTimeout`, 14-day window). If it exceeds `AUTO_MAX_PER_RUN` (20), the policy denies, and closing that pile needs the human reviewed-batch path. Since Founder 2026-11-17 the daily `--auto` run closes the oldest 20 each day, so a pile drains without that path.
     - Otherwise every listed ID must be eligible. Each is rechecked under the existing row lock at execute time.
     - An ID that becomes ineligible is skipped and reported, never closed.
     - A report whose reporter replied that the problem persists is ineligible under the existing rule.

   **What a passing policy decision does.** It is executable state, not just an audit row. In one transaction, through the same code path as a human approval:
   - it moves the request to `approved`;
   - it appends a `pop_approval_events` row using the **existing columns, with no migration**: `approver='policy:founder-standing-inapp-lifecycle@<version>'`, `approver_role='policy'`, `approver_id=NULL`, and the decision reference "FOUNDER_STANDING_DECISIONS 2026-10-14 (II) / #4069" in the existing reference/metadata field.
     - `localApproval()` and the quorum evaluation accept `approver_role='policy'` **only** for these three catalog operations.
     - Every other operation keeps rejecting it;
   - it signs the existing short-lived execution token bound to `operation_id`, strategy, deployed commit SHA, phase, TTL and parameter digest.

   No human or machine user identity is used as approver. Every other operation keeps its catalog quorum.

   **Write-back attribution.** `BugReportService::addComment()` and `changeStatus()` need a `User` id for `author_user_id`/`changed_by`.
   - Each `pop_inapp_agent` ApiClient records the super admin who issued it. Part 2 adds a nullable `IssuedByUserID` column to `ApiClient` (additive migration); a deploy carrying a migration always keeps Founder production approval.
   - Policy-approved write-backs are attributed to that user, explicitly as "issued credential on behalf of policy approval".
   - The approval event and execution record keep the policy identity, so the audit trail shows both who authorised the credential and that a policy approved the write.
   - No new service user and no change to `pop_approval_events`. If the issuer can no longer be resolved to an active super admin, execution fails closed.
 No other catalog entry may reference this policy; a CI contract test enforces that.

3. **Requester identity.** A new `ApiClient` purpose, `pop_inapp_agent`, with scopes `pop:draft` and `pop:dry-run`. It is restricted to the three operation IDs and to an explicit campus list; an empty list denies all.
   - A super admin issues it in the app, with the same UX as the triage read-only token: shown once, 90-day TTL, revocable, hash stored only.
   - It is stored as a cloud environment secret.
   - It can never approve or execute. There is no HTTP execute route; the Pi-local `pop:execute-approved` scheduler stays the only executor.

4. **Kill switches** for **policy-approved** requests, rechecked by the executor immediately before each execution and again inside the execute transaction, not only at draft time. They do not affect requests approved by the human `super_admin` fallback, which stays executable while the policy is frozen. Any one of them stops a policy-approved request, including one already approved:
   - the requesting ApiClient is revoked or expired;
   - the policy has `lifecycle: frozen`, or its version differs from the approval's pinned version;
   - the env flag `POP_INAPP_STANDING_POLICY` is not `1`. It defaults to **off** until the Founder turns it on after the dry-run phase.

   Revocation also moves every outstanding policy-approved, unexecuted request of that client to the existing terminal state `cancelled`, atomically, and appends a revocation event. No new POP request state is introduced.

5. **Records.**
   - Each execution stores an IDs-only execution record (EXECUTION_RECORD_SCHEMA), including `smoke_result_ref` and `deploy_run_id` for (b).
   - The agent comments on the report's GitHub issue with `bug_id`, the POP `request_id`, the operation, the verification result and the deploy run link. It never posts report text or personal data.

6. **Supersedes the SSH paths.**
   - #3943 and #3955 are closed once this lands. Their reusable parts are ported: the §3.8 validator, `request_id` idempotency, the eligibility semantics and the tests.
   - The existing dispatch-only workflows stay for human use. Retiring their SSH steps is a separate follow-up.

## Alternatives

- **A. Human approval per batch in the app (current POP model).** No approval-model change, but every batch needs a click, which contradicts "pre-approved". Kept as the fallback when the policy is frozen.
- **Runner→SSH request files (#3943/#3955 as written).** Rejected by the Founder on 2026-10-14.
- **Let the machine identity approve.** Rejected: it erases the requester/approver separation, and ADR-POP-002 forbids it.

## Trade-offs

| Pro | Con |
|-----|-----|
| Pre-approval is real, narrow and machine-checked | A new approver type (`policy`) must be audited and tested |
| No SSH, no runner secrets, Pi-local execution only | Needs a new scoped credential for the cloud agent |
| Every decision is pinned to a policy version and is replayable | Phase C still depends on the agent attaching honest smoke evidence |

## Consequences

**Rollout:**
1. This ADR is approved.
2. Implementation PR(s), covering catalog, policy, strategies, ApiClient purpose, tests and the CI contract. They merge under #4069 point 3: independent review, a green latest CI, no open P0/P1, and every thread dispositioned.
3. The Founder issues the credential.
4. A dry-run-only period.
5. The Founder flips the env flag to on.

**What this ADR does not grant:** data repair, migration, credentials beyond the one scoped client, new read access, or any other operation.


## v5 — limited suggestion and UX lifecycle (2026-11-15)

Authority: [Founder standing decision](../../governance/FOUNDER_STANDING_DECISIONS.md#inapp-suggestion-writeback). Catalog16/policy5 retain the same four operation IDs, requester purpose, campus scope, daily caps, kill switches and Pi-local executor. No new credential, API route, schema, scheduler or approval framework.

- `inapp-report-triage`: `disposition` is strictly `bug`, `suggestion` or `ux_friction`. Only new legacy six-key input is normalized to explicit `bug` before the existing exact-key/hash/idempotency checks; explicit null or other kinds are refused. Persisted old requests are never defaulted or re-hashed. Bug receipt/triage stays unchanged. The two logged kinds call `closeAsLogged` only from `new` and accept exactly: `已收進產品清單 <issue URL>。上線時會在這裡通知你；想補充直接回覆就會重開。` This receipt cannot claim a deployment. Logging preserves the canonical engineering-required flag and does not mean shipped.
- `inapp-report-resolve-after-deploy`: a bug still resolves through the existing statuses and both retest outcomes. A currently closed-as-logged suggestion/UX for that same issue instead uses `notifyLoggedSuggestionShipped`, retaining closed status. Exact actual deploy job/current manifest/fix ancestry and issue linkage/postdeploy smoke/health/fresh read proof remain hard requirements. The plan binds the latest close ID and kind; every `logged_ship:<closeId>:` record blocks a second notice, even with another revision.
- The logged branches reject unresolved attribution and an unanswered latest reporter message. The v6 correction below preserves existing staff-tool attribution for self-filed admin reports. Safety/campus/proof/kill-switch checks and close-cycle state are rechecked under lock. Triage respects an existing explicit classification; changing it requires staff review. The bug resolution path accepts only an explicit bug or deliberately unclassified legacy data. Unsupported kinds remain hard errors even with human fallback approval.
- Execution and public comment/evidence commit atomically. POP re-plans before verify; an already posted notice correctly makes the fresh plan ineligible. The logged verifier therefore checks the immutable execution receipt against actual close cycle, public comment and append-only production evidence, rather than taking empty deployment fields from a refused repeat plan.
- Catalog16 and policy5 deliberately invalidate old pins; stale drafts/approvals require a new reviewed request, not an automatic retry or resign. Code rollback does not unsend public comments, remove append-only evidence or undo report status changes. Existing native activation and rollback gates still apply.

Suggestions merely logged remain tracked in their GitHub product issue; only deployment-backed notification marks shipped. Neither path fills `reporter-verify`.

### v6 correction: self-filed admin reports

Catalog17/policy6 correct v5's blanket author-equals-reporter refusal. Existing `BugReportService::isTrackedStaffReply` already documents that staff tooling can use the issuing admin's user ID for a self-filed report. This correction preserves that attribution, with no new identity, credential, route, schema or operation.

- Only a successful execute record for the two logged lifecycle operations may identify an earlier machine comment. Match its exact request/strategy, report/campus, comment ID, author, body hash, issue and current close cycle. Reuse canonical parameter hashing; text, an old acknowledgement, failed/dry-run/verify-only records or copied wording do not prove origin. Historical origin does not depend on the request's later status or verify timing.
- A missing or late execute record remains untrusted. Existing comment/execute-record commit timing is unchanged; a crash without a record requires diagnosis, not guessed provenance, automatic record repair or duplicate public replies.
- An authenticated admin's public HTTP reply to their own `closed_as_logged` suggestion/UX report uses the existing reopen behavior. Reporter identity, closed state, latest close marker and kind are rechecked under the report/status-log locks. Other staff, private notes, duplicate/not-planned and admin legacy timeout/bug paths retain their behavior. Machine lifecycle calls use the service directly and do not impersonate this human reply.
- Progress macros, reporter verification, approvals, scope, safety, MAC, caps, kill switches and deployment evidence stay in force. Old pending requests and approvals are not repaired or reused after the version bump. Logging, shipping and reporter acceptance remain separate.
