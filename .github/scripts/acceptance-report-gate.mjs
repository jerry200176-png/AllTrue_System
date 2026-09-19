import fs from 'node:fs';
import path from 'node:path';

const campusNineOnlyTitle = 'contracted four-session detail explains two unarranged sessions';

const expectedTests = (branchId) => {
  const tests = [
    ['calendar-course-consistency.spec.js', 212, 5, 'director desktop: calendar and course management agree'],
    ['calendar-course-consistency.spec.js', 212, 5, 'director mobile: calendar and course management agree'],
    ['calendar-course-consistency.spec.js', 342, 3, 'director: 科目數統計的分校占比與 API、分校及期間切換一致'],
    ['calendar-course-consistency.spec.js', 414, 5, 'director desktop: 課程付款狀態與帳務入口分開且可操作'],
    ['calendar-course-consistency.spec.js', 414, 5, 'director mobile: 課程付款狀態與帳務入口分開且可操作'],
    ['calendar-print-production.spec.js', 203, 3, 'session branch is authorized'],
    ['calendar-print-production.spec.js', 207, 3, 'director opens week/month print preview without printing or writes'],
    ['tutoring-free-production.spec.js', 68, 3, 'director read-only API and real UI acceptance'],
  ];
  if (Number(branchId) === 9) {
    tests.splice(5, 0,
      ['calendar-course-consistency.spec.js', 449, 5, `director desktop: ${campusNineOnlyTitle}`],
      ['calendar-course-consistency.spec.js', 449, 5, `director mobile: ${campusNineOnlyTitle}`],
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
  const skipped = makeReport(16);
  skipped.suites[0].specs[0].tests[0].status = 'skipped';
  let rejected = false;
  try { validateReport(skipped, 16); } catch { rejected = true; }
  if (!rejected) throw new Error('self-test failed: skipped report was accepted');
  console.log('acceptance-report-gate self-test: ok');
}

if (process.argv.includes('--self-test')) {
  selfTest();
} else {
  const reportPath = process.env.RESULTS_PATH;
  const branchId = Number(process.env.EFFECTIVE_BRANCH_ID || 0);
  if (!reportPath || !branchId) throw new Error('report path and effective branch are required');
  validateReport(JSON.parse(fs.readFileSync(reportPath, 'utf8')), branchId);
  console.log(`acceptance-report-gate: passed branch=${branchId}`);
}
