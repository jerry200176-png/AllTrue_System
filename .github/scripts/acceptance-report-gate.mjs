import fs from 'node:fs';
import path from 'node:path';

const campusNineOnlyTitle = 'contracted four-session detail explains two unarranged sessions';

const expectedTests = (branchId) => {
  const tests = [
    ['calendar-course-consistency.spec.js', 213, 5, 'director desktop: calendar and course management agree'],
    ['calendar-course-consistency.spec.js', 213, 5, 'director mobile: calendar and course management agree'],
    ['calendar-course-consistency.spec.js', 343, 3, 'director: 科目數統計的分校占比與 API、分校及期間切換一致'],
    ['calendar-course-consistency.spec.js', 415, 5, 'director desktop: 課程付款狀態與帳務入口分開且可操作'],
    ['calendar-course-consistency.spec.js', 415, 5, 'director mobile: 課程付款狀態與帳務入口分開且可操作'],
    ['calendar-print-production.spec.js', 204, 3, 'session branch is authorized'],
    ['calendar-print-production.spec.js', 208, 3, 'director opens week/month print preview without printing or writes'],
    ['tutoring-free-production.spec.js', 94, 3, 'director read-only API and real UI acceptance'],
  ];
  if (Number(branchId) === 9) {
    tests.splice(5, 0,
      ['calendar-course-consistency.spec.js', 450, 5, `director desktop: ${campusNineOnlyTitle}`],
      ['calendar-course-consistency.spec.js', 450, 5, `director mobile: ${campusNineOnlyTitle}`],
    );
  }
  return tests.map(([file, line, column, title]) => ({ file, line, column, title, project: 'chromium' }));
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
        });
      }
    }
    for (const child of suite.suites || []) visit(child);
  };
  for (const suite of report.suites || []) visit(suite);
  return collected;
};

export function validateReport(report, branchId) {
  const actual = collectTests(report);
  const expected = expectedTests(branchId);
  const key = (test) => JSON.stringify({ file: test.file, line: test.line, column: test.column, title: test.title, project: test.project });
  const expectedKeys = new Set(expected.map(key));
  const actualKeys = new Set(actual.map(key));
  if (actual.length !== expected.length || actualKeys.size !== actual.length) {
    throw new Error(`acceptance collection mismatch: expected ${expected.length}, found ${actual.length}`);
  }
  for (const item of expected) if (!actualKeys.has(key(item))) throw new Error(`missing expected acceptance: ${key(item)}`);
  for (const item of actual) {
    if (!expectedKeys.has(key(item))) throw new Error(`unexpected acceptance: ${key(item)}`);
    if (item.status !== 'expected' || item.resultStatuses.length === 0 || item.resultStatuses.some((status) => status !== 'passed')) {
      throw new Error(`acceptance did not pass cleanly: ${key(item)} status=${item.status} results=${item.resultStatuses.join(',')}`);
    }
  }
}

export function summarizeReport(report, branchId) {
  validateReport(report, branchId);
  const tests = collectTests(report).map(({ file, line, column, title, project, status }) => ({
    file, line, column, title, project, status,
  }));
  return {
    schema_version: 1,
    status: 'passed',
    branch_id: branchId,
    test_count: tests.length,
    tests,
  };
}

function expectReject(label, callback) {
  let rejected = false;
  try { callback(); } catch { rejected = true; }
  if (!rejected) throw new Error(`self-test accepted invalid ${label}`);
}

function selfTest() {
  const makeReport = (branchId) => ({
    suites: [{
      specs: expectedTests(branchId).map((item) => ({
        file: item.file,
        line: item.line,
        column: item.column,
        title: item.title,
        tests: [{ projectName: item.project, status: 'expected', results: [{ status: 'passed' }] }],
      })),
      suites: [],
    }],
  });
  validateReport(makeReport(16), 16);
  validateReport(makeReport(9), 9);
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
  const summary = summarizeReport(makeReport(16), 16);
  if (summary.status !== 'passed' || summary.branch_id !== 16 || summary.test_count !== expectedTests(16).length
    || summary.tests.some((test) => Object.keys(test).some((key) => ['error', 'message', 'stdout', 'stderr', 'attachments'].includes(key)))) {
    throw new Error('self-test sanitized summary failed');
  }
  console.log('acceptance-report-gate self-test: ok');
}

if (process.argv.includes('--self-test')) {
  selfTest();
} else {
  const reportPath = process.env.RESULTS_PATH;
  const branchId = Number(process.env.EFFECTIVE_BRANCH_ID || 0);
  if (!reportPath || !branchId) throw new Error('report path and effective branch are required');
  const summary = summarizeReport(JSON.parse(fs.readFileSync(reportPath, 'utf8')), branchId);
  const summaryPath = process.env.SUMMARY_PATH;
  if (!summaryPath) throw new Error('summary path is required');
  fs.writeFileSync(summaryPath, `${JSON.stringify(summary)}\n`);
  console.log(`acceptance-report-gate: passed branch=${branchId} tests=${summary.test_count}`);
}
