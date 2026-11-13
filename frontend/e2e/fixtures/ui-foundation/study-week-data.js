// Synthetic data for the teacher's week view (issue 5269 step 2). Names are invented. Fixed "today" is Friday 2026-11-13.
export const TODAY = new Date('2026-11-13T04:00:00+08:00');
const t = (id, due_date, title, extra = {}) => ({ id, student_id: 1, plan_unit_id: null, title, kind: 'homework', due_date, minutes: null, note: null, done_at: null, ...extra });
export const DAY_LABELS = ['週一 11/09', '週二 11/10', '週三 11/11', '週四 11/12', '週五 11/13', '週六 11/14', '週日 11/15'];
export const TASKS = [
  t(1, '2026-11-09', '預習第三章', { kind: 'preview', minutes: 30, done_at: '2026-11-09 20:00:00' }),
  t(2, '2026-11-10', '習作第十二到十五題', { minutes: 45, plan_unit_id: 12, done_at: '2026-11-10 21:00:00' }),
  t(3, '2026-11-10', '背單字'),
  t(4, '2026-11-11', '複習分數的加減', { kind: 'review', minutes: 40, plan_unit_id: 12, note: '先做課本例題，再做講義前兩頁' }),
  t(5, '2026-11-12', '訂正上週小考', { kind: 'correction' }),
  t(6, '2026-11-13', '段考範圍整理成一頁筆記', { kind: 'other', minutes: 60 }),
  t(7, '2026-11-13', '作業'),
  t(8, '2026-11-14', '模擬考卷第一回', { minutes: 90 }),
];
export const PLAN = {
  id: 5, student_class_id: 7, title: '七年級上學期數學', status: 'active', plan_start: '2026-09-07', plan_end: '2027-01-09', percent_done: 33, units_behind: 1,
  next_exam: { sequence: 2, label: '第二次段考', start_date: '2026-11-26', days_left: 13 },
  units: [
    { id: 11, plan_id: 5, sort_order: 1, title: '一元一次方程式', planned_start: '2026-10-26', planned_end: '2026-11-08', completed_at: null },
    { id: 12, plan_id: 5, sort_order: 2, title: '分數的加減與乘除', planned_start: '2026-11-09', planned_end: '2026-11-22', completed_at: null },
  ],
};

/** Sign-ins of the month of TODAY (and one from last month that must not count): 3 of 4 came this month. */
export const TIMELINE_ITEMS = [
  { type: 'attendance', id: 901, date: '2026-11-02', subject: '數學', attendance: '到班' },
  { type: 'attendance', id: 902, date: '2026-11-05', subject: '數學', attendance: '遲到' },
  { type: 'attendance', id: 903, date: '2026-11-09', subject: '數學', attendance: '到班' },
  { type: 'attendance', id: 904, date: '2026-11-11', subject: '數學', attendance: '缺席' },
  { type: 'attendance', id: 905, date: '2026-10-28', subject: '數學', attendance: '缺席' },
];

/** Classmates offered by the 套用到同班 picker (long names on purpose). */
export const CLASSMATES = [{ id: 21, name: '李大華' }, { id: 22, name: '歐陽子安的雙胞胎弟弟' }, { id: 23, name: '陳小明' }, { id: 24, name: '林佳' }];

/** mode: 'full' | 'empty' | 'error' (week load fails) | 'readonly' (every write answers 403). */
export async function mockStudyWeekApi(page, mode = 'full') {
  const json = (route, body, status = 200) => route.fulfill({ status, json: body });
  let rows = mode === 'empty' ? [] : TASKS.map((x) => ({ ...x }));
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    if (url.pathname.endsWith('/learning-timeline')) return json(route, { items: mode === 'empty' ? [] : TIMELINE_ITEMS, coverage: { limit_per_source: 300, capped_sources: [] } });
    if (url.pathname.endsWith('/exam-periods')) return json(route, { data: [] });
    if (url.pathname.endsWith('/learning-plans')) return json(route, { data: [PLAN] });
    if (url.pathname.endsWith('/study-tasks') && method === 'GET') {
      if (mode === 'error') return json(route, { message: 'boom' }, 500);
      const from = url.searchParams.get('from');
      const to = url.searchParams.get('to');
      return json(route, { data: rows.filter((r) => r.due_date >= from && r.due_date <= to) });
    }
    if (url.pathname.endsWith('/study-classmates')) return json(route, { data: CLASSMATES, max_targets: 30 });
    if (url.pathname.endsWith('/apply-to-students')) return json(route, { data: { results: [], created: 2, skipped: 1, refused: 0 } });
    if (url.pathname.endsWith('/copy-week')) return json(route, { data: [], skipped: 0 });
    if (url.pathname.includes('/study-tasks')) {
      if (mode === 'readonly') return json(route, { message: 'Forbidden' }, 403);
      const body = route.request().postDataJSON?.() || {};
      const id = Number(url.pathname.split('/').pop());
      if (method === 'POST') { const row = { ...t(100 + rows.length, body.due_date, body.title), ...body }; rows.push(row); return json(route, { data: row }, 201); }
      if (method === 'DELETE') { rows = rows.filter((r) => r.id !== id); return json(route, { message: 'ok' }); }
      rows = rows.map((r) => (r.id === id ? { ...r, ...body, done_at: 'done' in body ? (body.done ? '2026-11-13 09:00:00' : null) : r.done_at } : r));
      return json(route, { data: rows.find((r) => r.id === id) });
    }
    return json(route, { data: [] });
  });
}
