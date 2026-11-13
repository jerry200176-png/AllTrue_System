import { test, expect } from '@playwright/test';
import { DAY_LABELS, TODAY, mockStudyWeekApi } from './fixtures/ui-foundation/study-week-data.js';

/**
 * Layout guard for pages migrated to the ui components (in-app #541 / #542 / #543, #4989).
 * Real browser, realistic CJK data, desktop + phone, every 字級 setting. A page is guarded by adding ONE entry to PAGES.
 *
 * For every page x viewport x font size it asserts:
 *   (a) a name never renders taller than 1.6 x its line height (no per-character wrapping);
 *   (b) the page body has no horizontal overflow;
 *   (c) sibling rows start at the same x (within 1px), whether or not a row shows a checkbox;
 *   (d) optional `touch` selectors: every visible match is at least 44 x 44 and the selectors match something.
 * It runs in the required ui-foundation job (see playwright.ui-foundation.config.js), not the visual-regression job.
 */

const TEXT_SIZES = ['standard', 'medium', 'large']; // useTextSize.js TEXT_SIZES (標準, 稍大, 大)
const VIEWPORTS = [{ name: 'desktop-1536', width: 1536, height: 784 }, { name: 'phone-375', width: 375, height: 800 }];
const json = (route, body) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });

const NAMES = ['林宥辰', '陳品妤', '王子謙', '張育豪', '李欣怡', '黃柏諺', '吳佳穎', '蔡承翰', '劉宇軒', '楊雅婷', '許家瑋', '鄭淑芬', '謝宗翰', '郭怡君', '洪瑞', '曾翊', '周小明', '蕭婉如', '賴冠廷', '歐陽子安'];
const SUBJECTS = ['國中數學', '高中英文', '國小自然', '高二物理（先修班）', '國中理化與生物綜合複習衝刺班', '一對一作文'];

const COURSE_NAMES = ['林宥辰', '歐陽子晴', '范姜宜蓁', '司馬文傑', '陳品妤', '曾翊'];
const COURSE_MGMT = {
  // 課程查找, slices 1-2 (#4989): header, toolbar, 課程分布 strip and the student group cards.
  // The left-edge group mixes the header title column, the toolbar card, the strip label and the group cards on purpose:
  // they are different elements, so a gutter that differs between header and list makes it fail (a list of identical cards cannot).
  key: 'course-mgmt',
  url: '/pilot-mount.html?page=course&mode=normal',
  names: COURSE_NAMES,
  allNames: true,
  rows: ['.course-workbench__intro, .course-header-card, .course-stats-disclosure__label, .student-group-card'],
  touch: ['.student-group-toggle', '.focus-btn', '.student-group-view-tab'],
  mock: async (page) => {
    const data = COURSE_NAMES.map((n, i) => ({ id: 100 + i, student_id: 9100 + i, student_name: n, teacher_name: ['王老師', '李大華老師', '陳小明老師'][i % 3], subject: 'math', subject_name: SUBJECTS[i % 6], first_class_date: '2026-09-01', status: i === 1 ? 'inactive' : 'active', ScheduleMode: 'count', payment_type: 'session', sessions_purchased: 8, sessions_used: 3, remaining_sessions: 5, payment_status: ['unpaid', 'paid', 'partial'][i % 3], class_type: 'one_on_one' }));
    await page.route('**/api/v1/**', (r) => json(r, new URL(r.request().url()).pathname === '/api/v1/student-classes' ? { data, total: data.length, current_page: 1, last_page: 1 } : { data: [] }));
  },
};
// The opened help panel (#4989 slice 1 follow-up): it takes its own full row, starts on the title's left edge and does not push the page sideways.
const COURSE_MGMT_HELP_OPEN = {
  ...COURSE_MGMT,
  key: 'course-mgmt (help panel open)',
  allNames: false,
  rows: ['.course-workbench__intro, .course-workbench__help[open], .course-workbench__help[open] > summary > svg, .course-workbench__help[open] > p'],
  touch: ['.course-workbench__help[open] > summary'],
  setup: async (page) => { await page.locator('[data-testid="course-help"] > summary').click(); await expect(page.locator('.course-workbench__help[open]')).toBeVisible(); },
};

