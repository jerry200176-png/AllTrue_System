// @vitest-environment node
import { describe, it, expect } from 'vitest';
import { installReadOnlyAppGuard } from './readOnlyAppGuard.js';
import {
  CLASS_SESSION_LIST_PATH,
  CLASS_SESSION_PROJECTION_PATH,
  classSessionReadKind,
  installLocalClassSessionReads,
  isNonMaterializingWindow,
  localClassSessionBody,
  nonMaterializingProjectionPath,
  projectionSource,
  strictYmd,
} from './classSessionReadResponder.js';

// #5175: the guard blocks class-session reads; the acceptance spec answers them locally.
const APP = 'https://app.example.test';

const row = (id, classId, date, extra = {}) => ({
  id, student_class_id: classId, session_date: date, start_time: '10:00', status: 'scheduled', ...extra,
});
const ROWS = [row(1, 11, '2026-08-05'), row(2, 11, '2026-08-06'), row(3, 12, '2026-08-07', { status: 'attended' })];
const payload = (rows = ROWS) => ({
  api_kind: 'projection',
  completeness: 'full',
  total: rows.length,
  data: rows,
  by_class: Object.fromEntries([11, 12].map((id) => [id, rows.filter((r) => r.student_class_id === id)])),
  projected: { by_class: { 11: [{ session_date: '2026-08-06' }] } },
});
const source = (overrides = {}) => projectionSource({
  branchId: 16, start: '2026-08-05', end: '2026-08-07', payload: payload(), ...overrides,
});
const query = (text) => new URLSearchParams(text);

/** Playwright context double: routes run newest first; fallback() hands over to the next one. */
function fakeContext() {
  const routes = [];
  const sent = [];
  return {
    sent,
    route: async (predicate, handler) => { routes.unshift({ predicate, handler }); },
    async dispatch(method, url) {
      const outcome = { method, url, action: null, response: null };
      const matching = routes.filter(({ predicate }) => predicate(new URL(url)));
      let index = 0;
      const run = async () => {
        const entry = matching[index];
        index += 1;
        if (!entry) { outcome.action = 'network'; sent.push(`${method} ${url}`); return; }
        await entry.handler({
          request: () => ({ method: () => method, url: () => url, postDataBuffer: () => null }),
          fulfill: async (response) => { outcome.action = 'fulfill'; outcome.response = response; },
          abort: async () => { outcome.action = 'abort'; },
          continue: async () => { outcome.action = 'network'; sent.push(`${method} ${url}`); },
          fallback: run,
        });
      };
      await run();
      return outcome;
    },
  };
}

describe('classSessionReadResponder — which direct read is safe', () => {
  it('accepts only real YYYY-MM-DD dates', () => {
    expect(strictYmd('2026-08-05')).toBe('2026-08-05');
    for (const bad of ['2026-8-5', '2026-08-05T00:00:00', '2026-08-05 23:59', '2026-02-30', '20260805', '', null, undefined, 20260805]) {
      expect(strictYmd(bad), String(bad)).toBe('');
    }
  });

  it('treats only a window of two or more days as non-materializing', () => {
    expect(isNonMaterializingWindow('2026-08-05', '2026-08-07')).toBe(true);
    expect(isNonMaterializingWindow('2026-08-05', '2026-08-06')).toBe(true);
    // The controller materializes when start === end; reversed and loose dates are refused too.
    expect(isNonMaterializingWindow('2026-08-05', '2026-08-05')).toBe(false);
    expect(isNonMaterializingWindow('2026-08-07', '2026-08-05')).toBe(false);
    expect(isNonMaterializingWindow('2026-08-05', '2026-08-05T23:59')).toBe(false);
    expect(isNonMaterializingWindow('', '2026-08-07')).toBe(false);
    expect(isNonMaterializingWindow('2026-08-05', undefined)).toBe(false);
  });

  it('builds the direct projection read only for a safe window', () => {
    expect(nonMaterializingProjectionPath({ branchId: 16, start: '2026-08-05', end: '2026-08-07' }))
      .toBe(`${CLASS_SESSION_PROJECTION_PATH}?branch_id=16&start=2026-08-05&end=2026-08-07`);
    expect(() => nonMaterializingProjectionPath({ branchId: 16, start: '2026-08-05', end: '2026-08-05' }))
      .toThrow(/one-day window makes the server create class sessions/);
    expect(() => nonMaterializingProjectionPath({ branchId: 16, start: '2026-08-07', end: '2026-08-05' })).toThrow();
    expect(() => nonMaterializingProjectionPath({ branchId: 0, start: '2026-08-05', end: '2026-08-07' }))
      .toThrow(/positive branch id/);
    expect(() => nonMaterializingProjectionPath({ branchId: '16', start: '2026-08-05', end: '2026-08-07' })).toThrow();
  });
});

