# Issue #4326 execution plan

Status: bounded implementation complete, verification and handoff recorded below;
no production writes, merge or deployment. Production case acceptance is open.
Baseline: origin/main 803321c1. Risk: T2 scheduling workflow.

Continue the read-only repair plan delivered in the issue planning session.
Scope is deliberately limited to the three verified code defects: effective
teacher occupancy with legacy/v2 substitution, duplicated reschedule error
composition, and voided preview entries. Preserve capacity rules, source-lesson
exclusion, stale-schedule filtering, atomic transaction and campus boundaries.
Self-counting and production substitution ownership remain unverified hypotheses.

## Ownership

- This branch owns the bounded ScheduleGuardService occupancy correction and
  its tests, reschedule frontend error handling and preview status correction.
- Coordination recorded on #4215:
  https://github.com/jerry200176-png/AllTrue_System/pull/4215#issuecomment-6295453097
- #4215 remains separate and open; do not edit its branch or import its feature
  implementation. Founder continuation permits the minimal shared guard/caller
  compatibility correction proven below. Recheck heads before integration.
- Cloud investigation at ce867baf reported no commits or shared edits.
- Xinzhuang billing recovery and #4324/#4325 are outside this task.

## Ordered work and evidence

1. RED/GREEN: public guard regression for substitute away/into, flag off/on,
   materialized and schedule-only occupancy; genuine capacity remains enforced.
2. RED/GREEN: both reschedule composables render a complete server error once;
   preserve legacy detail fallback without duplicate occupant labels.
3. RED/GREEN: voided preview sessions/exceptions do not occupy; active remains.
4. Run focused backend scheduling and frontend regression suites, inspect diff,
   record exact results and remaining risks here. No broad architecture changes.

## Verification and stop points

Use scripts/phpunit-isolated.sh (ephemeral loopback MariaDB) and focused Vitest /
Node tests. Tests use synthetic identifiers only. No production test requests.
Do not change policy 2 to 3, activate flags, alter credentials/permissions/billing,
merge or deploy. A passing test is not production case acceptance. Deployment
identity, effective production flags and anonymous case rows remain unavailable;
later read-only acceptance must reconcile them and obtain reporter verification.

## Progress

- Isolated task worktree created by agent-start; preflight passed.
- Dependencies installed from existing locks; no dependency upgrades.
- RED: initial guard matrix failed 4/4, proving legacy session occupancy and
  original-teacher schedule projection defects. GREEN: 4 tests / 20 assertions.
- Expanded revert-proof: restored baseline guard temporarily, ran the final
  five-case suite, observed 5 failures including HTTP 409 instead of expected
  200 for the synthetic 16-18 to 17-19 move; restored implementation bytes.
- RED: both frontend flows duplicated the complete server message (2 failures).
  Shared formatter now keeps the complete server summary and uses details only
  for legacy responses. Legacy duplicate projections are deduplicated by
  student ID + displayed course variant; distinct students/variants remain.
- RED: voided session preview failed; adding voided to the exclusion set passes.
  Both voided session and exception projections are now covered.
- Backend focused suite: 94 tests / 563 assertions passed (ephemeral MariaDB):
  `bash scripts/phpunit-isolated.sh backend/tests/Feature --filter
  'SubstituteOccupancy4326Test|ScheduleGuardrailsTest|StaleScheduleExceptionBusyTest|RescheduleSessionPrecisionTest|RescheduleOccupiedSlotTest|AvailabilityCapacityTest|ScheduleGuardCurrentReadTest|SubstituteRescheduleRegressionTest|SubstituteReschedulesCombinationTest|SubstituteWithRescheduleTest'`.
- Frontend focused Vitest: 4 files / 28 tests passed: calendar/course-management
  reschedule composables, rescheduleApi, CalendarRescheduleModal.
- Node calendarOccurrenceMerge, slotOccupancy and scheduleDisplay tests passed.
  PHPStan on ScheduleGuardService passed; PHP syntax and diff whitespace passed.