/** PAGES: { key, url, mock(page), names (exact texts that must stay on one line), rows (selector groups whose visible members must share a left edge), setup?(page) } */
const PAGES = [
  {
    key: 'tuition-report',
    url: '/pilot-mount.html?page=tuition-report',
    names: NAMES,
    rows: ['tbody tr td:first-child'],
    mock: async (page) => {
      const data = NAMES.map((n, i) => ({ student_class_id: i + 1, student_name: n, subject: SUBJECTS[i % 6], teacher_name: ['王老師', '李大華老師', '陳老師'][i % 3], class_type: 'one_on_one', monthly_sessions: 8, rate: 600, monthly_tuition: 4800, pay_status: ['paid', 'partial', 'unpaid', 'review'][i % 4], outstanding_amount: 1200, last_paid_at: '2026-11-03' }));
      await page.route('**/api/v1/finance/branch-monthly-tuition**', (r) => json(r, { data, summary: { total_students: 20, total_sessions: 160, total_tuition: 96000, total_due: 96000, total_collected: 50000, total_outstanding: 46000, unpaid_students: 9 }, meta: { current_page: 1, last_page: 1, per_page: 50, total: 20 } }));
    },
  },
  {
    // issue 5269 step 2: the teacher's week view (a tab of the student profile). Day labels are the "names" (never wrap);
    // the plan lines and the day grid share the card's left edge; every control a finger meets is at least 44px.
    key: 'student-study-week',
    url: '/study-week-pilot-mount.html',
    names: [...DAY_LABELS, '歐陽子安 學習檔案'],
    allNames: false,
    rows: ['[data-testid="week-plans"], [data-testid="week-grid"]', '[data-testid="week-plans"] > div'],
    touch: ['[data-testid^="add-"]', '[data-testid="week-prev"]', '[data-testid="week-next"]', '[data-testid="week-today"]', '[data-testid^="done-"] ~ *, label:has([data-testid^="done-"])', 'button[data-testid^="title-"]'],
    mock: async (page) => { await page.clock.setFixedTime(TODAY); await mockStudyWeekApi(page, 'full'); },
    setup: async (page) => {
      await page.getByRole('tab', { name: '讀書計畫' }).click();
      await expect(page.getByTestId('task-8')).toBeVisible();
      // Phone: one vertical list of seven days; desktop: seven columns side by side.
      const columns = () => page.evaluate(() => new Set([...document.querySelectorAll('[data-testid^="day-"]')].map((d) => Math.round(d.getBoundingClientRect().top))).size === 1);
      await expect.poll(columns, { message: 'seven columns on a wide dialog, one stacked list on a phone' }).toBe(page.viewportSize().width >= 1280);
      // The first task of every column starts at the same height, so the 今天 mark must not push one column down.
      if (page.viewportSize().width >= 1280) {
        const starts = await page.evaluate(() => ['task-1', 'task-2', 'task-4', 'task-5', 'task-6', 'task-8'].map((id) => Math.round(document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect().top)));
        expect(new Set(starts).size, `first tasks start at ${starts.join(', ')}`).toBe(1);
      }
    },
  },
  ...[false, true].map((search) => ({
    key: search ? 'tuition-collect (search: students without a bill)' : 'tuition-collect (bill list, mixed selectable rows)',
    url: '/pilot-mount.html?page=tuition',
    names: search ? ['林小明', '林佳', '林宥辰'] : NAMES.filter((n) => n !== '林小明'),
    rows: ['.bl__row, .bl__plainrow'],
    mock: async (page) => {
      const kinds = ['pending_report', 'unpaid', 'partial', 'paid'];
      const rows = NAMES.map((n, i) => { const k = kinds[i % 4]; return { id: 100 + i, student_id: 9100 + i, student_name: n, subject: SUBJECTS[i % 6], schedule_mode: i % 2 ? 'date' : 'count', payment_status: k, charge: 4200, paid_amount: k === 'paid' || k === 'pending_report' ? 4200 : 0, outstanding: k === 'unpaid' ? 4200 : 0, payable_amount: 4200, payable_outstanding: k === 'unpaid' ? 4200 : 0, due_date: '2026-09-12', days_until_settlement: 2 - i, course_start_date: '2026-09-01', course_end_date: '2026-09-30', invoice_id: 500 + i, payable_status: 'invoiced', remaining_sessions: i % 5, sessions_purchased: 16, latest_payment_report_id: k === 'pending_report' ? 600 + i : null }; });
      await page.route('**/api/v1/alerts/tuition**', (r) => json(r, rows));
      await page.route('**/api/v1/accounting/**', (r) => json(r, { data: [], summary: {} }));
      await page.route('**/api/v1/students**', (r) => json(r, { data: [...rows.map((x) => ({ id: x.student_id, name: x.student_name, enable: 1 })), { id: 1, name: '林小明', enable: 1 }, { id: 2, name: '林佳', enable: 1 }] }));
      await page.route('**/api/v1/branches**', (r) => json(r, [{ id: 1, name: '內湖分校' }]));
      await page.route('**/api/v1/class-sessions**', (r) => json(r, { data: [] }));
    },
    setup: async (page) => {
      await expect(page.locator('[data-testid^="bill-row-"]').first()).toBeVisible();
      if (search) {
        await page.getByTestId('bill-search').fill('林');
        await expect(page.getByTestId('bill-student-1')).toBeVisible();
      }
    },
  })),
  COURSE_MGMT,
  COURSE_MGMT_HELP_OPEN,
  {
    key: 'teacher-eligibility',
    url: '/teacher-eligibility-pilot-mount.html',
    names: ['王老師', '李大華老師', '陳小明'],
    rows: ['.desktop-table tbody tr td:first-child', '.teacher-card'],
    mock: async (page) => {
      const t = (id, name) => ({ teacher_id: id, teacher_name: name, overall_status: 'qualifies', review_required: false, settlement: { regular_subject_count: 4, tutoring_trial_subject_count: 2, payroll_subject_count: 6, one_to_three_count: 3, subject_count_bonus: 1200, one_to_three_bonus: 800, multiplier_pct: 105, weighted_bonus_amount: 2100, total_payout: 36000, payout_is_draft: false, adjustments: [{ label: '行政加給', amount: 500 }], multiplier_parts: [{ key: 'base', label: '基本', pct: 100 }] }, components: { weekly_16_segments: { status: 'qualifies', metrics: { regular_segments: 18, trial_segments: 2, total_segments: 20, meets_16_segments: true, course_sessions: [] } }, subject_count_bonus: { status: 'qualifies', amount: 1200 } } });
      await page.route('**/api/v1/finance/teacher-eligibility**', (r) => (r.request().url().includes('/inputs')
        ? json(r, { events: [], achievements: [], deductions: [], admin_allowances: [], cash_adjustments: [] })
        : json(r, { teachers: [t(701, '王老師'), t(702, '李大華老師'), t(703, '陳小明')], period: { start: '2026-09-01', end: '2026-09-30' }, policy_version: '115.07', branch_subject_total: 12, lock: { status: 'draft' } })));
    },
  },
  {
    key: 'binding-conflicts',
    url: '/pilot-mount.html?page=binding-conflicts&role=super_admin',
    names: NAMES.slice(0, 8),
    rows: ['.bcrp-table tbody tr', '.bcrp-mobile-card'],
    mock: async (page) => {
      const side = (id, c, m, d) => ({ id, campus_id: c, campus_name: c === 1 ? '內湖分校' : '大安分校', line_user_id_masked: m, bound_at: `2026-10-0${d}T12:00:00+08:00` });
      const data = { conflicts: NAMES.slice(0, 8).map((n, i) => ({ id: i + 7, student_id: i + 7, student_name: n, campus_count: 2, bindings: [side(i * 2 + 11, 1, 'U1111111…aaaa', 1), side(i * 2 + 12, 2, 'U2222222…bbbb', 2)] })) };
      await page.route('**/api/v1/**', (r) => json(r, new URL(r.request().url()).pathname === '/api/v1/bindings/conflicts' ? data : { data: [] }));
    },
  },
  {
    key: 'binding-health',
    url: '/pilot-mount.html?page=binding-health&role=director',
    names: NAMES.slice(0, 12),
    rows: [],
    mock: async (page) => {
      const data = { overall: { bound_count: 82, student_total: 100 }, weekly_summary: { bound: 7, unbound: 2, net: 5 }, by_campus: [{ campus_id: 1, campus_name: '內湖分校', rate: 0.86, bound_count: 43, student_total: 50 }, { campus_id: 2, campus_name: '大安分校', rate: 0.78, bound_count: 39, student_total: 50 }], trend: { day: [60, 64, 63, 70, 75, 74, 82].map((b, i) => ({ date: `2026-09-0${i + 1}`, bound: b })), week: [{ week: '2026-W35', bound: 70 }, { week: '2026-W36', bound: 82 }] }, unbound_active: NAMES.slice(0, 12).map((n, i) => ({ student_id: i, student_name: n, campus_id: 1, campus_name: '內湖分校' })), anomalies: [{ id: 101, type: '重複綁定', severity: 'warning', campus_id: 2, campus_name: '大安分校', student_name: NAMES[0], occurred_at: '' }] };
      await page.route('**/api/v1/**', (r) => json(r, new URL(r.request().url()).pathname === '/api/v1/bindings/metrics' ? data : { data: [] }));
    },
  },
];

