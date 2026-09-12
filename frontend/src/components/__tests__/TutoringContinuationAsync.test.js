// Exercise the actual page handlers with deferred auth, without production APIs.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { describe, expect, it, vi } from 'vitest';

const source = readFileSync(`${process.cwd()}/src/pages/StudentsList.vue`, 'utf8');
const open = source.slice(source.indexOf('const openAddSessionsForCourse ='), source.indexOf('const submitAddSessions ='));
const submit = source.slice(source.indexOf('const submitAddSessions ='), source.indexOf('const formatBillingPeriod ='));
function setup() {
  let resolveAuth;
  const auth = new Promise(resolve => { resolveAuth = resolve; });
  const context = {
    addSessionsSubmitting: { value: false }, selectedCourse: { value: { id: 7, class_type: 'tutoring' } },
    selectedStudent: { value: { id: 8 } }, props: { branchId: 16 },
    addSessionCount: { value: 4 }, addSessionStartDate: { value: '2026-10-01' }, tutoringEndDate: { value: '' },
    isTutoringCourse: c => c?.class_type === 'tutoring', isPackageMember: c => Boolean(c?.PackageID),
    supabase: { auth: { getSession: () => auth } },
    fetch: vi.fn(async () => ({ ok: false, json: async () => ({ message: 'conflict' }) })),
    alert: vi.fn(),
  };
  vm.createContext(context);
  vm.runInContext(`${open}\n${submit}\nglobalThis.handlers = { openAddSessionsForCourse, submitAddSessions };`, context);
  return { context, resolveAuth: () => resolveAuth({ data: { session: { access_token: 'fixture-only' } } }) };
}

describe('tutoring continuation async identity', () => {
  it('freezes payload before auth and prevents opening paid B while A submits', async () => {
    const { context: c, resolveAuth } = setup();
    const pending = c.handlers.submitAddSessions();
    expect(c.addSessionsSubmitting.value).toBe(true);
    c.handlers.openAddSessionsForCourse({ id: 99, class_type: 'one_on_one' });
    expect(c.selectedCourse.value.id).toBe(7);
    c.addSessionCount.value = 30;
    c.addSessionStartDate.value = '2026-11-01';
    resolveAuth();
    await pending;
    expect(c.fetch).toHaveBeenCalledTimes(1);
    const [url, options] = c.fetch.mock.calls[0];
    expect(url).toBe('/api/v1/student-classes/7/continue-tutoring');
    expect(JSON.parse(options.body)).toEqual({ sessions: 4, start_date: '2026-10-01' });
    expect(c.addSessionsSubmitting.value).toBe(false);
  });

  it.each(['course', 'campus'])('does not submit after a %s context change during auth', async kind => {
    const { context: c, resolveAuth } = setup();
    const pending = c.handlers.submitAddSessions();
    if (kind === 'course') c.selectedCourse.value = { id: 99, class_type: 'one_on_one' };
    else c.props.branchId = 9;
    resolveAuth();
    await pending;
    expect(c.fetch).not.toHaveBeenCalled();
    expect(c.addSessionsSubmitting.value).toBe(false);
  });
});
