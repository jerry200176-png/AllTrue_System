import fs from 'node:fs';
import path from 'node:path';

const campusNineOnlyTitle = 'contracted four-session detail explains two unarranged sessions';
// #4150: scope=payments only (not in `all`). These may end `skipped` only through their own
// "not-exercised:" skip, i.e. the live campus had no case for that rule; any other skip fails.
const paymentTests = [
  ['payment-display-production.spec.js', 352, 3, '學生管理: free courses read 免費／贈送 and 未繳 only when unpaid'],
  ['payment-display-production.spec.js', 398, 3, '課程查找: free courses read 免費／贈送 and 未繳 only when unpaid'],
  ['payment-display-production.spec.js', 533, 3, '帳務: a waived course reads 不收了 and offers no payment action'],
  ['payment-display-production.spec.js', 537, 3, '帳務: a legacy paid flag with no standing payment reads 需核對'],
  ['payment-display-production.spec.js', 541, 3, '帳務: payments that net to zero show no 繳費日'],
];
const paymentConflictTests = [
  ['payment-conflict-production.spec.js', 207, 3, 'production UI: pending report conflict refreshes the collection owner'],
  ['payment-conflict-production.spec.js', 207, 3, 'production UI: pending report conflict refreshes ledger and collection owners'],
  ['payment-conflict-production.spec.js', 207, 3, 'production UI: synthetic expected_amount7600 refusal preserves entered amount'],
  ['payment-conflict-production.spec.js', 207, 3, 'production UI: synthetic expected_amount0 refusal preserves entered amount'],
  ['payment-conflict-production.spec.js', 239, 1, 'local guard: fixture refusal is upstream-free and unexpected writes remain blocked'],
];
// #4150 review: a payments run is conclusive only when at least one target rule (one per #4150 display fix) checked
// at least one live case in a passing test. Ordinary rows alone exercise none of the fixes.
const TARGET_RULES = ['free_gift', 'waived', 'legacy_only', 'net_zero_reversal'];
const RULE_COVERAGE = /^(free_gift|waived|legacy_only|net_zero_reversal)=(\d+)$/;
const PAYMENT_RULE = Object.fromEntries(paymentTests.map(([, , , title], index) => [title,
  ['free_gift', 'free_gift', 'waived', 'legacy_only', 'net_zero_reversal'][index]]));
const NOT_EXERCISED_SKIP = /^not-exercised: [A-Za-z0-9 _]+$/;
const COVERAGE_NOTE = /^[a-z0-9_]+=\d+$/;
const RULE_NOTE = /^[a-z0-9_]+$/;

const expectedTests = (branchId, scope = 'all') => {
  const tests = [
    ['calendar-course-consistency.spec.js', 212, 5, 'director desktop: calendar and course management agree'],
    ['calendar-course-consistency.spec.js', 212, 5, 'director mobile: calendar and course management agree'],
    ['calendar-course-consistency.spec.js', 342, 3, 'director: 科目數統計的分校占比與 API、分校及期間切換一致'],
    ['calendar-course-consistency.spec.js', 414, 5, 'director desktop: 課程付款狀態與帳務入口分開且可操作'],
    ['calendar-course-consistency.spec.js', 414, 5, 'director mobile: 課程付款狀態與帳務入口分開且可操作'],
    // Affected-path evidence for in-app reports already fixed in production (#3903 #3982 #4015 #4059 #4060 #4122 #4166 #4267).
    ['calendar-course-consistency.spec.js', 644, 3, 'director desktop: in-app 390 側欄入口與面板標題都叫「搜尋」'],
    ['calendar-course-consistency.spec.js', 664, 3, 'director desktop: in-app 395/403 帳務中心應收分頁用新標籤，不再出現舊字樣'],
    ['calendar-course-consistency.spec.js', 726, 5, 'director desktop: in-app 396 帳務入口開啟的合約卡被標示、捲到眼前並顯示課程編號'],
    ['calendar-course-consistency.spec.js', 726, 5, 'director mobile: in-app 396 帳務入口開啟的合約卡被標示、捲到眼前並顯示課程編號'],
    ['calendar-course-consistency.spec.js', 768, 3, 'director desktop: in-app 404 課程查找帳務資料每份合約顯示期間與付款狀態'],
    ['calendar-course-consistency.spec.js', 814, 3, 'director desktop: in-app 400 家長 LINE 綁定清單與統計卡和 API 一致'],
    ['calendar-course-consistency.spec.js', 858, 3, 'director desktop: in-app 411 快速排課老師選單排除停用老師'],
    ['calendar-course-consistency.spec.js', 911, 5, 'director desktop: in-app 417 學習評量表只在桌面提供「列表」切換'],
    ['calendar-course-consistency.spec.js', 911, 5, 'director mobile: in-app 417 學習評量表只在桌面提供「列表」切換'],
    ['calendar-print-production.spec.js', 203, 3, 'session branch is authorized'],
    ['calendar-print-production.spec.js', 207, 3, 'director opens week/month print preview without printing or writes'],
    ['tutoring-free-production.spec.js', 68, 3, 'director read-only API and real UI acceptance'],
    ['feedback-launcher-production.spec.js', 300, 3, 'desktop exposes reporter-facing choices without writes'],
    ['feedback-launcher-production.spec.js', 306, 3, 'mobile exposes reporter-facing choices without writes'],
  ];
  if (Number(branchId) === 9) {
    tests.splice(5, 0,
      ['calendar-course-consistency.spec.js', 449, 5, `director desktop: ${campusNineOnlyTitle}`],
      ['calendar-course-consistency.spec.js', 449, 5, `director mobile: ${campusNineOnlyTitle}`],
    );
  }
  const selected = {
    all: tests,
    calendar: tests.filter(([file]) => file === 'calendar-course-consistency.spec.js'),
    print: tests.filter(([file]) => file === 'calendar-print-production.spec.js'),
    tutoring: tests.filter(([file]) => file === 'tutoring-free-production.spec.js'),
    feedback: tests.filter(([file]) => file === 'feedback-launcher-production.spec.js'),
    payments: paymentTests,
    'payment-conflicts': paymentConflictTests,
  }[scope];
  if (!selected) throw new Error(`unsupported acceptance scope: ${scope}`);
  return selected.map(([file, line, column, title]) => ({ file, line, column, title, project: 'chromium' }));
};

