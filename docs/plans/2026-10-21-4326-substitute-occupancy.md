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

- Code input #4327: 4ed03770b0287d8a75bc18afd34aaccc7a4bb502;
  #4215: 03bdfa964f69f13bb396fa2990269a316942b09c.
- In an isolated worktree whose HEAD is the #4327 input, run
  `git merge-tree --write-tree HEAD 03bdfa964f69f13bb396fa2990269a316942b09c`.
  Exit 1 reports expected content conflicts; raw tree is
  d2cf9bf340c9dda3d124f3f0f356867cd71715fc. No branch is merged by this command.
- Materialize that tree only in a disposable validation worktree, then apply
  [the compatibility patch](2026-10-21-4326-4215-compatibility.patch).
  It is a resolution artifact for this raw combined tree, NOT a patch to apply
  directly to either PR branch. It retains #4215's generic/private errors,
  successor safeguards and student-current-read path, and main's bounded stale
  reader while using #4327's source-aware/current teacher validation.
- Artifact SHA256:
  2dc69b6b1e0831ca481054d70ec3f6e6b0f9823bc5168e5af70cc3eae90a551a.
  `git apply --check` and byte comparisons verify the resulting three files
  match the tested compatibility worktree. All other regular backend files
  match the raw combined tree. A docblock-only addition followed the 158/1053 run.
- Recheck both live heads before any later integration; these pinned results
  do not authorize merge/deployment or clear unrelated #4215 review findings.
