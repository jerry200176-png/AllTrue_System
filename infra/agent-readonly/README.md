# Agent database diagnostics — preparation, not activated

Use upstream [DBHub 1.6.0](https://github.com/bytebase/dbhub/tree/v1.6.0)
(MIT, Node >=22.5) over local **stdio**. This profile provides schema discovery,
bounded general SQL and two parameterized shortcuts. It is not restricted to
existing report IDs: the Founder diagnostic identity can investigate all
approved campuses and students, including cross-contract anomalies.

`views.sql` is a review template, not a Laravel migration or deployment hook.
The eleven explicit-column views expose contract, session, attendance,
assessment state, deduction/package and invoice/payment relationships. They
exclude names, contacts, account suffixes, tokens, credentials, URLs, JSON
snapshots and free text. IDs, dates and amounts remain sensitive evidence:
store results in private task artifacts, never public GitHub comments.
LEFT JOIN retains dangling relationships; NULL campus/student is a diagnostic
signal, not authorization to assign ownership. Campus on these views generally
reflects the current student; attendance also preserves its recorded campus.

## Proposed activation boundary

1. Verify source schema/version, every referenced column, view owner and the
   approved all-campus Founder scope. Install on an existing reporting replica
   when available; otherwise constrain queries against the primary. A replica
   needs observed lag and a snapshot time before creating a repair manifest.
2. Through the existing approved operations channel, create a dedicated identity
   with **SELECT on these eleven views only**. Do not grant source-schema SELECT,
   FILE, PROCESS, EXECUTE, CREATE, temporary-table, GRANT OPTION or write rights.
   Use a locked, separately managed view definer with only required source reads.
   The agent account is distinct from the app account and never owns the views.
3. Keep DB access on a private approved route, bound to the service origin;
   require TLS with verified CA. No public DB port, agent SSH-to-Pi exception,
   application credential reuse or HTTP MCP listener is part of this proposal.
4. Bind `ALLTRUE_READONLY_DSN` through environment Secrets, never chat, arguments
   or committed files. It replaces the encrypted-dump dependency for these reads;
   it does not extend `ALLTRUE_TRIAGE_TOKEN` permissions.
5. Enable and verify database/gateway audit records for identity, query, time,
   duration and outcome with private retention. **DBHub alone supplies neither
   organizational RBAC nor an enterprise audit trail**; upstream directs those
   needs to [Bytebase](https://github.com/bytebase/bytebase). Do not advertise an
   unauthenticated DBHub HTTP endpoint as an audited access gateway.

Only after those checks, run the pinned server with the supplied profile:

```bash
npx --yes @bytebase/dbhub@1.6.0 --transport stdio --config infra/agent-readonly/dbhub.toml
```

The upstream package version is fixed; this command is not a hermetic transitive
dependency lock. Reuse a reviewed installation for repeat work. DSN stays in the
environment. Native database grants remain the write barrier even if a tool
parser has a flaw. General queries cap at 200 rows; contract/timeline shortcuts
cap at 200/2,000 and return `matched_rows`. Read truncation flags and split a
bounded scope rather than treating truncated data as a complete repair input.
The five-second timeout is requested from DBHub; verify actual driver/server
timeout and cancellation behavior before production activation.

## Evidence and rollback

Run `python3 scripts/tests/test_dbhub_readonly.py /path/to/dbhub` against disposable
synthetic SQLite. It exercises the actual MCP process, not a mocked SQL filter.
Run `python3 scripts/tests/test_dbhub_mysql_readonly.py /path/to/dbhub` for a
disposable loopback-only MySQL 8.0 container proof. It validates all eleven view
projections, native view-only SELECT and write/source/system/column refusals,
then exercises the same real MCP cases. The source fixture is derived from the
projection; this cannot certify live column compatibility. Its privileged test
definer is not a production provisioning template. Both runs preserve ten
synthetic session rows and expose nine lessons against an eight-unit contract.

These checks do not prove production schema, audit,
campus authorization, network readiness or timeout enforcement. The two shortcut
parameters select a campus; they do not authorize an untrusted caller. This
Founder-only profile must not be shared with staff or parent clients.

Activation needs the exact server, private route, identity, eleven-view grants,
audit destination and retention in one reviewed credential/access package.
Rollback stops the connector, revokes that identity's view grants and removes its
Secret binding; retain audit evidence. Remove unused views/definer only after
checking dependents. No business rows are changed by reading or by this PR.