- The v2 regression fixture also stamps original date/start on the substitute
  row, exercising the live occurrence path rather than only legacy fallback.

## Final scope and remaining risks

- No capacity, self-exclusion, deduplication of backend occupancy, interval,
  transaction or concurrency policy changes. Existing current-read regressions
  passed; this does not establish race safety for every reschedule writer.
- Normal schedule provenance exclusions from #4245 remain. Legal legacy extra /
  makeup rows retain their own teacher fallback; v2 still resolves LR ownership.
- Public #4215 head remains 03bdfa964f69f13bb396fa2990269a316942b09c.
  Its overlap patch was inspected: the provenance work is already present on
  our main baseline, and this branch extends it without replacing the PR branch.
  Founder assigned this #4327 Codex as single shared-area integration coordinator:
  https://github.com/jerry200176-png/AllTrue_System/pull/4327#issuecomment-6295738100
  Ownership waiting is lifted; this does not assert the old worker acknowledged
  or stopped. Preserve #4215's branch and successor-shift functionality.
- Self-counting, intralesson partial substitution, stale UI ownership parity,
  production flags/rows and serving release identity remain unverified. They
  are not claimed fixed; expand investigation only if case evidence requires it.
- Existing server message text and capacity rule precedence remain unchanged.
  The broad structured-message redesign is deferred to avoid mixing it into
  the three evidence-backed fixes authorized for this execution.
- Code rollback is a revert through the approved release path; this patch has
  no schema or data repair. Formal production acceptance still requires the
  read-only evidence and reporter verification defined in the planning handoff.

## Compatibility continuation (Founder assignment)

- Pin #4327 at 97a6f7303 and #4215 at 03bdfa964; perform isolated three-way
  compatibility analysis without merging either PR or modifying #4215's branch.
- Preserve main's current-read/$lock and provenance behavior. The tree analysis
  reports one StaleScheduleExceptionFilter conflict; resolve only in the isolated
  compatibility worktree, retaining bounded current-read discovery.
- Reproduce unresolved #4215 review discussion_r4306281805 with two connections:
  an unrelated student's effective-teacher booking commits after the apply RR
  snapshot. Verify teacher rejection and zero writes, including substitute cases.
- Implement only evidence-backed compatibility corrections, test the combined
  successor/current-read/substitute suites, and record exact-head CI/review status.
- No merge, deployment, production changes, policy/flag/permission changes.

### Compatibility evidence and necessary correction

- Three-way base: 0ea127d48; inputs: #4327 97a6f7303 and #4215 03bdfa964.
  Initial tree analysis found only a formatting conflict in
  StaleScheduleExceptionFilter at the current-read `get()` chain. Keep main's
  complete file (including FORCE INDEX/exact-date/current-read behavior); do
  not copy the older #4215 file over it.
- Unresolved #4215 review:
  https://github.com/jerry200176-png/AllTrue_System/pull/4215#discussion_r4306281805
  was reproduced on the combined tree: independently committed other-student
  teacher booking stayed invisible to an established RR snapshot; apply
  incorrectly returned 200 (RED 1 test / 7 assertions). Passing existing $lock
  to teacher validation made it reject with no writes (GREEN 1 / 10).
- The same missing propagation exists in main's successor caller. Added public
  apply regressions for session and schedule-only occupancy: RED 2 / 14 with
  200 instead of 422; GREEN 2 / 18 with refusal and identical data snapshots.
- Merely passing $lock loses a substitute: validateCourseOccurrence reloads
  current contract metadata and overwrites a caller's cloned TeacherID. Public
  guard regressions fail for both legacy/v2 (RED 2 / 4). The optional server-owned
  source occurrence now resolves the effective teacher AFTER metadata reload,
  forwarding $lock to the existing resolver (GREEN 2 / 6). Existing callers that
  omit this argument retain their prior semantics.
