# Legacy in-app bug evidence backfill — Founder decision packet

**Date:** 2026-09-07 (Asia/Taipei)
**Status:** Blocked at the schema / audit-semantics gate; no production write was
performed for this packet.

## Current production snapshot

The fresh, paginated in-app Bug inventory contained 253 records:

Production evidence anchor for the related code audit was the observed
production head `a4d2be72446fc87b6f068537d6ef8d0ae97f176b`. This packet records
inventory classification only; it is not a claim that every historical PR was
independently behavior-tested at that revision.

| Status | Count |
|---|---:|
| `closed` | 142 |
| `resolved` | 111 |
| `new` / `triaged` / `in_progress` | 0 |

Of the 111 resolved records, only 6 have the machine-readable
`[resolution_evidence]` marker required by the Evidence Contract. The other
105 are historical resolves created before that gate existed. They are not
eligible for the reporter timeout workflow, and they must not be mass-closed.

The current queue is therefore not an open-engineering queue; it is a
resolved-but-not-evidence-complete queue. `resolved -> closed` remains limited
to reporter verification or the evidence-backed seven-day timeout.

The exact 105 legacy IDs in this classification are:

```text
2,3,4,5,6,7,8,9,12,13,16,17,18,19,20,21,22,23,24,25,26,28,29,30,31,32,33,37,38,39,41,42,43,44,45,46,47,48,54,55,56,57,58,61,62,63,65,66,67,69,70,71,72,76,77,80,81,83,84,85,86,87,88,89,90,91,92,94,95,96,97,98,99,104,105,109,114,115,116,122,123,124,126,127,128,129,133,135,136,143,149,150,151,158,159,161,162,168,174,175,180,189,190,191,192
```

## Evidence classification

### Candidate set for a future read-only production re-verification

These nine bugs have an unambiguous public explanation, a merged PR reference,
a historical successful deployment record, and a public comment at or after the
latest resolve. Their historical PR commits are not all the current production
revision, so the old deployment record alone is insufficient. The next
evidence step is a bounded, bug-specific read-only production probe against the
current production code.

| In-app bug | Historical PR | Current-main equivalent / proof | Historical deploy run | Notes |
|---:|---:|---|---:|---|
| #94 | #369 | `9cff1a547de6054d112d79a708a14780dd44a9fb` is an ancestor of the current production head | 25956700099 | Teacher field after async list load; follow-up comment #580 posted by run 34080284189 |
| #105 | #367 | `b903a5acda725780d412e88c1f732479f5fb3ca2` is an ancestor of the current production head | 25955438291 | Same-campus/date filtering and historical evaluation range |
| #114 | #431 | `13bb2dde79c73de8ad09416b8fb8071a343ac779` is an ancestor of the current production head | 26320075422 | End-date / stopped-course calendar visibility |
| #115 | #431 | Same as above | 26320075422 | Chat attachment button |
| #116 | #431 | Same as above | 26320075422 | Mobile chat input position |
| #124 | #499 | `ab947333e8e747f0d6241528901a9bb2dec2ad30` is an ancestor of the current production head | 26328794492 | Cancelled duplicate reschedule placeholder |
| #126 | #500 | `e2177fa891bcc004705a8a5d5377467d858fc9f3` is an ancestor of the current production head | 26329132115 | Session-date fallback to the course's own week |
| #143 | #616 | `29595d7aaa58927a68622bb945c4939442ef3ad5` is the current equivalent | 26703727147 | Scroll lock surviving page change |
| #174 | #938 | `f33f7be05dcd8cceeb6db1a54785e47a862a198a` is an ancestor of the current production head | 28274544835 | Overlap response routed to force-create flow |

These are engineering candidates only. They are not marked complete by this
document because current production behavior still needs direct evidence and a
valid in-app evidence write-back.

### Contradictory or protected evidence

- #122 and #123 cite PR #446 in public history, but the merged PR contents do
  not match the claimed session-generation fix. Do not use that reference as
  production proof until the issue history is reconciled.
- #94 initially had no public comment at or after its latest resolve. The
  reporter-facing follow-up was posted by run `34080284189` and verified in
  production as comment `580`; it is now back in the candidate set above.
- #180 cites PR #954, but no historical deployment record was found. It needs a
  fresh production probe and source-to-production reconciliation.
- Billing / entitlement / historical-data items (#92, #95, #96, #97, #149,
  #158, #159, #189, #190, #191) remain Founder-gated. No billing truth or
  historical row may be altered as part of evidence backfill.
- #19 and #98 have no public comments, no attachments, and insufficient
  reproduction detail. They cannot satisfy the public-reply / root-cause gate
  from existing evidence.
- Positive words in reporter comments (#13, #18, #26, #30) are not an API
  `reporter-verify` action and must not be treated as reporter verification.

## Why the current schema is insufficient

`bug_report_status_logs` stores only `from_status`, `to_status`, `note`, actor,
and time. It has no event type or immutable evidence relation. Inserting a
synthetic `resolved -> resolved` row would make a status-transition table carry
an unrelated audit event and could distort reopen / resolution metrics.
Cycling `resolved -> in_progress -> resolved` would be worse: it changes the
workflow state, resets the resolution timestamp, and sends a misleading
reporter-visible lifecycle signal.

This matches the mature-product pattern: Jira exposes audit records separately
from workflow changes, GitLab exposes notes separately from state changes, and
Laravel audit packages model append-only audit events rather than fake status
transitions. See the [Jira audit records API](https://developer.atlassian.com/cloud/jira/platform/rest/v2/api-group-audit-records/),
[GitLab Notes API](https://docs.gitlab.com/api/notes/), and
[Spatie activitylog documentation](https://spatie.be/index.php/docs/laravel-activitylog/v5/introduction).

## Proposed safe implementation after Founder GO

1. Add a dedicated append-only resolution-evidence relation/table with the
   bug ID, evidence source, source reference, production revision, deploy run,
   public comment ID, verifier actor/time, manifest ID, and immutable payload.
2. Add a manifest-driven command and manual workflow with dry-run first,
   explicit confirmation, row locks, exact production-SHA checks, and an
   idempotency key. It must fail closed on changed status, missing public reply,
   contradictory source history, or a non-matching production revision.
3. Backfill only evidence; do not change Bug status, reporter identity,
   comments, attachments, billing, sessions, permissions, or production code.
4. Update timeout eligibility to consume the dedicated evidence relation while
   preserving the existing seven-day and no-reply rules.
5. Run targeted tests and CI, then perform a dry-run. Production migration and
   apply remain separate protected actions with a rollback plan.

## Founder decisions required

1. Approve or reject the dedicated append-only evidence table and its migration.
2. If approved, authorize the bounded candidate set above for read-only
   production re-verification, with contradictory and protected items excluded.
3. Confirm that a historical public comment plus current production probe is
   sufficient evidence for the backfill, or specify any additional proof.
4. Separately decide the protected billing/data-repair items and the known
   reconciliation residuals; this packet does not authorize those writes.
