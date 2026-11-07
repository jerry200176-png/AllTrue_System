// Mock-mode API (used only when no staging proxy is configured): login/session/branches/parent
// bills on top of the shared Playwright mocks. Fake data only.
import { installApiMocks, PARENT_DASHBOARD } from '../e2e/fixtures/ui-foundation/apiMocks.js';

export const ROLES = {
  director: { id: 9001, role: 'director', name: '示範主任' },
  teacher: { id: 9002, role: 'teacher', name: '示範老師' },
};
const CAMPUSES = [{ id: 1, name: '內湖分校', code: 'neihu' }, { id: 2, name: '東湖分校', code: 'donghu' }];
const PAGE_BY_APP_PAGE = {
  'teacher-home': 'teacher', 'course-mgmt': 'course', 'tuition-collect': 'tuition',
  calendar: 'calendar', attendance: 'attendance', students: 'students', teachers: 'teachers', learning: 'learning',
};

export const sessionFor = (key) => ({
  access_token: `preview-${key}-token`,
  token: `preview-${key}-token`,
  user: { ...ROLES[key], campuses: [1, 2], must_change_password: false },
});

const PARENT_PREVIEW = {
  ...PARENT_DASHBOARD,
  students: [{ id: 2000, name: '測試學生甲' }],
  invoices: [
    { id: 1, IssueDate: '2026-11-01', TotalAmount: 6000, Status: 'unpaid', campus_name: '內湖分校' },
    { id: 2, IssueDate: '2026-10-01', TotalAmount: 6000, Status: 'paid', campus_name: '內湖分校' },
  ],
  upcoming_sessions: [{ id: 6201, SessionDate: '2026-11-12', StartTime: '16:00', EndTime: '17:00', Status: 'scheduled', Subject: '數學' }],
  attendance_history: [{ id: 5001, date: '2026-11-05', time: '16:00–17:00', subject: '數學', teacher_name: '測試老師', Status: 'present', status_label: '出席' }],
};

const cache = new Map();
async function handlerFor(mode, page) {
  const key = `${mode}:${page}`;
  if (!cache.has(key)) {
    const hs = [];
    await installApiMocks({ route: (_g, h) => hs.push(h) }, mode, page);
    cache.set(key, hs[0]);
  }
  return cache.get(key);
}

/** @returns {Promise<{status:number, body:any}|null>} null = no mock (caller fails closed) */
export async function previewResponse(url, req) {
  const p = url.pathname.replace(/^\/api\/v1/, '');
  const auth = req.headers.get('Authorization') || '';
  const role = (/preview-(director|teacher)-token/.exec(auth) || [])[1] || 'director';
  const ok = (body) => ({ status: 200, body });

  if (p === '/auth/login') {
    const account = String((await req.clone().json().catch(() => ({}))).account || '').toLowerCase();
    return ok({ data: { session: sessionFor(account.includes('teacher') ? 'teacher' : 'director') } });
  }
  if (p === '/parent/login') return ok({ token: 'preview-parent-token', student: PARENT_PREVIEW.student, students: PARENT_PREVIEW.students, identity_groups: [] });
  if (p === '/me') return ok({ ...ROLES[role], campuses: [1, 2], must_change_password: false, capabilities: [], email: 'demo@example.invalid' });
  if (p === '/campuses' || p === '/branches') return ok(CAMPUSES);
  if (p.startsWith('/parent/dashboard')) return ok(PARENT_PREVIEW);

  // Same endpoint serves different pages: pick the page-specific shared mock from ?app_page=.
  const page = PAGE_BY_APP_PAGE[new URLSearchParams(location.search).get('app_page') || ''] || '';
  const handler = await handlerFor(page ? 'normal' : 'dashboard', page);
  let out = null;
  const route = {
    request: () => ({ url: () => url.href, method: () => req.method, headers: () => Object.fromEntries(req.headers) }),
    fulfill: ({ status = 200, body = '' }) => { out = { status, body: body ? JSON.parse(body) : {} }; },
    unmocked: () => {},
  };
  await handler(route);
  return out;
}
