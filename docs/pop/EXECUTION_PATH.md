# POP execution path (prepared slice)

This document describes the Phase 2-prepared execution boundary. It does not
authorize production execution. The catalog entry remains `lifecycle:
prepared` until a Founder exact-SHA decision activates it.

## Request and approval

1. A director or super admin submits `POST
   /api/v1/pop/operations/{operationId}/draft` with the catalog's exact
   `parameter_keys` and a unique idempotency key.
2. The self-hosted runner invokes `pop execute --phase=dry-run` for the
   resulting request. The strategy re-reads production state and records its
   preconditions, snapshot, and bounded change set.
3. Approval uses `POST
   /api/v1/pop/operations/requests/{requestId}/approvals`. The catalog policy
   requires distinct `director` and `super_admin` approvals, both bound to the
   same parameters hash and exact 40-hex commit SHA. A token is issued only
   after both roles have approved.

The control-plane API intentionally has no execute endpoint. It cannot mutate
production. It also enforces role, password-change, and campus middleware;
the service repeats the campus check and rejects self-approval.

## Execution and recovery

The reusable `pop-execute.yml` workflow is only an adapter. It checks out the
approved commit on the `alltrue-production` self-hosted runner and calls
`backend/bin/pop execute`. For `execute`, `verify`, and `rollback`, the CLI
requires the short-lived database-backed approval token and exact commit SHA.
There is no SSH hop, ad-hoc artisan repair, or direct mutation API.

Every phase is transaction-bound where it writes, has a unique idempotency
key, and persists a schema-versioned execution record with correlation ID,
version pins, invariants, approval reference, snapshot reference, and result.
Rollback first checks that session ownership, evidence rows, charges, invoice,
and continuity-group membership have not drifted; any drift aborts the
rollback rather than guessing.

`course-contract-repair` is deliberately conservative: it requires an exact
source/target/session expectation set, no payment or payment-report evidence,
no package or settlement lock, no unexpected active sibling contracts, and
updates only the selected contract/session/invoice boundary. Existing
`CourseContinuityService`, `SessionContractRecoveryService`, and
`SessionDeductionService` remain the domain implementation points.

## Resuming the Huang repair

Do not reuse the old plan after a fresh precondition scan. The latest scan
found additional active contracts SC3279 and SC3395 for the same student,
campus, and subject, so the former two-contract desired state is not currently
an executable request. A new exact plan and evidence package are required
before drafting another request. This slice does not create that request and
does not execute the billing mutation.
