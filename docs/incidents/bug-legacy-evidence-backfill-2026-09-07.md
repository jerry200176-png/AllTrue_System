# Legacy in-app bug evidence backfill — Founder decision packet

**Date:** 2026-09-07 (Asia/Taipei)
**Status:** Completed for the approved minimal evidence model and bounded
backfill. One of the nine candidates (#114) met the evidence gate and was
backfilled and closed through the existing timeout path; the other eight remain
unresolved legacy classifications.

## Current production snapshot

The fresh, paginated in-app Bug inventory contained 253 records:

The initial inventory anchor for the related code audit was
`a4d2be72446fc87b6f068537d6ef8d0ae97f176b`. The evidence-grade probe ran after
the protected deployment at production backend revision
`4de6b39de6048d5abd0c019eef3209d24ff9dbe2`.

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

## Shipped implementation and execution record

1. PRs #2518, #2520, #2521, and #2530 shipped the additive model, command, and
   bounded read-only probe workflow. The final operational manifest/workflow
   was merged by PR #2533 at `f739b85835641fc3f215d855d62539e5638168a5`.
2. `bug_report_evidence` contains the approved fields, foreign key,
   bug/type/verified-time and type/revision indexes, and the unique
   bug/type/source idempotency key. `BugReportEvidence` rejects update/delete.
   Existing status logs remain status-transition history.
3. Protected deploy run `34083538684` applied the one pending migration with
   pre-migration DB backup, passed DB probe, health, smoke, exact Pi HEAD, and
   rollback-readiness checks. A later protected deploy run `34112543025`
   verified the current production revision used by the probe; it had no
   pending migration.
4. Probe run `34113146974` performed the approved nine bounded read-only
   candidate probes. It observed health `ok` and verified only #114: the named
   student/classes resolved and the bounded calendar read returned zero
   occurrences. It recorded this as current-state verification, without
   fabricating historical deploy provenance.
5. Backfill dry-run `34114039124` validated one insert. Apply run
   `34114112625` appended evidence row `1` for #114. The post-apply check found
   the row at the exact production revision and status-log count unchanged at
   `3`.
6. Existing timeout dry-run `34114181379` found only #114 eligible under the
   seven-day policy. Existing timeout apply run `34114244243` closed #114 and
   its post-apply dry-run reported `eligible=0`.

## Nine-case probe and backfill result

| Bug | Probe result | Backfill / timeout result |
|---:|---|---|
| #94 | Inconclusive: public context exists, but no safe case-specific current target | No backfill |
| #105 | Inconclusive: no concrete historical target for a mutation-free probe | No backfill |
| #114 | Verified: named target resolved; zero bounded current calendar occurrences | Evidence row 1; closed by existing timeout path |
| #115 | Inconclusive: no safe case-specific current target | No backfill |
| #116 | Inconclusive: no safe case-specific current target | No backfill |
| #124 | Inconclusive: no safe case-specific current target | No backfill |
| #126 | Inconclusive: same-name student found, but historical class identity is ambiguous | No backfill |
| #143 | Inconclusive: no safe case-specific current target | No backfill |
| #174 | Inconclusive: no safe case-specific current target | No backfill |

The probe artifact and operational logs are retained by the linked GitHub
Actions runs. No billing, entitlement, historical-data, permission, or other
financial production mutation was performed.

## Remaining Founder decisions / unresolved categories

1. The approved table, migration, candidate probe set, and evidence rule are
   now implemented and executed. No additional evidence should be mass-created
   for the eight inconclusive candidates.
2. #122/#123 remain contradictory against PR #446; #180 lacks historical deploy
   evidence; #19/#98 lack sufficient public resolution context; and positive
   words in #13/#18/#26/#30 are not reporter verification actions.
3. Billing / entitlement / historical-data items (#92, #95, #96, #97, #149,
   #158, #159, #189, #190, #191) and Pi reconciliation residuals remain
   read-only findings. Any production financial or historical-row mutation
   still requires separate Founder approval.