// Where a non-passing attempt failed, as `spec.js:line`: the file basename and line number only, never the error text.
// A flaky first attempt is otherwise invisible in the public log (the report is encrypted), so it cannot be root-caused.
const failureLocation = (result) => {
  if (result?.status === 'passed' || result?.status === 'skipped') return null;
  const errors = Array.isArray(result?.errors) ? result.errors : [];
  const location = errors.find((error) => error?.location)?.location ?? result?.error?.location;
  const file = path.basename(String(location?.file || ''));
  const line = Number(location?.line);
  return /^[\w.-]+\.m?[jt]s$/.test(file) && Number.isInteger(line) && line > 0 ? `${file}:${line}` : 'unknown';
};

// The read-only guard's afterEach (calendar-course-consistency.spec.js) fails with expect(blockedWrites, READ_ONLY_FAILURE)
// .toEqual([]); the diff in the error text lists each blocked write as { method, origin, path } (readOnlyAppGuard.js).
// Only those three fields are read, and each write is rebuilt as `METHOD host /path`: no query string, body, header or
// other error text, and every path segment that is not a plain lowercase route word or `vN` becomes `:id`.
const READ_ONLY_FAILURE = 'read-only acceptance must not send HTTP writes to any origin';
const ANSI_COLOR = /\u001b\[[0-9;]*m/g;
const BLOCKED_WRITE_FIELDS = /"method": "([^"\n]{1,16})"[\s+,]*"origin": "([^"\n]{1,200})"[\s+,]*"path": "([^"\n]{0,500})"/g;
const ROUTE_SEGMENT = /^(?:v\d{1,3}|[a-z]+(?:[-_][a-z]+)*)$/;
const MAX_BLOCKED_WRITES_PRINTED = 10;

