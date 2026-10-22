import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { aggregate, collectAttempts, collectRuns, main, render, RUNS_PER_DAY_FLAG, WINDOW_DAYS } from '../../.github/scripts/actions-cost-report.mjs';

const run = (name, min, conclusion = 'success') => ({ name, run_started_at: '2026-10-20T00:00:00Z', updated_at: new Date(Date.parse('2026-10-20T00:00:00Z') + min * 60000).toISOString(), conclusion });

test('aggregate: sums runs and minutes per workflow, sorted by minutes', () => {
  const rows = aggregate([run('A', 2), run('A', 3, 'failure'), run('B', 10), run('B', 0, 'cancelled')]);
  assert.deepEqual(rows.map((r) => [r.name, r.runs, Math.round(r.minutes), r.failed, r.cancelled]), [['B', 2, 10, 0, 1], ['A', 2, 5, 1, 0]]);
});

test('aggregate: flag threshold follows the parameters', () => {
  const runs = Array.from({ length: 7 * 10 }, () => run('Q', 1)); // 10/day
  assert.equal(aggregate(runs)[0].flagged, false);
  assert.equal(aggregate(runs, { flagPerDay: 9 })[0].flagged, true);
  assert.equal(aggregate(runs, { days: 1, flagPerDay: RUNS_PER_DAY_FLAG })[0].flagged, true); // 70/day over a 1-day window
  assert.equal(WINDOW_DAYS > 0, true);
});

test('render: names flagged workflows and the spending-limit note; handles missing timestamps', () => {
  const rows = aggregate([run('land-queue', 1), { name: 'x', conclusion: 'success' }], { days: 1, flagPerDay: 0 });
  const md = render(rows, { date: '2026-10-21', days: 1, flagPerDay: 0 });
  assert.match(md, /\*\*land-queue\*\*/);
  assert.match(md, /spending limit/);
  assert.match(md, /<!-- actions-cost-report -->/);
});

const base = Date.parse('2026-10-20T00:00:00Z');
const timestamp = (offset) => new Date(base + offset).toISOString();
const record = (id, offset = 0, extra = {}) => ({
  id, name: 'A', created_at: timestamp(offset), run_attempt: 1,
  status: 'completed', conclusion: 'success', run_started_at: timestamp(offset),
  updated_at: timestamp(offset + 60000), ...extra,
});
const options = { repo: 'owner/repo', since: base, until: base + 4000 };

// Model the API's inclusive creation search, hard 1,000-result search ceiling,
// and 100-item pages independently of the collector. Deliberately cap total_count
// too, so detecting only total_count > 1000 would lose real records in this test.
function runsApi(records) {
  const calls = [];
  const api = (path) => {
    const url = new URL(path, 'https://api.github.com');
    calls.push(url);
    assert.equal(url.pathname, '/repos/owner/repo/actions/runs');
    assert.equal(url.searchParams.has('status'), false);
    assert.equal(url.searchParams.get('per_page'), '100');
    const [start, end] = url.searchParams.get('created').split('..').map(Date.parse);
    const matching = records.filter((r) => Date.parse(r.created_at) >= start && Date.parse(r.created_at) <= end);
    const visible = matching.slice(0, 1000);
    const n = Number(url.searchParams.get('page'));
    return { total_count: visible.length, workflow_runs: visible.slice((n - 1) * 100, n * 100) };
  };
  return { api, calls };
}

test('real cap: splits 1,205 runs before pagination, preserving split and outer boundaries exactly once', () => {
  const records = Array.from({ length: 1205 }, (_, i) => record(i + 1, (i % 4) * 1000));
  const outside = [record(9001, -1000), record(9002, 4000)];
  const { api, calls } = runsApi([...records, ...outside]);
  const collected = collectRuns(api, options);
  assert.deepEqual(collected.map((r) => r.id).sort((a, b) => a - b), records.map((r) => r.id));
  assert.equal(new Set(collected.map((r) => r.id)).size, 1205);
  const ranges = [...new Set(calls.map((u) => u.searchParams.get('created')))];
  assert.deepEqual(ranges, [
    '2026-10-20T00:00:00Z..2026-10-20T00:00:03Z',
    '2026-10-20T00:00:00Z..2026-10-20T00:00:01Z',
    '2026-10-20T00:00:02Z..2026-10-20T00:00:03Z',
  ]);
  assert.equal(calls.filter((u) => u.searchParams.get('created') === ranges[0]).length, 1);
  assert.equal(calls.filter((u) => u.searchParams.get('page') === '7').length, 2);
});

