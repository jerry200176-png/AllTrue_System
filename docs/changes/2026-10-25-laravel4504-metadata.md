# #4504 bounded read-only metadata collection

The existing preflight is pinned to the retired #2833 candidate and does not inspect
Sanctum token storage. Add a manual-only diagnostic using the existing `production-ssh`
environment, host trust, SSH identity, side-effect concurrency and encrypted evidence.
The shared production-case-dump workflow is untouched. No credential, environment,
permission, deployment or application behavior changes. No production run occurs on merge.

The collector reuses the reviewed local script (original SHA256
`0830f9285cbac72ca29423402b635e7aed77a9ce83db2de81d0d1d956572d7dc`), adding a required
production SHA check before configuration and after capture. Candidate #4504 remains
`7f9acf5ef8ed5ead47c948da58799b035993720e`. Only schema/index metadata and the exact
original 2019 migration record are selected in a READ ONLY transaction; no token,
student, accounting or other business rows are queried. Remote timeout is45s TERM+5s KILL;
the outer SSH operation is70s+5s. A killed client does not prove server-side SQL cleanup.

Once the reviewed workflow is on main and one exact execution has been authorized:
`gh workflow run laravel-4504-metadata.yml --repo jerry200176-png/AllTrue_System --ref main`
with `expected_backend_sha=<approved current production SHA>`,
`expected_workflow_sha=<approved main SHA containing this collector>`, and
`confirm=READ_ONLY_4504:<expected_backend_sha>:<expected_workflow_sha>`.
Do not substitute an unapproved/new target after a mismatch. This fragment is not execution approval.

Retrieve artifact `laravel-4504-metadata-<run_id>-<attempt>`, then read `metadata.summary.json`.
Its fixed schema contains only approved identities, capture time, numeric versions, fixed extension
presence, known token-column types/nullability, fixed index predicates and original migration count.
It requires no decryption key. Private metadata/errors remain encrypted; no raw stderr enters the
public summary. Failure, malformed JSON, identity drift or incomplete transport produces Hold.
Collection success is not framework compatibility, loaded FPM state, activation approval or acceptance.
The separate PR test workflow uploads an explicitly synthetic artifact to verify safe retrieval.
Rollback: revert this diagnostic; it has no production data or configuration change to undo.
