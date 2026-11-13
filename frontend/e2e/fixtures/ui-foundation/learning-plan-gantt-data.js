// Synthetic data for the learning-plan Gantt browser checks. Fixed "today" is 2026-11-13 (week 10 of 18).
const unit = (id, title, s, e, extra = {}) => ({
  id, plan_id: 5, sort_order: id, title, note: null, exam_sequence: null, planned_start: s, planned_end: e, dates_manual: false, completed_at: null, ...extra,
});
const done = { completed_at: '2026-11-01 10:00:00' };

export const TODAY = new Date('2026-11-13T04:00:00+08:00');
export const COURSE = { id: 7, subject: 'Math', subject_name: '數學', teacher_name: '王老師', status: 'active' };
export const PLAN = {
  id: 5, student_class_id: 7, title: '七年級上學期數學', status: 'active', plan_start: '2026-09-07', plan_end: '2027-01-09',
  percent_done: 33, units_behind: 2,
  next_exam: { sequence: 2, label: '第二次段考', start_date: '2026-11-26', days_left: 13, academic_year: 115, semester: 1 },
  units: [
    unit(1, '整數與數線的認識與運算', '2026-09-07', '2026-09-20', done),
    unit(2, '因數與倍數的應用題型', '2026-09-21', '2026-10-04', done),
    unit(3, '分數的加減與乘除運算', '2026-10-05', '2026-10-18', done),
    unit(4, '指數律與科學記號表示法', '2026-10-19', '2026-10-30', done),
    unit(5, '一元一次方程式的解法與應用', '2026-10-26', '2026-11-08', { dates_manual: true }),
    unit(6, '二元一次聯立方程式', '2026-11-02', '2026-11-12'),
    unit(7, '直角坐標平面與函數圖形的初步認識', '2026-11-09', '2026-11-22'),
    unit(8, '比例式與連比的應用', '2026-11-23', '2026-12-06'),
    unit(9, '幾何圖形與尺規作圖入門', '2026-12-07', '2026-12-20'),
    unit(10, '三角形的基本性質與全等', '2026-12-14', '2026-12-27', { dates_manual: true }),
    unit(11, '平行四邊形與特殊四邊形', '2026-12-21', '2027-01-03'),
    unit(12, '期末總複習與統整測驗', '2027-01-04', '2027-01-09'),
  ],
};

/** mode: 'plan' | 'empty' | 'readonly' (every write answers 403). Routes are registered on a Playwright page. */
export async function mockLearningPlanApi(page, mode = 'plan') {
  const json = (route, body, status = 200) => route.fulfill({ status, json: body });
  await page.route('**/api/v1/**', (route) => {
    const url = new URL(route.request().url());
    const method = route.request().method();
    if (url.pathname.endsWith('/learning-timeline')) return json(route, { items: [], coverage: { limit_per_source: 300, capped_sources: [] } });
    if (url.pathname.endsWith('/exam-periods')) return json(route, { data: [] });
    if (url.pathname.endsWith('/student-classes')) return json(route, { data: [COURSE] });
    if (url.pathname.endsWith('/curriculum-templates')) {
      return json(route, { data: [{ id: 9, title: '七年級上學期數學（南一版）', units_count: 12 }, { id: 10, title: '七年級上學期數學（康軒版）', units_count: 14 }] });
    }
    if (url.pathname.endsWith('/learning-plans') && method === 'GET') return json(route, { data: mode === 'empty' ? [] : [PLAN] });
    if (method !== 'GET' && mode === 'readonly') return json(route, { message: 'Forbidden' }, 403);
    return json(route, { data: PLAN });
  });
}