test('cap: 1,000 runs in one second are irreducible, even if the count is exactly the ceiling', () => {
  const { api } = runsApi(Array.from({ length: 1000 }, (_, i) => record(i + 1)));
  assert.throws(() => collectRuns(api, options), /cap cannot be split/);
});

test('cap: a count above the ceiling also splits and each child paginates', () => {
  const { api } = runsApi(Array.from({ length: 1200 }, (_, i) => record(i + 1, (i % 4) * 1000)));
  let rootCalls = 0;
  const result = collectRuns((path) => {
    const data = api(path);
    if (new URL(path, 'https://api.github.com').searchParams.get('created').endsWith('00:00:03Z') && ++rootCalls === 1) data.total_count = 1200;
    return data;
  }, options);
  assert.equal(result.length, 1200);
});

test('pagination: rejects partial pages, duplicates, changed totals and incomplete search results', () => {
  for (const corruption of ['partial', 'duplicate', 'changed', 'incomplete']) {
    const { api } = runsApi(Array.from({ length: 220 }, (_, i) => record(i + 1)));
    const corrupt = (path) => {
      const data = api(path);
      if (corruption === 'incomplete') data.incomplete_results = true;
      if (new URL(path, 'https://api.github.com').searchParams.get('page') === '2') {
        if (corruption === 'partial') data.workflow_runs.pop();
        if (corruption === 'duplicate') data.workflow_runs[0] = record(1);
        if (corruption === 'changed') data.total_count -= 1;
      }
      return data;
    };
    assert.throws(() => collectRuns(corrupt, options), /partial|Duplicate|Incomplete/, corruption);
  }
});

test('attempts: earlier failure/cancellation survive successful and ongoing reruns; boundaries and scope are explicit', () => {
  const until = base + 3600000;
  const records = [
    record(1, 0, { run_attempt: 2, run_started_at: timestamp(1200000), updated_at: timestamp(1500000) }),
    record(2, 0, { run_attempt: 3, status: 'in_progress', conclusion: null }),
    record(3, 0, { status: 'queued', conclusion: null, run_started_at: null, updated_at: null }),
    record(4, 0, { updated_at: timestamp(3600000) }),
    record(5, -86400000, { run_attempt: 2, run_started_at: timestamp(1800000), updated_at: timestamp(1900000) }),
  ];
  const historical = new Map([
    ['1/1', record(1, 0, { conclusion: 'failure', updated_at: timestamp(600000) })],
    ['2/1', record(2, 0, { conclusion: 'failure', updated_at: timestamp(120000) })],
    ['2/2', record(2, 0, { run_attempt: 2, conclusion: 'cancelled', run_started_at: timestamp(180000), updated_at: timestamp(360000) })],
  ]);
  const { api } = runsApi(records);
  const fetched = [];
  const result = collectAttempts((path) => {
    const match = path.match(/runs\/(\d+)\/attempts\/(\d+)$/);
    if (!match) return api(path);
    const key = `${match[1]}/${match[2]}`;
    fetched.push(key);
    assert.ok(historical.has(key), `unexpected attempt ${key}`);
    return historical.get(key);
  }, { ...options, until });
  assert.deepEqual(fetched, ['1/1', '2/1', '2/2']);
  assert.deepEqual(aggregate(result.attempts).map((r) => [r.runs, r.minutes, r.failed, r.cancelled]), [[4, 20, 2, 1]]);
  assert.equal(result.coverage.runCount, 4);
  assert.deepEqual(result.coverage.nonCompleted, { in_progress: 1, queued: 1 });
  assert.equal(result.coverage.afterCutoff, 1);
  const md = render(aggregate(result.attempts), { date: '2026-10-20', coverage: result.coverage });
  assert.match(md, /1 in_progress, 1 queued; 1 completed at\/after the cutoff/);
  assert.match(md, /\[2026-10-20T00:00:00Z, 2026-10-20T01:00:00Z\)/);
  assert.match(md, /reruns this week of runs originally created before the cohort are excluded/);
  assert.match(md, /not complete seven-day repository usage/);
  assert.match(md, /not billed minutes or monetary\/token cost/);
});

