# In-App326 fixed-target Phase-C closeout

SourceRef `alltrue:bug_report:326`; canonical issue3083. Application UI/API
capability is unavailable in this batch: no explicitly authorized application
session or credential injection exists. No credential search or new identity.
Follow the existing In-App329 delivery closeout procedure.

Only register target326 in the existing Phase-C writer, with regression and
canonical task provenance. Mechanical workflow change is R3/T3; exact-head
required checks, independent review and explicit protected merge/dispatch
approval remain required. This document grants no authority.

Fresh detail run36250366033 confirms triaged, comments790/791 (791 public),
status log1108 and no later reporter reply. Bind the exact existing notice791
text and expected log1108. Transactional guards reject any changed history,
reply or status before writes. Existing single-target parsing, role, service,
revision ancestry, transitions and idempotency are unchanged.

Original fix3156 head ea07fbce9422e4d0d5c914eef73d8f149de8fa44 merged as
449931d6bf82d8b77f2a944a9a9fc58ed69e9e9a; deployment35539903948 succeeded.
Current healthy production2cbb74bf87a1c22dbe14fd30daf43847b23389c0 contains
that revision. Writer records the original merge revision and matching deploy.
Saved 11-test browser regression covers the 900px receivables/receipt actions;
relevant source/test/mount/viewport files are byte-identical between tested
ad2f90260d4914611ce24f4778aafd8f4742b101 and current production. Pre-fix source
contract fails as expected. Reuse valid evidence without another application
deploy or product test run. Published notes
staff-2026-09-21-tuition-actions-reachable-326 remain applicable.

Product display fix is R1; engineering delivery follows the current R1
Evidence Contract. Production affected reporter-path observed NO; reporter
accepted PENDING. Never call reporter-verify or equate resolved with acceptance.

After protected approval: merge only the reviewed exact head/base; refresh
latest detail, abort on new failure/reopen, then dispatch
bug-phase-c-allowlist.yml once with bug_id=326 on the approved integrated main.
Existing locked transaction performs triaged -> in_progress -> resolved,
records original revision/deploy, and reuses notice791 without a duplicate.
Read back complete status/comments/logs/revision evidence. Issue3083 may track
other SourceRefs: no unconditional issue close.

Rollback: stale evidence/history causes transaction rollback before any write;
failed execution rolls back its transaction. Stop on unexpected result.
After committed success, use the established reopen path for a new failure;
no direct database reversal. Remove/correct metadata only through normal PR
and protected approval. No changes to accounts, billing, schema or app release.