describe('classSessionReadResponder — what is answered locally', () => {
  it('answers only plain GET/HEAD to the two paths on the app origin', () => {
    expect(classSessionReadKind(APP, 'GET', `${APP}${CLASS_SESSION_LIST_PATH}?start=2026-08-05`)).toBe('list');
    expect(classSessionReadKind(APP, 'head', `${APP}${CLASS_SESSION_LIST_PATH}`)).toBe('list');
    expect(classSessionReadKind(APP, 'GET', `${APP}${CLASS_SESSION_PROJECTION_PATH}?branch_id=16`)).toBe('projection');
    // Writes, sub-resources, other origins and odd spellings are left to the guard.
    expect(classSessionReadKind(APP, 'POST', `${APP}${CLASS_SESSION_LIST_PATH}`)).toBeNull();
    expect(classSessionReadKind(APP, 'PATCH', `${APP}${CLASS_SESSION_LIST_PATH}/9`)).toBeNull();
    expect(classSessionReadKind(APP, 'GET', `${APP}${CLASS_SESSION_LIST_PATH}/9/recovery`)).toBeNull();
    expect(classSessionReadKind(APP, 'GET', `${APP}/api/v1/class-sessions/batch`)).toBeNull();
    expect(classSessionReadKind(APP, 'GET', `https://other.example.test${CLASS_SESSION_LIST_PATH}`)).toBeNull();
    expect(classSessionReadKind(APP, 'GET', `${APP}/api/v1/class-sessions/`)).toBeNull();
    expect(classSessionReadKind(APP, 'GET', `${APP}/api/v1/class-sessions/%70rojection`)).toBeNull();
    expect(classSessionReadKind('', 'GET', `${APP}${CLASS_SESSION_LIST_PATH}`)).toBeNull();
  });

  it('refuses a source that is not a complete multi-day projection', () => {
    expect(source().rows).toHaveLength(3);
    expect(() => source({ start: '2026-08-05', end: '2026-08-05' })).toThrow(/multi-day window/);
    expect(() => source({ branchId: 0 })).toThrow(/positive branch id/);
    expect(() => source({ payload: { ...payload(), completeness: 'partial' } })).toThrow(/complete projection/);
    expect(() => source({ payload: { ...payload(), api_kind: 'index' } })).toThrow(/complete projection/);
    expect(() => source({ payload: { api_kind: 'projection', completeness: 'full' } })).toThrow(/complete projection/);
    expect(() => source({ payload: null })).toThrow(/complete projection/);
  });

  it('answers a list read with an empty list in both response shapes', () => {
    const body = localClassSessionBody('list', query('branch_id=16&start=2026-08-05&end=2026-08-05&per_page=500'), source());
    expect(body.data).toEqual([]);
    expect(body.by_class).toEqual({});
    expect(body).toMatchObject({ current_page: 1, last_page: 1, per_page: 500, total: 0 });
    expect(body.materialized).toMatchObject({ data: [], by_class: {}, current_page: 1, last_page: 1, per_page: 500, total: 0 });
    expect(body.projected).toEqual({ by_class: {} });
    expect(localClassSessionBody('list', query(''), null).per_page).toBe(1000);
  });

  it('answers a projection read with the loaded rows inside the requested window', () => {
    const week = localClassSessionBody('projection', query('branch_id=16&start=2026-08-03&end=2026-08-09'), source());
    expect(week).toMatchObject({ api_kind: 'projection', completeness: 'full', total: 3 });
    expect(week.data.map((r) => r.id)).toEqual([1, 2, 3]);
    expect(Object.keys(week.by_class)).toEqual(['11', '12']);
    // The calendar refuses a paginated projection (classSessionsApi fetchClassSessionsProjection).
    expect(week).not.toHaveProperty('current_page');
    expect(week).not.toHaveProperty('last_page');

    // A one-day request is answered from the loaded rows; it is never sent to the server.
    const day = localClassSessionBody('projection', query('branch_id=16&start=2026-08-06&end=2026-08-06'), source());
    expect(day.data.map((r) => r.id)).toEqual([2]);
    expect(day.by_class).toEqual({ 11: [ROWS[1]] });
    expect(day.total).toBe(1);

    const outside = localClassSessionBody('projection', query('branch_id=16&start=2026-11-09&end=2026-11-15'), source());
    expect(outside).toMatchObject({ api_kind: 'projection', completeness: 'full', total: 0, data: [], by_class: {} });
  });

  it('never serves rows to another campus, outside the loaded window, or without a source', () => {
    expect(localClassSessionBody('projection', query('branch_id=2&start=2026-08-03&end=2026-08-09'), source()).data).toEqual([]);
    expect(localClassSessionBody('projection', query('start=2026-08-03&end=2026-08-09'), source()).data).toEqual([]);
    expect(localClassSessionBody('projection', query('campus_id=16&start=2026-08-03&end=2026-08-09'), source()).total).toBe(3);
    expect(localClassSessionBody('projection', query('branch_id=16&start=2026-08-03&end=2026-08-09'), null).data).toEqual([]);
    // A row the payload carried from outside its own window is not presented as part of it.
    const stray = source({ payload: payload([...ROWS, row(4, 12, '2026-08-09')]) });
    expect(localClassSessionBody('projection', query('branch_id=16&start=2026-08-03&end=2026-08-09'), stray)
      .data.map((r) => r.id)).toEqual([1, 2, 3]);
    expect(() => localClassSessionBody('other', query(''), null)).toThrow(/unknown class-session read kind/);
  });

  it('reads the date from either row spelling', () => {
    const legacy = source({ payload: { ...payload([]), data: [{ id: 9, StudentClassID: 11, SessionDate: '2026-08-06 00:00:00' }] } });
    expect(localClassSessionBody('projection', query('branch_id=16&start=2026-08-06&end=2026-08-06'), legacy).data).toHaveLength(1);
  });
});

