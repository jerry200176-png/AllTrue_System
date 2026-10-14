# ADR-POP-015: Standing-Policy Approval for In-App Report Lifecycle Write-Backs

| Field | Value |
|-------|-------|
| Status | Proposed (Founder design review) |
| Lifecycle | draft |
| Date | 2026-10-14 |
| Confidence | 75% |
| Risk | Medium |
| Revisit | 2027-01 |

## Context

Founder decision 2026-10-14 (`FOUNDER_STANDING_DECISIONS.md` 2026-10-14 (II), #4069) pre-approves exactly three production writes:

- (a) Phase A triage: `new`→`triaged` plus a §3.8 public reply.
- (b) Phase C: `resolved` plus a reply. Only for reports that were really fixed and whose deploy was verified.
- (c) The 14-day reporter-timeout close, under the existing eligibility rules.

The same decision requires #3943/#3955 to drop the GitHub-runner→SSH write path and use the ADR-POP-008 Pi-local executor. Today POP lets an AI only `draft` and `dry-run`. Every execution needs a human approval in the DB (ADR-POP-002), and a machine identity can never approve. "Pre-approved" therefore cannot work unless something other than a person can approve these three operations. That something must still be a narrow, auditable, revocable rule.

## Decision

1. **Three catalog operations** (`operations/catalog.yaml`), domain `support`, `execution_authority: pop-pi-local`, `transaction_required: true`, `verification_required: true`:
   - `inapp-report-triage`: params `bug_id`, `github_issue_url`, `public_reply`, `request_id`.
   - `inapp-report-resolve-after-deploy`: params `bug_id`, `github_issue_url`, `public_reply`, `fix_commit_sha`, `deploy_run_id`, `request_id`.
   - `inapp-report-reporter-timeout`: params `bug_ids` (each ID is checked on its own), `request_id`.

   A public reply cannot be unsent, so they are marked `reversible: false`. A status change is recorded in `BugReportStatusLog`, and a mistaken one is corrected by a new status change, never by deleting history.

2. **One approval policy**, `founder-standing-inapp-lifecycle` (`operations/policies/inapp-lifecycle.yaml`), pinned by version at approval time (ADR-POP-004). It approves a request **only if every machine check passes**. Missing or unknown evidence denies the request (fail closed).
   - **Common checks:**
     - The operation is one of the three above.
     - The `request_id` has never been seen.
     - The report exists.
     - The reply passes the existing §3.8 validator (from #3943), contains no PII patterns, and links to an issue in this repository whose SourceRef matches `bug_id`.
     - The daily cap per operation is not exceeded (default 20).
     - A successful dry-run of the same parameter digest is recorded.
   - **(a) triage:** the status is `new` at dry-run time, and again under a row lock at execute time.
   - **(b) resolve:**
     - The status is `triaged` or `in_progress`.
     - `fix_commit_sha` is an ancestor of the production `deployment.json` `backend_sha` (or of `frontend_build_sha` for a frontend fix), checked on the Pi against the deployed manifest.
     - `deploy_run_id` is the successful `deploy.yml` run whose `head_sha` equals the deployed SHA.
     - Health returned 200 after that deploy.
     - The executor never infers "fixed" on its own. The requester must attach the smoke result reference, and it is recorded.
   - **(c) timeout:**
     - Every ID passes the existing `listEligibleForReporterTimeout` check: `resolved` for 14 days with no reporter reply.
     - Each ID is rechecked under the existing row lock at execute time.
     - Ineligible IDs are skipped and reported. They are never closed, and never cause the rest to be refused.
     - A report whose reporter replied that the problem persists is ineligible by that existing rule.

   When the checks pass, the policy writes one `pop_approval_events` row with `approver_type=policy`, `approver_ref=founder-standing-inapp-lifecycle@<version>` and the Founder decision reference. **No human or machine user identity is used as approver.** Every other operation keeps its catalog quorum. This policy cannot be referenced by any other catalog entry; a CI contract test enforces that.

3. **Requester identity.** A new `ApiClient` purpose, `pop_inapp_agent`, with scopes `pop:draft` and `pop:dry-run`, restricted to the three operation IDs.
   - A super admin issues it in the app, with the same UX as the triage read-only token: shown once, 90-day TTL, revocable, hash stored only.
   - It is stored as a cloud environment secret, and the proxy injects it for `/api/v1/pop/...` draft and dry-run only.
   - It can never approve or execute. There is still no HTTP execute route; the Pi-local `pop:execute-approved` scheduler remains the only executor.

4. **Kill switches**, any one of which stops all three operations:
   - revoke the ApiClient;
   - set the policy `lifecycle: frozen`;
   - set the env flag `POP_INAPP_STANDING_POLICY=0` (default **off** until the Founder turns it on after the dry-run phase).

5. **Records.**
   - Each execution stores an IDs-only execution record (EXECUTION_RECORD_SCHEMA).
   - The agent comments on the report's GitHub issue with `bug_id`, the POP `request_id`, the operation, the verification result and the deploy run link. It never posts report text or personal data.

6. **Supersedes the SSH paths.**
   - #3943 and #3955 are closed once this lands. Their reusable parts are ported: the §3.8 validator, `request_id` idempotency, the `--reviewed-batch` eligibility semantics and the tests.
   - The existing dispatch-only workflows (`bug-phase-a-triage.yml`, `bug-followup-comment.yml`, `bug-reporter-timeout.yml` manual apply) stay for human use. Retiring their SSH steps is a separate follow-up.

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
