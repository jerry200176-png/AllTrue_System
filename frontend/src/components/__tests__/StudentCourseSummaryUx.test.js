import { courseProgress } from '../../lib/courseMoneyState.js';
import { afterEach, describe, it, expect, vi } from 'vitest';
import { supabase } from '../../supabase.js';
import { authedFetch, getAccessToken } from '../../lib/authedFetch.js';
import { humanizeApiErrorMessage } from '../../lib/humanizeApiErrorMessage.js';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(__dirname, '../../pages/StudentsList.vue'), 'utf8');
const activeCourseSlice = source.slice(source.indexOf('<!-- Phase 2A:'), source.indexOf('<div v-else-if="getHistoryStudentCourses'));

// Execute the exact handler (not a duplicated implementation) with the actual
// authenticated/compatibility wrappers. Browser tests below cover rendered UI.
describe('529 deletion control flow', () => {
  afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks(); localStorage.clear(); });
  async function runDelete({ status = 422, legacy = false, transport = false,
    refreshError = false, accepted = true, token = 'synthetic-only', malformed = false } = {}) {
    localStorage.setItem('alltrue_session', JSON.stringify({ access_token: token }));
    vi.spyOn(supabase.auth, 'getSession').mockResolvedValue({ data: { session: { access_token: token } } });
    const fetchMock = vi.fn(async () => {
      if (transport) throw new TypeError('Synthetic transport interruption');
      return new Response(malformed ? '<html>synthetic failure</html>' : JSON.stringify(status === 200 ? { message: 'Course deleted successfully' } : {
        message: status >= 500 ? 'SQLSTATE synthetic internals' : '這門課已有上課紀錄，請使用結案。', code: 'course_has_history',
      }), { status, headers: { 'Content-Type': malformed ? 'text/html' : 'application/json' } });
    });
    vi.stubGlobal('fetch', fetchMock);
    const show = vi.fn();
    const reload = vi.fn().mockResolvedValue(undefined);
    const loadStudent = vi.fn(async () => { if (refreshError) throw new Error('Synthetic refresh failure'); });
    const handlerText = source.slice(source.indexOf('const deleteCourse = async'), source.indexOf('\n\n// --- Add Sessions'))
      .replace(/^const deleteCourse = /, '').replace(/;\s*$/, '');
    const parserText = source.slice(source.indexOf('const parseApiErrorMessage = '), source.indexOf('\n\nconst courseScheduleFingerprint'))
      .replace(/^const parseApiErrorMessage = /, '').replace(/;\s*$/, '');
    const deps = { confirm: () => accepted, alert: vi.fn(), isLaravelCourse: () => !legacy,
      getAccessToken, authedFetch, supabase, humanizeApiErrorMessage,
      selectedStudent: { value: { id: 9002 } }, studentCourses: { value: {} },
      loadStudentCourses: loadStudent, loadAllStudentCourses: reload,
      toastRef: { value: { show } }, parseApiErrorMessage: new Function('return ' + parserText)() };
    await new Function(...Object.keys(deps), 'return ' + handlerText)(...Object.values(deps))({ id: 990001 });
    return { fetchMock, show, reload, loadStudent };
  }

  for (const status of [403, 404, 409, 422, 500]) {
    it(`stops after the authoritative ${status} and presents safe feedback`, async () => {
      const { fetchMock, show, reload } = await runDelete({ status });
      expect(fetchMock).toHaveBeenCalledTimes(1);
      expect(show).toHaveBeenCalledWith(expect.objectContaining({ variant: 'error' }));
      expect(JSON.stringify(show.mock.calls)).not.toContain('SQLSTATE');
      expect(reload).not.toHaveBeenCalled();
    });
  }
  it('handles malformed refusal bodies without retry or raw HTML', async () => {
    const { fetchMock, show } = await runDelete({ malformed: true });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(show).toHaveBeenCalledWith(expect.objectContaining({ variant: 'error' }));
    expect(JSON.stringify(show.mock.calls)).not.toContain('<html>');
  });
  it('shows the actual legacy wrapper error after one DELETE', async () => {
    const { fetchMock, show, reload } = await runDelete({ legacy: true });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(show).toHaveBeenCalledWith(expect.objectContaining({ description: expect.stringContaining('請使用結案') }));
    expect(reload).not.toHaveBeenCalled();
  });
  it('keeps an ambiguous transport outcome from causing another DELETE', async () => {
    const { fetchMock, show, reload } = await runDelete({ transport: true });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(show).toHaveBeenCalledWith(expect.objectContaining({ title: expect.stringContaining('尚未確認') }));
    expect(reload).not.toHaveBeenCalled();
  });
  it('does not replay a successful DELETE when its refresh throws', async () => {
    const { fetchMock, show } = await runDelete({ status: 200, refreshError: true });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(show).toHaveBeenCalledWith(expect.objectContaining({ title: '課程已刪除', variant: 'warning' }));
  });
  it('refreshes after one successful DELETE', async () => {
    const { fetchMock, reload, loadStudent, show } = await runDelete({ status: 200 });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(reload).toHaveBeenCalledTimes(1);
    expect(loadStudent).toHaveBeenCalledTimes(1);
    expect(show).toHaveBeenCalledWith(expect.objectContaining({ title: '課程已刪除', variant: 'success' }));
  });
  for (const options of [{ accepted: false }, { token: null }]) {
    it(`makes no DELETE for ${options.accepted === false ? 'cancellation' : 'missing token'}`, async () => {
      const { fetchMock, reload } = await runDelete(options);
      expect(fetchMock).not.toHaveBeenCalled();
      expect(reload).not.toHaveBeenCalled();
    });
  }
});