describe('classSessionReadResponder — installed after the guard', () => {
  async function arm() {
    const context = fakeContext();
    const blocked = await installReadOnlyAppGuard(context, APP);
    const reads = await installLocalClassSessionReads(context, APP);
    return { context, blocked, reads };
  }

  it('answers class-session reads in the browser; none is sent and none is recorded as blocked', async () => {
    const { context, blocked, reads } = await arm();
    reads.serveProjection({ branchId: 16, start: '2026-08-05', end: '2026-08-07', payload: payload() });

    const projection = await context.dispatch('GET', `${APP}${CLASS_SESSION_PROJECTION_PATH}?branch_id=16&start=2026-08-03&end=2026-08-09`);
    expect(projection.action).toBe('fulfill');
    expect(projection.response).toMatchObject({ status: 200, contentType: 'application/json' });
    expect(JSON.parse(projection.response.body).data.map((r) => r.id)).toEqual([1, 2, 3]);

    // The director dashboard's one-day read: the request that makes the server create rows.
    const today = await context.dispatch('GET', `${APP}${CLASS_SESSION_LIST_PATH}?branch_id=16&start=2026-11-10&end=2026-11-10&per_page=500`);
    expect(today.action).toBe('fulfill');
    expect(JSON.parse(today.response.body)).toMatchObject({ data: [], total: 0 });

    const head = await context.dispatch('HEAD', `${APP}${CLASS_SESSION_LIST_PATH}`);
    expect(head).toMatchObject({ action: 'fulfill', response: { status: 200, body: '' } });

    expect(context.sent).toEqual([]);
    expect(blocked).toEqual([]);
    expect(reads.answered).toEqual([
      { kind: 'projection', method: 'GET', start: '2026-08-03', end: '2026-08-09', rows: 3 },
      { kind: 'list', method: 'GET', start: '2026-11-10', end: '2026-11-10', rows: 0 },
      { kind: 'list', method: 'HEAD', start: '', end: '', rows: 0 },
    ]);
  });

  it('answers a projection read with an empty projection until the spec supplies rows', async () => {
    const { context, reads } = await arm();
    const before = await context.dispatch('GET', `${APP}${CLASS_SESSION_PROJECTION_PATH}?branch_id=16&start=2026-08-03&end=2026-08-09`);
    expect(JSON.parse(before.response.body)).toMatchObject({ api_kind: 'projection', completeness: 'full', total: 0 });
    expect(() => reads.serveProjection({ branchId: 16, start: '2026-08-05', end: '2026-08-05', payload: payload() })).toThrow();
    expect(reads.answered).toEqual([{ kind: 'projection', method: 'GET', start: '2026-08-03', end: '2026-08-09', rows: 0 }]);
  });

  it('leaves writes and odd spellings to the guard, which still blocks and records them', async () => {
    const { context, blocked, reads } = await arm();
    const outcomes = [];
    for (const [method, path] of [
      ['POST', CLASS_SESSION_LIST_PATH],
      ['POST', '/api/v1/class-sessions/batch'],
      ['PATCH', '/api/v1/class-sessions/9'],
      ['GET', '/api/v1/class-sessions/'],
      ['GET', '/api/v1/class-sessions/%70rojection'],
    ]) outcomes.push((await context.dispatch(method, `${APP}${path}`)).action);
    expect(outcomes).toEqual(['abort', 'abort', 'abort', 'abort', 'abort']);
    expect(blocked.map((request) => `${request.method} ${request.path}`)).toEqual([
      `POST ${CLASS_SESSION_LIST_PATH}`,
      'POST /api/v1/class-sessions/batch',
      'PATCH /api/v1/class-sessions/9',
      'GET /api/v1/class-sessions/',
      'GET /api/v1/class-sessions/%70rojection',
    ]);
    expect(reads.answered).toEqual([]);
    expect(context.sent).toEqual([]);
  });

  it('does not touch other reads: they still reach the app', async () => {
    const { context, blocked, reads } = await arm();
    expect((await context.dispatch('GET', `${APP}/api/v1/schedules?start=2026-08-05`)).action).toBe('network');
    expect((await context.dispatch('GET', `${APP}/api/v1/class-sessions/9/recovery`)).action).toBe('network');
    expect(context.sent).toHaveLength(2);
    expect(blocked).toEqual([]);
    expect(reads.answered).toEqual([]);
  });
});