test('attempt integrity: missing metadata, mismatched identity and incomplete historical attempts fail closed', () => {
  const opts = { ...options, until: base + 3600000 };
  for (const extra of [{ run_attempt: undefined }, { updated_at: null }, { run_started_at: 'bad' }, { conclusion: null }]) {
    const { api } = runsApi([record(1, 0, extra)]);
    assert.throws(() => collectAttempts(api, opts), /Missing or invalid|Missing attempt/);
  }
  const missingName = runsApi([record(1, 0, { name: undefined })]);
  assert.throws(() => collectAttempts(missingName.api, opts), /Missing workflow name/);
  for (const extra of [{ id: 2 }, { run_attempt: 2 }, { status: 'in_progress' }]) {
    const { api } = runsApi([record(1, 0, { run_attempt: 2 })]);
    assert.throws(() => collectAttempts((path) => path.includes('/attempts/') ? record(1, 0, extra) : api(path), opts), /Invalid attempt|Historical attempt/);
  }
});

test('main: data failures stop before any tracker lookup, issue creation, or comment', () => {
  const now = base + 7 * 86400000;
  for (const kind of ['cap', 'partial', 'missing-attempt']) {
    const records = Array.from({ length: kind === 'cap' ? 1000 : kind === 'partial' ? 120 : 1 }, (_, i) => record(i + 1, 0, kind === 'missing-attempt' ? { run_attempt: 2 } : {}));
    const { api } = runsApi(records);
    const calls = [];
    const gh = (args) => {
      calls.push(args);
      assert.equal(args.length, 2);
      if (args[1].includes('/attempts/')) throw new Error('attempt unavailable (404)');
      const data = api(args[1]);
      if (kind === 'partial' && args[1].endsWith('page=2')) data.workflow_runs = [];
      return JSON.stringify(data);
    };
    assert.throws(() => main({ repo: options.repo, now, gh }), /cap cannot be split|partial|attempt unavailable/, kind);
    assert.equal(calls.some((args) => args.includes('POST') || args.some((a) => a.includes('/issues'))), false);
  }
});

test('main: posts the bounded attempt report to the existing tracker after collection succeeds', () => {
  const { api } = runsApi([record(1)]);
  const posts = [];
  const gh = (args, input) => {
    if (args.includes('POST')) {
      posts.push({ path: args[3], body: JSON.parse(input).body });
      return '{}';
    }
    if (args[1].includes('/issues?')) {
      assert.deepEqual(args.slice(2), ['--paginate', '--slurp']);
      return JSON.stringify([[{ number: 42, state: 'open', body: '<!-- actions-cost-report -->' }]]);
    }
    return JSON.stringify(api(args[1]));
  };
  main({ repo: options.repo, now: base + WINDOW_DAYS * 86400000, gh });
  assert.equal(posts.length, 1);
  assert.equal(posts[0].path, '/repos/owner/repo/issues/42/comments');
  assert.match(posts[0].body, /Total: 1 completed attempts, about 1 wall-clock minutes/);
  assert.match(posts[0].body, /Scope: 1 runs originally created in \[2026-10-20T00:00:00Z, 2026-10-27T00:00:00Z\)/);
  assert.match(posts[0].body, /not complete seven-day repository usage/);
});

const openIssue = (number, body = null, extra = {}) => ({ number, state: 'open', body, labels: [], ...extra });
function trackerStub({ issuePages = [[]], labelPages = [[{ name: 'ops-hygiene' }]], failure } = {}) {
  const { api } = runsApi([]);
  const calls = [];
  const gh = (args, input) => {
    const writing = args.includes('POST');
    const path = args[writing ? 3 : 1];
    const body = input && JSON.parse(input);
    calls.push({ writing, path, body });
    if (writing) {
      if (path.endsWith('/labels')) return JSON.stringify({ name: body.name });
      if (path.endsWith('/issues')) return JSON.stringify(openIssue(500, body.body, { labels: body.labels.map((name) => ({ name })) }));
      if (path.endsWith('/comments')) return '{}';
      assert.fail(`unexpected write: ${path}`);
    }
    if (path.includes('/issues?') || path.includes('/labels?')) {
      assert.deepEqual(args.slice(2), ['--paginate', '--slurp']);
      const list = path.includes('/issues?') ? 'issues' : 'labels';
      if (failure === list) throw new Error(`${list} later page failed (403)`);
      return JSON.stringify(list === 'issues' ? issuePages : labelPages);
    }
    return JSON.stringify(api(path));
  };
  return { calls, run: () => main({ repo: options.repo, now: base + WINDOW_DAYS * 86400000, gh }) };
}

