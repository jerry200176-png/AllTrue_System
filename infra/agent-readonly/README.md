# Agent database diagnostics — preparation, not activated

## WSL client for the existing AllTrue read API

`query_alltrue.py` calls the existing AllTrue `/api/v1/triage/db` routes
through an approved relay. WSL holds its enrolled client certificate while
the AllTrue read token stays on the managed host. The relay must authenticate its caller, inject the existing read-only
authorization server-side, and forward only the selected GET routes to the
verified AllTrue origin. Keep full query strings out of relay logs. A local relay may bind to loopback. Use HTTPS for a
remote relay. Set `ALLTRUE_READ_GATEWAY_URL` to the approved relay origin.
For mTLS set `ALLTRUE_READ_CLIENT_CERT`, `ALLTRUE_READ_CLIENT_KEY` and, for a
private server CA, `ALLTRUE_READ_SERVER_CA` to the corresponding file paths.
The existing token can read multiple campuses. Query filters such as
`CampusID=15` limit the result, and the relay must enforce caller authorization
independently.

```bash
python3 infra/agent-readonly/query_alltrue.py
python3 infra/agent-readonly/query_alltrue.py Student --eq CampusID=15 --order id --limit 200
```

For larger reads, sort by a unique numeric ID and advance its lower bound to
the previous page's last ID plus one. Filters take numbers, dates and times.
Filter returned status codes locally so server-side post-page code filtering
cannot hide later results. Confirm table and field names before querying and
keep exports in a private artifact directory. Write requests
continue through AllTrue's existing POP and accounting approval workflows.
The client does not provision a relay or change production access.

For Daan's first reconciliation, collect the scoped evidence and create the
worksheet without retaining raw table pages:

```bash
review_dir=$(mktemp -d)
python3 infra/agent-readonly/collect_daan.py --out "$review_dir/daan-review.json"
```

The collector verifies the Daan code and ID at the pinned public AllTrue origin
and verifies the campus is a production campus through the approved relay. It
uses the same worksheet rules as the native diagnostic profile. It checks
campus ownership, includes historical receipts confirmed on later bills and
flags inconclusive receipt-move evidence. The result is a paginated live read.
Refresh each disputed item's evidence immediately before a POP or accounting
approval.

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

## Activate the managed WSL relay

`relay/nginx.conf` is the deployable configuration for an approved managed
NGINX host. It forwards the existing catalog and table GET routes only, with
upstream TLS verification at the pinned production AllTrue origin. The
dedicated client CA and enrolled certificate list both apply. The default
listener is loopback. Use a private TCP route preserving client TLS or bind to
the approved private interface. The server certificate must match the relay
address. The relay access log records certificate, response status and timing.
The application retains the query audit.

The exact activation review binds the managed host and relay address, the
production origin, the reader's certificate fingerprint and expiry, the
approved table scope of the existing token, and the audit destination.
The Founder authorizes new credentials or changes in custody. No database
account or password is needed for this existing audited read API.

Place the CA and server certificate/key at the paths in the NGINX template.
The managed host maps the enrolled SHA-1 certificate fingerprint to `1` in
`callers.conf` (lowercase, without colons). Inject the existing read-only token
via the managed secret facility into a private `upstream-auth.conf` containing
the NGINX Authorization header directive. Credentials and their rendered
configuration stay in the managed runtime. Do not run `nginx -T` there, since
it prints the rendered configuration. Keep the WSL device key owner-only.
Prefer short-lived enrollment and enforce rotation privately.

Before activation, validate the configuration, then verify refusal of an
unenrolled certificate, a write method and an unlisted path. Validate one
authenticated catalog query, the expected production campus identity and
the corresponding private audit evidence. Disable access by removing the
fingerprint and reloading NGINX. If the AllTrue read token is compromised,
use the existing AllTrue revocation control. Keep audit evidence privately.

Any Daan ledger change uses the existing AllTrue accounting action or POP
operation with an exact preview and Founder approval. Recheck the current
records before applying a change and verify the affected balance, payment,
receipt and audit afterward. The relay's credential has only read access.

### Synthetic verification

```bash
python3 -m unittest discover -s scripts/tests -p 'test_alltrue*.py'
```

To exercise NGINX end to end, set `ALLTRUE_RELAY_NGINX_TEST_BIN` to a
local NGINX executable. The test generates disposable TLS identities and
uses a loopback dummy upstream, with no connection to the production API.