/** Runs in the browser: wrapped names, left edges per row group, page overflow. */
function measure({ names, rows, touch = [] }) {
  const visible = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
  const wanted = new Set(names);
  const found = new Set();
  const wrapped = [];
  for (const el of document.querySelectorAll('body *')) {
    if (el.children.length) continue;
    const text = el.textContent.trim();
    if (!wanted.has(text) || !visible(el)) continue;
    found.add(text);
    const range = document.createRange();
    range.selectNodeContents(el);
    const box = range.getBoundingClientRect();
    const cs = getComputedStyle(el);
    const lineHeight = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.4;
    if (box.height > lineHeight * 1.6) wrapped.push({ text, height: Math.round(box.height), lineHeight: Math.round(lineHeight) });
  }
  const rowEdges = rows.map((selector) => ({ selector, lefts: [...document.querySelectorAll(selector)].filter(visible).map((el) => Math.round(el.getBoundingClientRect().left * 10) / 10) }));
  const small = touch.flatMap((selector) => [...document.querySelectorAll(selector)].filter(visible).map((el) => { const r = el.getBoundingClientRect(); return { selector, w: Math.round(r.width), h: Math.round(r.height) }; }).filter((t) => t.h < 44 || t.w < 44));
  const touchCount = touch.reduce((n, selector) => n + [...document.querySelectorAll(selector)].filter(visible).length, 0);
  return { found: [...found], wrapped, rowEdges, small, touchCount, scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth };
}