export function describeBlockedWrite(method, origin, pathname) {
  if (!/^[A-Z]{3,10}$/.test(method)) return null;
  let url;
  try { url = new URL(origin); } catch { return null; }
  if (!/^https?:$/.test(url.protocol) || !/^[a-z0-9][a-z0-9.-]{0,98}(?::\d{1,5})?$/.test(url.host)) return null;
  const segments = pathname.split(/[?#]/)[0].split('/').filter(Boolean).slice(0, 12)
    .map((segment) => (segment.length <= 32 && ROUTE_SEGMENT.test(segment) ? segment : ':id'));
  return `${method} ${url.host} /${segments.join('/')}`;
}

// Blocked writes named by one attempt's read-only failure: the printable ones with repeat counts, and how many were withheld.
const blockedWrites = (result) => {
  const printable = new Map();
  let withheld = 0;
  for (const error of Array.isArray(result?.errors) ? result.errors : []) {
    const text = String(error?.message ?? '').replace(ANSI_COLOR, '');
    if (!text.includes(READ_ONLY_FAILURE)) continue;
    const named = [...text.matchAll(BLOCKED_WRITE_FIELDS)].map(([, method, origin, pathname]) => describeBlockedWrite(method, origin, pathname));
    withheld += Math.max(0, (text.match(/"method":/g) || []).length - named.filter(Boolean).length);
    for (const label of named.filter(Boolean)) printable.set(label, (printable.get(label) || 0) + 1);
  }
  return { printable: [...printable], withheld };
};

const collectTests = (report) => {
  const collected = [];
  const visit = (suite) => {
    for (const spec of suite.specs || []) {
      for (const test of spec.tests || []) {
        collected.push({
          file: path.basename(spec.file || ''),
          line: spec.line,
          column: spec.column,
          title: spec.title,
          project: test.projectName,
          status: test.status,
          resultStatuses: (test.results || []).map((result) => result.status),
          failureLocations: (test.results || []).map(failureLocation),
          blockedWrites: (test.results || []).map(blockedWrites),
          // The JSON reporter repeats runtime annotations on the test and on each result; keep one of each.
          annotations: [...new Map([...(test.annotations || []), ...(test.results || []).flatMap((result) => result.annotations || [])]
            .map((note) => [`${note?.type}\u0000${note?.description}`, note])).values()],
        });
      }
    }
    for (const child of suite.suites || []) visit(child);
  };
  for (const suite of report.suites || []) visit(suite);
  return collected;
};

const isNotExercisedSkip = (item) => item.status === 'skipped' && item.resultStatuses.length > 0
  && item.resultStatuses.every((status) => status === 'skipped')
  && item.annotations.some((note) => note?.type === 'skip' && NOT_EXERCISED_SKIP.test(String(note.description || '')));

export function ruleTally(report) {
  const tally = Object.fromEntries(TARGET_RULES.map((rule) => [rule, { cases: 0, exercised: 0, notExercised: 0, failed: 0 }]));
  for (const item of collectTests(report)) {
    for (const note of item.annotations) {
      const match = note?.type === 'rule-coverage' ? RULE_COVERAGE.exec(String(note.description || '')) : null;
      if (!match) continue;
      const cases = Number(match[2]);
      if (item.status === 'expected' && cases > 0) {
        tally[match[1]].cases += cases;
        tally[match[1]].exercised += 1;
      } else if (item.status === 'expected' || isNotExercisedSkip(item)) {
        tally[match[1]].notExercised += 1;
      } else {
        tally[match[1]].failed += 1;
      }
    }
  }
  return tally;
}
const anyRuleExercised = (tally) => TARGET_RULES.some((rule) => tally[rule].exercised > 0);

// Job-log summary: file, title, status and pattern-checked counts only. Free text (error messages,
// attachments, page text) never reaches the log; the full report stays encrypted.
export function summarizeReport(report) {
  const lines = [];
  for (const item of collectTests(report)) {
    lines.push(`acceptance-result status=${item.status} results=${item.resultStatuses.join(',') || 'none'} file=${item.file} title=${item.title}`);
    item.failureLocations.forEach((at, attempt) => {
      if (!at) return;
      lines.push(`  failed-attempt=${attempt + 1} status=${item.resultStatuses[attempt]} at=${at}`);
      const { printable, withheld } = item.blockedWrites[attempt];
      for (const [label, count] of printable.slice(0, MAX_BLOCKED_WRITES_PRINTED)) {
        lines.push(`    blocked-write attempt=${attempt + 1} ${label}${count > 1 ? ` x${count}` : ''}`);
      }
      if (printable.length > MAX_BLOCKED_WRITES_PRINTED) lines.push(`    blocked-write attempt=${attempt + 1} more=${printable.length - MAX_BLOCKED_WRITES_PRINTED}`);
      if (withheld > 0) lines.push(`    blocked-write attempt=${attempt + 1} withheld=${withheld}`);
    });
    for (const note of item.annotations) {
      const description = String(note?.description || '');
      if (note?.type === 'coverage' && COVERAGE_NOTE.test(description)) lines.push(`  coverage ${description}`);
      if (note?.type === 'not-exercised' && RULE_NOTE.test(description)) lines.push(`  NOT EXERCISED rule=${description}`);
      if (note?.type === 'skip' && NOT_EXERCISED_SKIP.test(description)) lines.push(`  NOT EXERCISED ${description.slice('not-exercised: '.length)}`);
      // A passing test whose live data lacked one case: that assertion was skipped, not verified.
      if (note?.type === 'precondition-absent' && RULE_NOTE.test(description)) lines.push(`  PRECONDITION ABSENT ${description}`);
    }
  }
  const count = (status) => collectTests(report).filter((item) => item.status === status).length;
  lines.push(`acceptance-summary expected=${count('expected')} skipped=${count('skipped')} unexpected=${count('unexpected')} flaky=${count('flaky')}`);
  const notExercised = collectTests(report).filter(isNotExercisedSkip).length;
  if (notExercised > 0) lines.push(`acceptance-coverage exercised=${count('expected')} not_exercised=${notExercised}`);
  const hasRuleNotes = collectTests(report).some((item) => item.annotations.some((note) => note?.type === 'rule-coverage'));
  const tally = ruleTally(report);
  if (hasRuleNotes) {
    for (const rule of TARGET_RULES) {
      lines.push(`acceptance-rule rule=${rule} exercised=${tally[rule].exercised} not_exercised=${tally[rule].notExercised} failed=${tally[rule].failed} cases=${tally[rule].cases}`);
    }
  }
  if ((hasRuleNotes || notExercised > 0) && !anyRuleExercised(tally)) {
    lines.push('acceptance-verdict INCONCLUSIVE zero coverage (not an acceptance)');
  }
  return lines;
}

export function validateReport(report, branchId, scope = 'all') {
  const actual = collectTests(report);
  const expected = expectedTests(branchId, scope);
  // Source line/column are report metadata: unrelated edits may move them without
  // changing which acceptance case ran. Keep file/title/project as the stable key.
  const key = (test) => JSON.stringify({ file: test.file, title: test.title, project: test.project });
  const expectedKeys = new Set(expected.map(key));
  const actualKeys = new Set(actual.map(key));
  if (actual.length !== expected.length || actualKeys.size !== actual.length) {
    throw new Error(`acceptance collection mismatch: expected ${expected.length}, found ${actual.length}`);
  }
  for (const item of expected) if (!actualKeys.has(key(item))) throw new Error(`missing expected acceptance: ${key(item)}`);
  for (const item of actual) {
    if (!expectedKeys.has(key(item))) throw new Error(`unexpected acceptance: ${key(item)}`);
    if (scope === 'payments' && isNotExercisedSkip(item)) continue;
    if (item.status !== 'expected' || item.resultStatuses.length === 0 || item.resultStatuses.some((status) => status !== 'passed')) {
      throw new Error(`acceptance did not pass cleanly: ${key(item)} status=${item.status} results=${item.resultStatuses.join(',')}`);
    }
  }
  // A payments run where every rule was not exercised proves nothing about the display: inconclusive, never accepted.
  if (scope === 'payments' && !anyRuleExercised(ruleTally(report))) {
    throw new Error('acceptance INCONCLUSIVE: zero coverage, no target payment rule was exercised');
  }
}

function expectReject(label, callback) {
  let rejected = false;
  try { callback(); } catch { rejected = true; }
  if (!rejected) throw new Error(`self-test accepted invalid ${label}`);
}

function selfTest() {
  const makeReport = (branchId, scope = 'all') => ({
    suites: [{
      specs: expectedTests(branchId, scope).map((item) => ({
        file: item.file,
        line: item.line,
        column: item.column,
        title: item.title,
        tests: [{ projectName: item.project, status: 'expected', results: [{ status: 'passed' }],
          annotations: item.file === 'payment-display-production.spec.js' ? [{ type: 'rule-coverage', description: `${PAYMENT_RULE[item.title]}=1` }] : [] }],
      })),
      suites: [],
    }],
  });
  validateReport(makeReport(16), 16);
  validateReport(makeReport(9), 9);
  for (const scope of ['calendar', 'print', 'tutoring', 'feedback', 'payments', 'payment-conflicts']) {
    validateReport(makeReport(16, scope), 16, scope);
  }
  const skippedConflict = makeReport(16, 'payment-conflicts');
  skippedConflict.suites[0].specs[0].tests[0] = { projectName: 'chromium', status: 'skipped', results: [{ status: 'skipped' }], annotations: [] };
  expectReject('payment-conflicts skip', () => validateReport(skippedConflict, 16, 'payment-conflicts'));
  const lineShifted = makeReport(16);
  lineShifted.suites[0].specs.forEach((spec) => { spec.line += 100; });
  validateReport(lineShifted, 16);
  const columnShifted = makeReport(16);
  columnShifted.suites[0].specs.forEach((spec) => { spec.column += 2; });
  validateReport(columnShifted, 16);
  const wrongIdentity = makeReport(16);
  wrongIdentity.suites[0].specs[0].title = 'wrong title';
  expectReject('wrong identity', () => validateReport(wrongIdentity, 16));
  const missingIdentity = makeReport(16);
  missingIdentity.suites[0].specs.pop();
  expectReject('missing identity', () => validateReport(missingIdentity, 16));
  const duplicateIdentity = makeReport(16);
  duplicateIdentity.suites[0].specs.push(duplicateIdentity.suites[0].specs[0]);
  expectReject('duplicate identity', () => validateReport(duplicateIdentity, 16));
  const unexpectedIdentity = makeReport(16);
  unexpectedIdentity.suites[0].specs[0].file = 'unexpected.spec.js';
  expectReject('unexpected identity', () => validateReport(unexpectedIdentity, 16));
  const wrongProject = makeReport(16);
  wrongProject.suites[0].specs[0].tests[0].projectName = 'firefox';
  expectReject('wrong project', () => validateReport(wrongProject, 16));
  expectReject('malformed reporter JSON', () => validateReport(JSON.parse('{'), 16));
  expectReject('wrong reporter JSON', () => validateReport({ reporter: 'not-playwright' }, 16));
  for (const status of ['skipped', 'unexpected', 'flaky']) {
    const report = makeReport(16);
    report.suites[0].specs[0].tests[0].status = status;
    expectReject(`${status} status`, () => validateReport(report, 16));
  }
  const zeroResults = makeReport(16);
  zeroResults.suites[0].specs[0].tests[0].results = [];
  expectReject('zero results', () => validateReport(zeroResults, 16));
  const nonPassedResult = makeReport(16);
  nonPassedResult.suites[0].specs[0].tests[0].results[0].status = 'failed';
  expectReject('non-passed result', () => validateReport(nonPassedResult, 16));
  // payments: a not-exercised skip passes (and is reported), any other skip or a failure does not.
  const notExercised = makeReport(16, 'payments');
  notExercised.suites[0].specs[2].tests[0] = { projectName: 'chromium', status: 'skipped', results: [{ status: 'skipped' }],
    annotations: [{ type: 'skip', description: 'not-exercised: no waived case among 24 scanned ledgers' }] };
  validateReport(notExercised, 16, 'payments');
  if (!summarizeReport(notExercised).some((line) => line === '  NOT EXERCISED no waived case among 24 scanned ledgers')) {
    throw new Error('self-test summary did not report the not-exercised skip');
  }
  expectReject('not-exercised skip outside payments', () => validateReport({ suites: [{ specs: [{
    file: 'tutoring-free-production.spec.js', title: 'director read-only API and real UI acceptance',
    tests: [{ projectName: 'chromium', status: 'skipped', results: [{ status: 'skipped' }], annotations: [{ type: 'skip', description: 'not-exercised: x' }] }],
  }], suites: [] }] }, 16, 'tutoring'));
  const sessionSkip = makeReport(16, 'payments');
  sessionSkip.suites[0].specs[0].tests[0] = { projectName: 'chromium', status: 'skipped', results: [{ status: 'skipped' }],
    annotations: [{ type: 'skip', description: 'missing controlled production director session' }] };
  expectReject('payments session skip', () => validateReport(sessionSkip, 16, 'payments'));
  const failedPayment = makeReport(16, 'payments');
  failedPayment.suites[0].specs[1].tests[0].status = 'unexpected';
  failedPayment.suites[0].specs[1].tests[0].results = [{ status: 'failed' }];
  expectReject('payments failure', () => validateReport(failedPayment, 16, 'payments'));
  const leakyNote = makeReport(16, 'payments');
  leakyNote.suites[0].specs[0].tests[0].annotations = [{ type: 'coverage', description: 'student=王小明' }, { type: 'coverage', description: 'cards_checked=3' }];
  const leakySummary = summarizeReport(leakyNote).join('\n');
  if (leakySummary.includes('王小明') || !leakySummary.includes('coverage cards_checked=3')) throw new Error('self-test summary leaked or dropped a note');
  for (const scope of ['all', 'calendar']) {
    if (expectedTests(16, scope).some((item) => item.file === 'payment-display-production.spec.js' || item.file === 'payment-conflict-production.spec.js')) throw new Error(`payment acceptance leaked into ${scope}`);
  }
  // A missing production case is printed as PRECONDITION ABSENT (name only); the test itself still passed.
  const absent = makeReport(16);
  absent.suites[0].specs[0].tests[0].annotations = [{ type: 'precondition-absent', description: 'deactivated_teacher' },
    { type: 'precondition-absent', description: '王小明' }];
  validateReport(absent, 16);
  const absentSummary = summarizeReport(absent);
  if (!absentSummary.includes('  PRECONDITION ABSENT deactivated_teacher') || absentSummary.join('\n').includes('王小明')) {
    throw new Error('self-test summary dropped or leaked a precondition note');
  }
  // payments: all five not exercised is INCONCLUSIVE (summary says so, gate fails), even with no failure.
  const allSkipped = makeReport(16, 'payments');
  allSkipped.suites[0].specs.forEach((spec) => {
    spec.tests[0] = { projectName: 'chromium', status: 'skipped', results: [{ status: 'skipped' }],
      annotations: [{ type: 'skip', description: 'not-exercised: no case' }] };
  });
  let inconclusive = '';
  try { validateReport(allSkipped, 16, 'payments'); } catch (error) { inconclusive = String(error.message); }
  if (!inconclusive.includes('INCONCLUSIVE')) throw new Error('self-test accepted a zero-coverage payments run');
  if (!summarizeReport(allSkipped).includes('acceptance-verdict INCONCLUSIVE zero coverage (not an acceptance)')) {
    throw new Error('self-test summary did not flag zero coverage');
  }
  if (summarizeReport(notExercised).some((line) => line.includes('INCONCLUSIVE'))) throw new Error('self-test flagged a partially exercised run');
  // Ordinary rows only: both list tests pass with free_gift=0, every ledger rule is not exercised -> INCONCLUSIVE.
  const ordinary = makeReport(16, 'payments');
  ordinary.suites[0].specs.forEach((spec, index) => {
    const rule = PAYMENT_RULE[spec.title];
    spec.tests[0] = index < 2
      ? { projectName: 'chromium', status: 'expected', results: [{ status: 'passed' }], annotations: [{ type: 'rule-coverage', description: `${rule}=0` }] }
      : { projectName: 'chromium', status: 'skipped', results: [{ status: 'skipped' }],
        annotations: [{ type: 'rule-coverage', description: `${rule}=0` }, { type: 'skip', description: 'not-exercised: no case' }] };
  });
  expectReject('ordinary rows only (no target rule exercised)', () => validateReport(ordinary, 16, 'payments'));
  const ordinarySummary = summarizeReport(ordinary);
  for (const line of ['acceptance-rule rule=free_gift exercised=0 not_exercised=2 failed=0 cases=0',
    'acceptance-rule rule=waived exercised=0 not_exercised=1 failed=0 cases=0', 'acceptance-verdict INCONCLUSIVE zero coverage (not an acceptance)']) {
    if (!ordinarySummary.includes(line)) throw new Error(`self-test summary is missing: ${line}`);
  }
  // One target rule with one live case is enough to be conclusive; the rest are reported as not exercised.
  const oneRule = JSON.parse(JSON.stringify(ordinary));
  oneRule.suites[0].specs[3].tests[0] = { projectName: 'chromium', status: 'expected', results: [{ status: 'passed' }],
    annotations: [{ type: 'rule-coverage', description: 'legacy_only=2' }] };
  validateReport(oneRule, 16, 'payments');
  if (!summarizeReport(oneRule).includes('acceptance-rule rule=legacy_only exercised=1 not_exercised=0 failed=0 cases=2')) {
    throw new Error('self-test summary did not count the exercised rule');
  }
  // A failing test is reported as failed for its rule, never as exercised.
  const failedRule = JSON.parse(JSON.stringify(ordinary));
  failedRule.suites[0].specs[2].tests[0] = { projectName: 'chromium', status: 'unexpected', results: [{ status: 'failed' }],
    annotations: [{ type: 'rule-coverage', description: 'waived=1' }] };
  if (!summarizeReport(failedRule).includes('acceptance-rule rule=waived exercised=0 not_exercised=0 failed=1 cases=0')) {
    throw new Error('self-test summary did not report the failed rule');
  }
  // A skipped test can never claim coverage.
  const skippedClaim = JSON.parse(JSON.stringify(ordinary));
  skippedClaim.suites[0].specs[2].tests[0].annotations[0].description = 'waived=5';
  expectReject('coverage claimed by a skipped test', () => validateReport(skippedClaim, 16, 'payments'));
  // A flaky attempt is printed as file:line only; the error text (which may hold page text) never is.
  const flaky = makeReport(16);
  flaky.suites[0].specs[0].tests[0].status = 'flaky';
  flaky.suites[0].specs[0].tests[0].results = [
    { status: 'failed', errors: [{ message: 'expect(locator).toHaveText(expected)\nReceived: 合成學生', location: { file: '/w/frontend/e2e/calendar-course-consistency.spec.js', line: 784, column: 7 } }] },
    { status: 'passed' },
  ];
  flaky.suites[0].specs[1].tests[0].status = 'unexpected';
  flaky.suites[0].specs[1].tests[0].results = [{ status: 'timedOut', error: { message: 'Test timeout of 90000ms exceeded.' } }];
  const flakySummary = summarizeReport(flaky).join('\n');
  if (!flakySummary.includes('  failed-attempt=1 status=failed at=calendar-course-consistency.spec.js:784')
    || !flakySummary.includes('  failed-attempt=1 status=timedOut at=unknown')
    || flakySummary.includes('合成學生') || flakySummary.includes('Received') || flakySummary.includes('/w/frontend')) {
    throw new Error('self-test failure locations are wrong or leak error text');
  }
  expectReject('flaky test', () => validateReport(flaky, 16));
  // Only an ASCII script basename and a positive line pass; anything else is `unknown`, never echoed.
  const oddLocation = (location) => {
    const report = makeReport(16);
    report.suites[0].specs[0].tests[0].status = 'unexpected';
    report.suites[0].specs[0].tests[0].results = [{ status: 'failed', errors: [{ location }] }];
    return summarizeReport(report).find((line) => line.startsWith('  failed-attempt='));
  };
  for (const location of [
    { file: '/w/e2e/a b.spec.js', line: 3 }, { file: '/w/e2e/合成.spec.js', line: 3 }, { file: 'https://x/a.js?name=合成學生', line: 3 },
    { file: 'calendar.spec.js', line: -1 }, { file: 'calendar.spec.js', line: 0 }, { file: 'calendar.spec.js' }, { file: 'notes.txt', line: 3 },
  ]) {
    if (oddLocation(location) !== '  failed-attempt=1 status=failed at=unknown') {
      throw new Error(`self-test printed an unchecked location: ${JSON.stringify(location)}`);
    }
  }
  const notAList = makeReport(16);
  notAList.suites[0].specs[0].tests[0].results = [{ status: 'failed', errors: 'not a list' }];
  if (!summarizeReport(notAList).includes('  failed-attempt=1 status=failed at=unknown')) {
    throw new Error('self-test: a malformed errors field must not break the summary');
  }
  // The read-only afterEach failure names each blocked write as `METHOD host /path` (ids and query dropped). The sample is
  // Playwright's own error text: colour codes, the expect() diff, then the source frame.
  const esc = String.fromCharCode(27);
  const readOnlyFailure = (...writes) => [
    `Error: ${READ_ONLY_FAILURE}`, '', `${esc}[2mexpect(${esc}[22m${esc}[31mreceived${esc}[39m${esc}[2m).${esc}[22mtoEqual // deep equality${esc}[22m`, '',
    `${esc}[32m- Expected  -  1${esc}[39m`, `${esc}[31m+ Received  + ${writes.length * 6 + 2}${esc}[39m`, '', `${esc}[32m- Array []${esc}[39m`, `${esc}[31m+ Array [${esc}[39m`,
    ...writes.flatMap(([method, origin, pathname]) => [`${esc}[31m+   Object {${esc}[39m`, `${esc}[31m+     "method": "${method}",${esc}[39m`,
      `${esc}[31m+     "origin": "${origin}",${esc}[39m`, `${esc}[31m+     "path": "${pathname}",${esc}[39m`, `${esc}[31m+   },${esc}[39m`]),
    `${esc}[31m+ ]${esc}[39m`, '', '   at /w/frontend/e2e/calendar-course-consistency.spec.js:351:3',
  ].join('\n');
  const failedWith = (message) => {
    const report = makeReport(16);
    report.suites[0].specs[0].tests[0].status = 'flaky';
    report.suites[0].specs[0].tests[0].results = [
      { status: 'failed', errors: [{ message, location: { file: '/w/frontend/e2e/calendar-course-consistency.spec.js', line: 351 } }] },
      { status: 'passed' },
    ];
    return report;
  };
  const sentry = ['POST', 'https://o4500000000000000.ingest.us.sentry.io', '/api/4500000000000001/envelope/'];
  const named = summarizeReport(failedWith(readOnlyFailure(sentry, sentry,
    ['PATCH', 'https://daan.example.test', '/api/v1/student-classes/123/payments?token=secret#frag'],
    ['POST', 'https://daan.example.test', '/api/v1/students/王小明/3f2a9c1e-5b7d-4e21-9a0c-1d2e3f4a5b6c/Wang/a1b2'],
    ['PUT', 'https://daan.example.test', `/api/${'a'.repeat(33)}/${'b'.repeat(32)}`],
    ['DELETE', 'http://localhost:8443', '/'])));
  for (const line of [
    '  failed-attempt=1 status=failed at=calendar-course-consistency.spec.js:351',
    '    blocked-write attempt=1 POST o4500000000000000.ingest.us.sentry.io /api/:id/envelope x2',
    '    blocked-write attempt=1 PATCH daan.example.test /api/v1/student-classes/:id/payments',
    '    blocked-write attempt=1 POST daan.example.test /api/v1/students/:id/:id/:id/:id',
    `    blocked-write attempt=1 PUT daan.example.test /api/:id/${'b'.repeat(32)}`,
    '    blocked-write attempt=1 DELETE localhost:8443 /',
  ]) {
    if (!named.includes(line)) throw new Error(`self-test summary is missing: ${line}`);
  }
  if (named.some((line) => /王小明|token|secret|frag|3f2a9c1e|Wang|a1b2|4500000000000001|Received|Expected/.test(line))) {
    throw new Error('self-test summary leaked part of a blocked write or the error text');
  }
  // Not the read-only failure: nothing is read. An unusable method or origin: counted as withheld, never echoed.
  const otherFailure = summarizeReport(failedWith(readOnlyFailure(sentry).replace(READ_ONLY_FAILURE, 'expect(locator).toBeVisible()')));
  if (otherFailure.some((line) => line.includes('blocked-write'))) throw new Error('self-test printed writes from an unrelated failure');
  const odd = summarizeReport(failedWith(readOnlyFailure(['post', 'https://daan.example.test', '/api'], ['POST', 'ftp://daan.example.test', '/api'],
    ['POST', 'not a url', '/api'], ['POST', 'https://DAAN EXAMPLE.test', '/api'])));
  if (odd.some((line) => /blocked-write attempt=1 (?!withheld)/.test(line)) || !odd.includes('    blocked-write attempt=1 withheld=4')) {
    throw new Error('self-test did not withhold unusable blocked writes');
  }
  const many = summarizeReport(failedWith(readOnlyFailure(...Array.from({ length: 12 }, (_, index) => ['POST', 'https://daan.example.test', `/api/v1/route${'-x'.repeat(index + 1)}`]))));
  if (many.filter((line) => line.startsWith('    blocked-write attempt=1 POST')).length !== 10 || !many.includes('    blocked-write attempt=1 more=2')) {
    throw new Error('self-test did not cap the printed blocked writes');
  }
  // A passing attempt, a malformed errors field and an error without text print nothing and break nothing.
  const quiet = failedWith(readOnlyFailure(sentry));
  quiet.suites[0].specs[0].tests[0].results[1].errors = [{ message: readOnlyFailure(sentry) }];
  if (summarizeReport(quiet).filter((line) => line.includes('blocked-write')).length !== 1) throw new Error('self-test printed writes for a passing attempt');
  const brokenErrors = failedWith('x');
  brokenErrors.suites[0].specs[0].tests[0].results[0].errors = [null, {}, { message: 7 }];
  summarizeReport(brokenErrors);
  expectReject('flaky test with blocked writes', () => validateReport(failedWith(readOnlyFailure(sentry)), 16));
  console.log('acceptance-report-gate self-test: ok');
}

if (process.argv.includes('--self-test')) {
  selfTest();
} else {
  const reportPath = process.env.RESULTS_PATH;
  const branchId = Number(process.env.EFFECTIVE_BRANCH_ID || 0);
  const scope = process.env.ACCEPTANCE_SCOPE || 'all';
  if (!reportPath || !branchId) throw new Error('report path and effective branch are required');
  const report = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
  for (const line of summarizeReport(report)) console.log(line);
  validateReport(report, branchId, scope);
  console.log(`acceptance-report-gate: passed branch=${branchId} scope=${scope}`);
}