test('tracker: reuses an unlabelled marker after the first 100 issues, ignoring a marker on a pull request', () => {
  const first = Array.from({ length: 100 }, (_, i) => openIssue(i + 1));
  first[0] = openIssue(1, '<!-- actions-cost-report -->', { pull_request: { url: '/pulls/1' } });
  const { calls, run } = trackerStub({ issuePages: [first, [openIssue(142, '<!-- actions-cost-report -->')]] });
  run();
  assert.deepEqual(calls.filter((c) => c.writing).map((c) => c.path), ['/repos/owner/repo/issues/142/comments']);
  assert.equal(calls.some((c) => c.path.includes('/labels')), false, 'an existing unlabelled tracker needs no label mutation');
  assert.match(calls.find((c) => c.path.includes('/issues?')).path, /state=open&sort=created&direction=asc&per_page=100/);
  assert.equal(calls.some((c) => c.path.includes('state=all') || c.path.includes('state=closed')), false);
});

test('tracker: provisions a missing label after complete read-only lookups, then creates and comments on the active tracker', () => {
  const { calls, run } = trackerStub({ labelPages: [[]] });
  run();
  assert.deepEqual(calls.map((c) => [c.writing, c.path.split('?')[0]]), [
    [false, '/repos/owner/repo/actions/runs'], [false, '/repos/owner/repo/issues'],
    [false, '/repos/owner/repo/labels'], [true, '/repos/owner/repo/labels'],
    [true, '/repos/owner/repo/issues'], [true, '/repos/owner/repo/issues/500/comments'],
  ]);
  assert.equal(calls.find((c) => c.writing && c.path.endsWith('/labels')).body.name, 'ops-hygiene');
  assert.deepEqual(calls.find((c) => c.writing && c.path.endsWith('/issues')).body.labels, ['ops-hygiene']);
  assert.equal(calls.some((c) => c.body?.state === 'open'), false, 'closed issues are never reopened');
});

test('tracker: a label on a later page is reused without creating or changing its definition', () => {
  const labels = Array.from({ length: 100 }, (_, i) => ({ name: `other-${i}` }));
  const { calls, run } = trackerStub({ labelPages: [labels, [{ name: 'ops-hygiene' }]] });
  run();
  assert.deepEqual(calls.filter((c) => c.writing).map((c) => c.path), ['/repos/owner/repo/issues', '/repos/owner/repo/issues/500/comments']);
});

test('tracker: failed later-page lookups and unknown schemas never provision a label or create/comment on a tracker', () => {
  const cases = [
    { failure: 'issues' }, { failure: 'labels' },
    { issuePages: { items: [] } }, { issuePages: [] },
    { issuePages: [[{ number: 42 }]] }, { issuePages: [[openIssue(1, '<!-- actions-cost-report -->', { pull_request: 'unknown' })]] },
    { labelPages: [[{ unexpected: 'schema' }]] },
    { issuePages: [[openIssue(1)], [openIssue(2)]] },
    { issuePages: [[openIssue(1), openIssue(1)]] },
    { issuePages: [[openIssue(1, '<!-- actions-cost-report -->'), openIssue(2, '<!-- actions-cost-report -->')]] },
  ];
  for (const configuration of cases) {
    const { calls, run } = trackerStub(configuration);
    assert.throws(run, /later page failed|Invalid paginated lookup|Duplicate issues|Multiple active/);
    assert.equal(calls.some((c) => c.writing), false, JSON.stringify(configuration));
  }
});

test('workflow: schedule and manual runs serialize across refs without cancelling an active tracker writer', () => {
  const workflow = readFileSync(new URL('../../.github/workflows/actions-cost-report.yml', import.meta.url), 'utf8');
  assert.match(workflow, /^  schedule:/m);
  assert.match(workflow, /^  workflow_dispatch: \{\}/m);
  const concurrency = workflow.match(/^concurrency:\n((?:  .+\n)+)/m)?.[1];
  assert.ok(concurrency, 'workflow-level concurrency is required');
  assert.match(concurrency, /^  group: actions-cost-report$/m);
  assert.match(concurrency, /^  cancel-in-progress: false$/m);
  assert.equal(concurrency.includes('${{'), false, 'the group must not vary by event, ref or run');
});