describe('StudentsList course summary UX', () => {
  it('keeps the active course view task-first and progressively disclosed', () => {
    ['student-course-detail', '目前課程工作區', 'student-course-cards', 'student-course-card', '更多操作', '<details'].forEach((marker) => expect(activeCourseSlice).toContain(marker));
    expect(activeCourseSlice).not.toContain('course-inner-table');
  });

  it('keeps history as an explicit accessible disclosure below active work', () => {
    ['sl-history-toggle', ':aria-expanded="expandedHistoryCourses.has(student.id)"', ':aria-controls="`student-history-${student.id}`"', ':id="`student-history-${student.id}`"']
      .forEach((marker) => expect(source).toContain(marker));
  });

  it('puts course count, attention count, and selectable next action before detail', () => {
    ['student-course-overview', '課程總覽', '需要處理', 'student-course-picker', 'aria-pressed', 'selectStudentCourse(student.id, course.id, $event)']
      .forEach((marker) => expect(activeCourseSlice).toContain(marker));
    expect(source).toContain('const getFocusedStudentCourse = (studentId) =>');
    expect(source).toContain('const getStudentCourseAttentionCount = (studentId) =>');
  });

  it('uses existing data states for attention labels without inventing a score', () => {
    ['需要續報', '付款待確認', '資料待確認', '進行中', 'getCourseProgressSummary(course)']
      .forEach((marker) => expect(source).toContain(marker));
    expect(source).not.toContain('XP');
    expect(source).not.toContain('連續天數');
  });

  it('makes the primary next step explicit for each existing course state', () => {
    ['student-course-card__next-step', '現在先處理', 'getCoursePrimaryAction(course)', 'openCoursePrimaryAction(course, student.name)']
      .forEach((marker) => expect(activeCourseSlice).toContain(marker));
    ['查看繳費資訊', '補齊課程資料'].forEach((marker) => expect(source).toContain(marker));
    expect(source).toContain("if (action.key === 'renew') return openAddSessionsForCourse(course);");
    expect(source).toContain("if (action.key === 'payment') return goToTuitionBilling(course);");
  });

  it('shows honest session progress and a separate monthly cadence state', () => {
    ['role="progressbar"', ':aria-valuemax="courseProgress(course).total"', '堂數未設定，請編輯課程確認。', '月結'].forEach((marker) => expect(activeCourseSlice).toContain(marker));
    expect(source).toMatch(/import \{[^}]*\bcourseProgress\b[^}]*\} from '..\/lib\/courseMoneyState.js'/);
    expect(courseProgress({ payment_type: 'monthly', sessions_purchased: 8, remaining_sessions: 3 })).toBeNull();
    expect(courseProgress({ payment_type: 'session', sessions_purchased: 8, remaining_sessions: 3 })).toMatchObject({ total: 8, used: 5 });
  });

  it('preserves the existing course actions behind the disclosure', () => {
    ['goToTuitionBilling(course)', 'openAddSessionsForCourse(course)', 'openTuitionLedger(course)', 'editCourse(course)', 'closeCourseNoRenew(course, student.name)', 'deleteCourse(course)']
      .forEach((handler) => expect(activeCourseSlice).toContain(handler));
    expect(activeCourseSlice).not.toContain('togglePaymentStatus');
    expect(activeCourseSlice).not.toContain('openLatestPaymentInfo');
  });
});