- Main successor caller now forwards its existing apply/preview lock mode and
  source slot; no successor feature, student guard, flag or migration was imported.
- Combined #4215 caller uses the same source-aware guard, retaining its existing
  student-current-read and generic/private blocker behavior. Its mock-only
  effective-teacher assertion was replaced in the compatibility fixture by a
  real API conflict with an unrelated synthetic student's substitute booking.
- Main focused regression: 112 tests / 696 assertions passed; changed services
  pass PHPStan. Combined successor/substitute/current-read/capacity/stale suites:
  158 tests / 1053 assertions passed.
- One earlier combined run hit a local MariaDB 10.11.14 process segmentation
  fault and consequent connection-refused errors. The first affected undo cases
  passed alone (4 / 40), and the entire affected combined selection passed on
  fresh isolated MariaDB. Crash root cause remains unknown; it is not counted
  as an application fix or silently omitted from verification evidence.
- Exact-head remote CI and independent review are pending the updated commit;
  draft-skipped checks remain insufficient evidence.

### Reproducible integration artifact (validation only)

- Code input #4327: 9ae4fee829577ee56552c8e0ff0f164c6c40e5ab;
  #4215: 03bdfa964f69f13bb396fa2990269a316942b09c.
- In an isolated worktree, run `git merge-tree --write-tree
  9ae4fee829577ee56552c8e0ff0f164c6c40e5ab
  03bdfa964f69f13bb396fa2990269a316942b09c` as one command.
  Exit 1 reports expected conflicts; raw tree is
  9ea20a681603cfb44965e7894cb854ab2237ac8d. No branch is merged.
- Materialize that tree only in a disposable validation worktree, then apply
  [the compatibility patch](2026-10-21-4326-4215-compatibility.patch).
  It applies ONLY to this raw combined tree, not either PR branch. It retains
  #4215's private errors, anchored-occurrence safeguards and student-current-read
  path, and main's bounded stale reader. Teacher validation uses the actual
  written destination; date-bound substitute assignments are not moved.
- Artifact SHA256:
  8d5d710492f135c18d838c82c42399fae8ce45093bb5a7296805ad1485195c2f.
  `git apply --check`, actual apply and byte comparisons reproduce all three
  corrected files. Other backend files match this raw tree. The artifact
  includes the independent 9-case apply matrix and corrected destination/privacy
  fixture. #4215's anchored-occurrence refusal supersedes main's successful
  anchored-shift scenario in this combined fixture. Latest combined selection:
  171 tests / 1166 assertions PASS.
- Recheck live heads before any later integration. These pinned results do not
  authorize merging #4215 or clearing its unrelated review findings.

### Independent evidence follow-up

