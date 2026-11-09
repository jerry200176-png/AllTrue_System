import assert from 'node:assert/strict';
import { test } from 'node:test';
import { BILL_EXPORT_PATH, matchesVisibleBillExport, installSingleStudentExportGuard } from './directorExportGuard.js';

const origin = 'https://app.example.test';
const body = { branch_id: 1, filter: 'all', search: '合成學生', rows: [{ id: 7, package_id: null }] };
const matches = (method = 'POST', url = origin + BILL_EXPORT_PATH, data = body, keys = ['c7']) =>
  matchesVisibleBillExport(origin, method, url, data, keys, body.search);

test('the export exception matches one exact origin/path/method and visible identifier set', () => {
  assert.equal(matches(), true);
  for (const method of ['GET', 'PUT', 'PATCH', 'DELETE']) assert.equal(matches(method), false);
  for (const url of [origin + BILL_EXPORT_PATH + '/', origin + BILL_EXPORT_PATH + '?x=1',
    'https://other.example.test' + BILL_EXPORT_PATH, origin + '/api/v1/learning-records/7/rollback-approval']) {
    assert.equal(matches('POST', url), false);
  }
  for (const data of [{ ...body, branch_id: 0 }, { ...body, _method: 'DELETE' }, { ...body, search: '' },
    { ...body, rows: [] }, { ...body, rows: [{ id: 8 }] }, { ...body, rows: [{ id: 7, amount: 1 }] }]) {
    assert.equal(matches('POST', undefined, data), false);
  }
  const keys = Array.from({ length: 2001 }, (_, index) => 'c' + (index + 1));
  assert.equal(matches('POST', undefined, { ...body, rows: keys.map(key => ({ id: Number(key.slice(1)) })) }, keys), false);
});

test('only one armed UI export passes; rollback and all other writes stay blocked', async () => {
  const handlers = [];
  const context = { route: async (match, handle) => handlers.push({ match, handle }) };
  const guard = await installSingleStudentExportGuard(context, origin, ['c7'], body.search);
  async function send(method, path, data = body) {
    const url = new URL(path, origin);
    let decision = '';
    const request = { method: () => method, url: () => url.href, postDataJSON: () => data, postDataBuffer: () => Buffer.from('{}') };
    async function dispatch(index) {
      if (index < 0) throw new Error('unguarded request');
      if (!handlers[index].match(url)) return dispatch(index - 1);
      await handlers[index].handle({ request: () => request, continue: async () => { decision = 'sent'; },
        fetch: async options => {
          assert.deepEqual(options, { maxRedirects: 0 });
          return { status: () => 200, url: () => url.href, headers: () => ({
            'content-type': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'content-disposition': 'attachment; filename="synthetic.xlsx"',
          }), body: async () => Buffer.from([0x50, 0x4b, 0x03, 0x04]), dispose: async () => {} };
        },
        abort: async () => { decision = 'blocked'; }, fulfill: async options => { decision = options.response ? 'sent' : 'local'; },
        fallback: async () => dispatch(index - 1) });
    }
    await dispatch(handlers.length - 1);
    return decision;
  }
  assert.equal(await send('POST', BILL_EXPORT_PATH), 'sent');
  assert.equal(guard.exportCount(), 1);
  assert.equal(await send('POST', BILL_EXPORT_PATH), 'blocked');
  assert.equal(await send('POST', '/api/v1/learning-records/7/rollback-approval'), 'blocked');
  assert.equal(await send('DELETE', '/api/v1/student-classes/7'), 'blocked');
  assert.equal(await send('POST', 'https://other.example.test' + BILL_EXPORT_PATH), 'blocked');
  assert.equal(await send('GET', '/api/v1/class-sessions?branch_id=1'), 'blocked');
  assert.equal(await send('HEAD', '/api/v1/class-sessions/projection/'), 'blocked');
  assert.equal(await send('POST', '/api/v1/adoption/events'), 'local');
  assert.equal(guard.blocked.length, 6);
});
