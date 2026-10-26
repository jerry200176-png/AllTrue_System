## 2026-10-26 — scoped migration execution boundaries (#4508)
<!-- release-notes: silent_ship=silent-2026-10-26-migration-execution-boundaries -->
<!-- silent-reason: unactivated deployment safety and diagnostic candidate -->

Reject empty/unknown migration status and pending files outside the reviewed Git
range before backup or DDL. For the fixed #4508 migration, verify its content hash,
complete pending set, primary connection and canonical backup database before
execution. Restrict the command to the single reviewed migration path.

The initial execution profile is MariaDB 10.11/InnoDB with wsrep off: verify native
session metadata-lock wait 5s, statement-abort request 120s and INPLACE (no COPY
fallback), and refuse reconnection that would lose those session limits. MySQL's
SELECT-only timeout is not substituted; unknown/unsupported profiles stop before
DDL. An outer 300s client cap does not prove server cancellation or cleanup.

Any failed bounded attempt holds without code rollback, later deployment steps or
automatic retry. Success additionally verifies the primary nullable timestamp,
single-column index and exact ledger entry. Preserve token/expiry data and all
existing activation/identity-migration approval boundaries. No production probe,
DDL, deployment, credential or live protection change was executed by this PR.