for (const entry of PAGES) {
  for (const size of TEXT_SIZES) {
    for (const vp of VIEWPORTS) {
      test(`layout guard: ${entry.key} @${vp.name} text-size ${size}`, async ({ page }) => {
        await page.setViewportSize({ width: vp.width, height: vp.height });
        await page.addInitScript((s) => {
          try { localStorage.setItem('alltrue_text_size', s); } catch { /* storage blocked */ }
          document.documentElement.setAttribute('data-text-size', s);
        }, size);
        await entry.mock(page);
        await page.goto(entry.url);
        await expect(page.locator('html')).toHaveAttribute('data-pilot-ready', '1', { timeout: 15_000 });
        await expect(page.locator('html')).toHaveAttribute('data-text-size', size);
        if (entry.setup) await entry.setup(page);
        await page.getByText(entry.names[0], { exact: true }).first().waitFor({ state: 'attached' });
        await page.waitForLoadState('networkidle');
        const m = await page.evaluate(measure, { names: entry.names, rows: entry.rows, touch: entry.touch || [] });

        expect(m.found.length, 'the fixture names must be on screen, otherwise this guard checks nothing').toBeGreaterThan(0);
        if (entry.allNames) expect([...m.found].sort(), 'every fixture name must be on screen').toEqual([...entry.names].sort());
        expect(m.wrapped, '(a) a name wraps inside its cell').toEqual([]);
        if (entry.touch) {
          expect(m.touchCount, 'the touch-target selectors must match something, otherwise (d) checks nothing').toBeGreaterThan(0);
          expect(m.small, '(d) a touch target is smaller than 44px').toEqual([]);
        }
        expect(m.scrollWidth, '(b) the page body scrolls sideways').toBeLessThanOrEqual(m.innerWidth + 1);
        for (const group of m.rowEdges) {
          if (group.lefts.length < 2) continue;
          expect(Math.max(...group.lefts) - Math.min(...group.lefts), `(c) rows of ${group.selector} start at different x: ${group.lefts.join(', ')}`).toBeLessThanOrEqual(1);
        }
      });
    }
  }
}
