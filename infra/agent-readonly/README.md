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
Caller-controlled closure explanations and ledger source/reason text are omitted.
Class types, billing modes and payment methods map known values to fixed codes;
unknown values become `other`, never a guessed repair classification.

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
   or committed files, **only in the approved database/gateway service runtime**.
   The existing standing decision keeps production passwords out of agent cloud
   environments. Agents use a scoped, expiring gateway token; no permanent DB
   password is copied into their workspace. A local DSN is synthetic-test-only
   unless a separate concrete access decision authorizes another custody model.
   It replaces the encrypted-dump dependency for these reads;
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

## Daan tuition reconciliation, through 2026-10-31

The dedicated profile is separate from the general diagnostic profile. It has
six fixed tools and no arbitrary SQL or schema discovery. The native views
validate Campus 15, code `daan`, and production campus type before returning
rows. They use the student's current campus, as the AllTrue billing scope does.
Historical transfers or missing ownership that prevent attribution require
separate evidence. Cross-owner links are masked and marked uncertain.

### Prepare the private read entry

- Run the production identity check and verify the approved MySQL server,
  source columns, `daan` campus mapping and Taipei accounting dates.
- Use `daan-tuition-views.sql` with the verified source schema and a dedicated
  locked view definer. Grant a separate identity SELECT only on
  `alltrue_diagnostics.daan_tuition_{scope,contracts,invoices,invoice_items,payments,reports}`
  (six explicit grants). Require private routing, verified TLS and audited
  access under the same activation boundary above. Submit the exact access and
  credential package for Founder approval before provisioning.
- Run the pinned DBHub stdio connector with `--config infra/agent-readonly/daan-tuition.toml`
  inside the approved gateway runtime. Inject `ALLTRUE_DAAN_TUITION_DSN` there.
  Only the authenticated, expiring gateway session reaches the agent. Leave the
  connector off until native grants, routing, query timeout and audit pass.

### Collect and review

Call `daan_tuition_scope` first and require one matching row. For each of
`contracts`, `invoices`, `invoice_items`, `payments` and `reports`, call
`daan_tuition_<dataset>` with `after_id=0`, then advance to the last returned ID
until the tool returns an empty page. Stop on a failed or truncated result.
Use a stable approved snapshot for the full collection. A live paginated read
can surface leads, but it needs a fresh scoped read before any approval.

The worksheet takes private JSON on stdin with `scope` (the scope row) and
`pages` (dataset names mapped to arrays of returned row arrays, including each
final empty page):

Keep both files in a private directory that is accessible only to the operator (mode 0700).

```bash
umask 077
python3 infra/agent-readonly/daan-tuition-review.py < "$DAAN_SNAPSHOT" > "$DAAN_REVIEW"
```

Monthly face value reflects current bill values grouped by issue date. The
worksheet shows currently void bill face value separately. Payment amounts
use their recorded payment date. Receipt reassignments use the move date and
are excluded from cash collections. Internal credit movements and later activity
on in-scope accounts stay visible separately. Current status can include
later edits, so the output is a review list and does not reconstruct historical
invoice balances. Verify any suspected mismatch against the current AllTrue
student ledger and the Founder/director's evidence.

### Approved changes and verification

Prepare a private change list with exact bill, report and payment IDs,
observed and intended values, evidence reference, side effects and the
production backend SHA. For pending payments use the existing accounting
confirmation action. For eligible incorrect bills use the existing bill
correction preview and apply action. Only include an item after checking the
current accounting preview with that action's eligibility. Mixed action types
retain their existing approval and application steps. Where the standard
actions cannot express the change, use an eligible POP operation with an
immutable Repair Manifest, dry run, recovery point and the required Founder
approval. Keep unsupported items pending until a reviewed operation exists.

After Founder applies the approved action, reread the exact records. Verify
invoice amount and status, linked net payment, receipt and audit, and report
partial outcomes individually. To disable the read entry, stop the connector,
revoke its view grants and remove its secret binding through the approved
channel. Retain review and audit evidence privately.

### Synthetic verification

```bash
python3 scripts/tests/test_daan_tuition_review.py
python3 scripts/tests/test_daan_tuition_mysql_readonly.py /path/to/dbhub
```

The second command starts a separate disposable local MariaDB with synthetic
data and exercises the actual DBHub v1.6.0 stdio tools. Neither command
connects to a real ledger.
