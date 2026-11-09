# Director affected-path acceptance

The existing credentialed UI Smoke job runs `npm run test:e2e:director-acceptance`
after its ordinary smoke suite, using the same serialized account runtime. No new
credentials, API, trigger or permission is introduced. Publish/run this acceptance
after the assessed rollback editor and student workbook changes are deployed.
Exact deployed-SHA evidence remains a separate release requirement.

Two real UI paths are checked:

- An existing approved, editable assessment exposes rollback in its card and
  editor. The browser dismisses the prompt; no rollback POST may be attempted.
- The bill list searches an existing student's name, verifies all visible rows
  belong to one student ID, checks a row, and clicks the real export button.
  Export uses **visible filtered rows**, independently of bulk checkboxes. A
  one-shot guard permits only the exact existing export endpoint and matching
  visible identifiers, within its existing 2,000-row cap. The export audit is an
  expected effect; no course/payment/assessment write is permitted.

The existing read-only guard is installed after the normal smoke login. Page
startup may attempt materialization/read acknowledgements: these remain blocked.
The cancel check compares attempted writes before/after the prompt. It does not
claim those blocked startup paths were exercised successfully.

Missing runtime inputs or suitable existing data **fail as UNVERIFIED**, not skip.
Do not provision records, obtain a session token, run a repair, or retrieve runtime
credentials locally to make this pass.

This config uses the list reporter and disables traces, screenshots and video.
The private download is inspected in place with Python stdlib ZIP/XML, then
deleted. Only boolean/count evidence is printed; neither workbook contents nor
customer names are attached or uploaded. ZIP size/entry limits, relationships,
student sheet/summary identity, date/amount types, invoice structure, formula and
external-link absence are checked. This is structural acceptance, not a financial
reconciliation or repair-data collector.

Offline helper verification, without credentials or network requests:

```sh
cd frontend
npm run test:director-acceptance-helpers
node --check e2e/director-path-acceptance.spec.js
node --check playwright.director-acceptance.config.js
```
