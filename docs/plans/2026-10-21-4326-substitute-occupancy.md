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
- #4215 remains separate and open; do not edit its branch, successor-shift
  implementation or StaleScheduleExceptionFilter. Recheck overlap before handoff.
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
  Coordination comment has no owner acknowledgement yet. Before integration,
  confirm ownership with that session and preserve both branches' changes;
  do not treat this handoff as integration clearance.
- Self-counting, intralesson partial substitution, stale UI ownership parity,
  production flags/rows and serving release identity remain unverified. They
  are not claimed fixed; expand investigation only if case evidence requires it.
- Existing server message text and capacity rule precedence remain unchanged.
  The broad structured-message redesign is deferred to avoid mixing it into
  the three evidence-backed fixes authorized for this execution.
- Code rollback is a revert through the approved release path; this patch has
  no schema or data repair. Formal production acceptance still requires the
  read-only evidence and reporter verification defined in the planning handoff.