- Independent handoff: [verification report](https://github.com/jerry200176-png/AllTrue_System/pull/4327#issuecomment-6295966812).
  Integrated its two source-RR tests, replacing the weaker clone-only pair.
  Updated main source/successor/current-read selection: 61 tests / 488 assertions.
- The independent 9-case apply matrix was applied ONLY in the isolated #4215
  rehearsal and passed 9 / 79. It includes substitute-away/in and v2 makeup LR.
  Patches and logs remain in the named local review-evidence worktree; the
  updated committed artifact above now includes these cases.
- Hosted Presubmit flagged intentional replacement of #4245/#4162 lines. The
  documented intentional-revert label and exact rationale acknowledge this
  tested behavior correction; no checker or safeguard was weakened.


## Review corrections and evidence split

- Preserve the complete plan/artifact in this separate docs-only Draft because
  the combined runtime/regression/evidence diff exceeded the 700-line gate.
  Both branches start independently from main; no feature was stacked or merged.
- Independent review found legacy N+1 queries (8 lessons caused 9/10 schedule
  reads), a substituted session losing its existing room reservation, and voided
  exceptions suppressing another active occurrence on the same date. Each was
  reproduced by failing regressions before correction.
- Legacy assignments are now fetched in a bounded batch (including when another
  campus has v2 enabled); v2 and current-read resolution retain the existing
  authority and lock mode. Source-specific extra/makeup fallback is preserved.
- Teacher membership and the existing room reservation projection are distinct:
  a substitute frees the teacher but retains the source room reservation. This
  restores the existing teacher/campus-scoped room protection, without changing
  capacity policy or claiming a new cross-teacher room inventory authority.
- Voided exceptions require a matching start; session status uses the matching
  occurrence. Missing start information cannot suppress a known active lesson.
- The pinned raw-tree artifact above remains historical reproducible evidence;
  final compatibility reruns overlay the updated guard, resolver and regression
  tests in the isolated rehearsal, preserving #4215's successor implementation.

- Review-corrected main regression: 115 tests / 723 assertions; frontend 32 tests;
  three changed services pass PHPStan. Additional existing substitute suites
  are recorded in the runtime PR. Corrected combined rehearsal: 170 / 1159.
- Voided exception RED: two active-occurrence cases failed; a second RED proved
  voided/active sessions on the same day must be scoped by start. Both now pass.
- Runtime PR remains #4327. This docs-only evidence PR does not authorize merge,
  deployment or production acceptance. Final exact-head CI/review receipts are
  recorded on #4327 rather than causing repeated evidence-only runtime heads.

### Release authorization and destination-teacher correction (2026-10-21)

Founder explicitly instructed this session to finish and ship #4326, then handle
Xinzhuang billing and PR closeout. This supersedes the earlier task-specific
no-merge/no-deploy restriction for this code repair; canonical CI/review/queue,
deploy environment gates and financial mutation boundaries remain in force.

Independent review of `defb5e12b` found successor validation wrongly carried a
source substitute into a destination whose date-bound assignment is not moved by
`apply()`. Corrected at `e1bc51695`: resolve the actual written destination with
current reads. No schedule-assignment write or new undo semantics is introduced.
RED apply returned 200 for a full contract-teacher destination; GREEN rejects
with the authoritative snapshot unchanged, then applies after freeing the slot
and verifies its canonical teacher and unchanged date-bound assignment. Main
selection: 65 tests / 510 assertions PASS; changed service PHPStan PASS. General
source-aware guard tests remain: callers that really preserve a source assignment
still need that API. Previous source-teacher successor guidance above is
superseded by this correction.

The separate #4215 rehearsal retains its existing refusal of anchored source or
destination occurrences. Its privacy test fixture must place the conflicting
substitute-teacher lesson at the actual substituted destination (12-08), and
assert the incoming session (12-01), rather than assume the 12-08 substitute moves
to 12-15. This adaptation is confined to the isolated rehearsal; #4215's published
branch is untouched. The updated pinned patch above reproduces the corrected rehearsal, including
the destination-teacher service and fixture changes.
Production case acceptance remains open; final CI/review/deployment receipts
belong to the linked PRs and issue, not an inferred green status.

### Real legacy anchor room regression and approved release scope

Review found the actual ClassSessionController substitution writer leaves a
contract-teacher rescheduled anchor plus the linked substitute scheduled row.
RED reproduced the lost room reservation; `9ae4fee82` retains the substituted
live session room in date/recurring checks while explicit leave still frees it.
Main: 140 / 882 PASS; final strengthened room pair 2 / 10; PHPStan PASS. Latest
combined rehearsal: 171 / 1166 PASS, including no-end-date query bounds. The
updated input/tree above plus the committed patch reproduce every regular
backend file byte-for-byte; no undocumented backend overlay is required.

Founder separately approved including the existing #4283 invoice_corrections
migration in this normal deployment, after disclosure of its nullable-column
changes and retained-schema/limited automatic rollback risk. This does not
authorize the three financial repairs, flag/allowlist activation, credentials,
permissions or reuse of the held historical release train. Canonical environment
reviewers remain required. Approval receipt: [PR comment](https://github.com/jerry200176-png/AllTrue_System/pull/4327#issuecomment-6296660356).
