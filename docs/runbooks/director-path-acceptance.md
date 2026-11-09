# Director affected-path acceptance

The existing credentialed UI Smoke job runs `npm run test:e2e:director-acceptance`
after its ordinary smoke suite, using the same serialized account runtime. No new
credentials, API, trigger or permission is introduced. Publish/run this acceptance
after the assessed rollback editor and student workbook changes are deployed.
Exact deployed-SHA evidence remains a separate release requirement.
The same step first runs the guard/parser helpers and Chromium privacy proof; either failure stops the production acceptance before its audited export.

Two real UI paths are checked:

- An existing approved, editable assessment exposes rollback in its card and
  editor. The browser dismisses the prompt; no rollback POST may be attempted.
- The bill list searches an existing student's name, verifies all visible rows
  belong to one student ID, checks a row, and clicks the real export button.
  Export uses **visible filtered rows**, independently of bulk checkboxes. A
  one-shot guard permits only the exact existing export endpoint and matching
  visible identifiers, within its existing 2,000-row cap. The export audit is an
  expected effect; no course/payment/assessment mutation is authorized by this test.
  The request is fetched with `maxRedirects: 0`: every redirect is rejected,
  including 307/308 which otherwise carry the POST past browser routing. Only a
  direct HTTP 200 XLSX attachment with bounded ZIP bytes is fulfilled. A rejected
  or failed attempt consumes the one-shot allowance.

The existing guard is installed after the normal smoke login. It blocks HTTP
writes and direct app GET/HEAD requests to the known materializers `/api/v1/class-sessions` and
`/api/v1/class-sessions/projection`, including query, trailing-slash and encoded
path spellings. Their controller can create ClassSession rows while serving a
read. They are never counted as harmless POST reads. Page startup may also
attempt write-method materialization/read acknowledgements: these remain blocked.
The cancel check compares attempted writes before/after the prompt. It does not
claim those blocked startup paths were exercised successfully.

The ordinary create-entry navigation/cancel check counts guard-produced known
stateful-read rejections through the same canonical matcher. Its observed
GET/HEAD attempts must equal its blocked receipts; an escaped read cannot count
as successful protection. Unexpected business write-method attempts, including
course creation POSTs, still fail. This verifies that the entry opens/cancels
with those reads blocked, not that session availability was loaded successfully.

This is a method guard plus explicit known-stateful-read blocks, not a guarantee
that every allowed GET has zero side effects. Native read redirects retain the
existing routing limits. Prior cancellation/XLSX results remain evidence for
those two assertions; they do not prove an all-database zero-write run. If a
blocked materializer prevents a UI path completing, report UNVERIFIED and do not
create records or relax the guard to make it pass.

Missing runtime inputs or suitable existing data **fail as UNVERIFIED**, not skip.
Do not provision records, obtain a session token, run a repair, or retrieve runtime
credentials locally to make this pass.

The director config and the default smoke profile when `SMOKE_BASE_URL` is set
use the list reporter and disable traces, screenshots and video. Without that
variable, the default synthetic diagnostic profile retains its capture settings;
the separate visual profile is unchanged.
Playwright 1.64.1 also automatically captures failure DOM in error-context files:
`PLAYWRIGHT_NO_COPY_PROMPT=1` stops that snapshot in its installed
`ArtifactsRecorder._takePageSnapshot`, and the public `preserveOutput: 'never'`
option removes remaining error/source files. A synthetic Chromium failure
regression checks both private profiles: no synthetic DOM sentinel enters
attachments or the probe console, and output files are removed. It requires
actual browser execution and the expected failure, and cleans temporary output.
This is not a general stdout scrubber: ordinary assertion/call-log diagnostics
may still print text. The proof makes no claim that every stdout value is clean.
The private download is inspected in place with Python stdlib ZIP/XML, then
deleted. The director download test prints boolean/count evidence; neither workbook contents nor
customer names are attached or uploaded. ZIP size/entry limits, relationships,
student sheet/summary identity, date type and finite numeric F/G/H cells for every
source summary (exactly three per source), invoice structure, formula and
external-link absence are checked. This is structural acceptance, not a financial
reconciliation or repair-data collector.

Local verification, without production credentials or production requests:

```sh
cd frontend
npm run test:director-acceptance-helpers
npm run test:director-acceptance-privacy
npm run test:e2e:director-acceptance-local
node --check e2e/director-path-acceptance.spec.js
node --check playwright.director-acceptance.config.js
```

The last two commands require the existing locked Playwright/Chromium install.
The local integration runs both unchanged acceptance tests through real Vue
App/Login/sidebar/pages on loopback Vite, using synthetic credentials/API and a
native XLSX ZIP download. Chromium DNS is restricted to loopback, the fixture
server refuses business writes, and no production credentials or API are used.
This proves test integration; existing production data and deployed SHA still
require the authorized runtime smoke.
