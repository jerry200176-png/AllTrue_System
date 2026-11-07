// Shared API mock data for UI-foundation specs AND the PR preview build (single source).
// Handler is written against Playwright's route API; the preview shims that API.

export const PARENT_DASHBOARD = {
  student: { id: 2000, name: '測試學生甲', grade: 'J1', school: '測試國中', campus_id: 1, campus_name: '測試分校', line_linked: false },
  enrollments: [],
  cross_campus_access: 'actions',
  progress_summary: {
    week_label: '8/24–8/30',
    week_progress: { attended: 1, scheduled: 2 },
    next_session: { date: '2026-09-01', start_time: '10:00', end_time: '11:00', subject: '數學', is_today: false },
    payment: { paid_courses: 1, total_courses: 2, status: 'partial' },
    pending_total: 1,
  },
  announcements: [{ id: 9001, Title: '測試公告', Content: '這是給家長看的公告。', campus_name: '測試分校', created_at: '2026-08-28T09:00:00+08:00' }],
  learning_records: [],
  learning_records_meta: { total: 0, has_more: false },
  attendance_history: [],
  upcoming_sessions: [],
  classes: [{ id: 9401, subject: '數學', sessions_purchased: 8, used_sessions: 6, remaining_sessions: 2, paid: false, payment_status_label: '待繳費', status: 'active' }],
  remaining_sessions_total: 2,
  remaining_by_subject: { 數學: 2 },
  payment_alerts: [{ class_id: 9401, subject: '數學', remaining_sessions: 2, paid: false }],
  invoices: [],
  assessment_progress: { items: [] },
};

const CASE_NORMAL = {
  id: 'case-1001',
  lane: 'case',
  title: '請假申請',
  student_name: '測試學生甲',
  summary: '數學 · 請假後待安排補課',
  reason_preview: '身體不適請假一天',
  status_label: '待確認補課',
  status_code: 'pending_arrange',
  priority: 'due_soon',
  overdue: false,
  occurred_at: '2026-07-20T10:00:00+08:00',
  due_at: '2026-07-22T18:00:00+08:00',
  action: { label: '安排補課' },
};

const CASE_LONG = {
  ...CASE_NORMAL,
  id: 'case-1002',
  student_name: '測試學生超長姓名用於驗證折行與資訊層級是否清楚',
  summary: '英文進階班 · 家長來電說明臨時身體不適，希望改到下週同一時段，並請老師協助補齊當週進度與作業說明，文字需要足夠長以驗證版面。',
  reason_preview: '這段事由非常非常長，用來驗證長內容在真實 Vue 頁面上的折行、間距與 primary action 是否仍然清楚，不應水平爆版。'.repeat(2),
  overdue: true,
  priority: 'overdue',
};

function fakeStudents(count, { longName = false } = {}) {
  return Array.from({ length: count }, (_, i) => ({
    id: 2000 + i,
    name: longName && i === 0
      ? '測試學生長名字驗證用名稱避免真實個資'
      : `測試學生${String(i + 1).padStart(2, '0')}`,
    grade: 'J1',
    school: longName && i === 0 ? '測試國民中學附設高級中等學校名稱很長' : '測試國中',
    parent_name: longName && i === 0 ? '測試家長長名稱' : '測試家長',
    rfid: i % 3 === 0 ? null : `TEST${1000 + i}`,
    status: 'active',
    notes: '',
    line_bound: false,
  }));
}

export async function installApiMocks(page, mode, pageName = '', authProfile = null, onAuthRequest = null) {
  const hang = mode === 'loading';
  let manualBookingCheckCount = 0;
  let releaseHang;
  const hangPromise = hang
    ? new Promise((resolve) => { releaseHang = resolve; })
    : Promise.resolve();

  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const p = url.pathname;
    const method = route.request().method();
    const requestedPage = Number(url.searchParams.get('page') || 1);

    if (authProfile && p.endsWith('/me')) {
      const headers = route.request().headers();
      const actingAs = headers['x-acting-as'] === 'teacher' ? 'teacher' : 'director';
      onAuthRequest?.({ actingAs, userId: authProfile.userId });
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          id: authProfile.userId,
          name: 'E2E Dual Staff',
          role: actingAs,
          acting_as: actingAs,
          campuses: actingAs === 'teacher' ? [1] : [1, 2],
          capability_campuses: { director: [1, 2], teacher: [1] },
          capabilities: authProfile.capabilities,
          must_change_password: false,
        }),
      });
    }

    if (pageName === 'course' && mode === 'booking-race' && method === 'POST' && p.includes('/manual-sessions/check')) {
      manualBookingCheckCount += 1;
      if (manualBookingCheckCount === 1) {
        // Reproduce the stale response that used to overwrite the director's
        // newer date selection with an old 422 error.
        await new Promise((resolve) => setTimeout(resolve, 180));
        return route.fulfill({
          status: 422,
          contentType: 'application/json',
          body: JSON.stringify({ can_add: false, message: '舊日期檢查失敗', conflict_type: 'past_session' }),
        });
      }
      await new Promise((resolve) => setTimeout(resolve, 10));
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ can_add: true, message: '可以預約', duration_minutes: 120, available_sessions: 1, conflict_type: 'none' }),
      });
    }

    if (pageName === 'teacher' || pageName === 'attendance') {
      const json = (body) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
      if (pageName === 'attendance') {
        if (/\/(attendance|attendance\/ended-sessions|pending-swipes|teacher-attendance|teacher-attendance\/unclosed)$/.test(p)) return json({ data: [], total: 0 });
      }
      if (p.endsWith('/teacher-attendance/today')) return json({ status: 'normal', sign_in_dt: '2026-01-01 15:02:00', sign_out_dt: null });
      if (p.endsWith('/me/awaiting-reply-count')) return json({ awaiting_reply_count: 1 });
      if (p.endsWith('/me/exam-pending-summary')) return json({ data: { rounds: [] } });
      if (p.includes('/class-sessions')) {
        const d = new Date();
        const today = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        const data = [
          { id: 901, student_class_id: 801, student_id: 2000, student_name: '測試學生甲', teacher_name: '測試老師', session_date: today, start_time: '16:00', end_time: '17:00', status: 'scheduled', subject: '數學', branch_id: 1 },
          { id: 902, student_class_id: 802, student_id: 2001, student_name: '測試學生乙', teacher_name: '測試老師', session_date: today, start_time: '18:00', end_time: '19:00', status: 'scheduled', subject: '英文', branch_id: 1 },
        ];
        return json(p.endsWith('/projection') ? { api_kind: 'projection', completeness: 'full', data, by_class: {} } : { data, total: data.length });
      }
    }

    if (p.includes('/parent/dashboard')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(PARENT_DASHBOARD) });
    }
    if (p.includes('/parent/notification-preferences')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ learning_feedback_push: false }) });
    }

    // Preview shim sets route.unmocked to fail closed; Playwright keeps the lenient default.
    if (method !== 'GET') {
      if (route.unmocked) return route.unmocked();
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
    }

    if (hang && (p.includes('/action-inbox') || p.includes('/students') || (pageName === 'course' && p.includes('/student-classes')) || pageName === 'calendar' || pageName === 'discrepancy')) {
      await hangPromise;
    }

    if (pageName === 'tuition' && p.includes('/student-classes')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ last_page: 1, data: [{
        ID: 5105, StudentID: 2000, student_name: '測試月結學生', subject_name: '數學', teacher_name: '測試老師',
        monthly_payment: { review_required: true, contract_start: '2026-07-27', contract_end: '2026-09-10', registered_paid_amount: 7500,
          session_review: [
            { calendar_month: '2026-08', completed_sessions: 4, estimated_charge: 6000, uncovered_sessions: 4, outside_contract_sessions: 0 },
            { calendar_month: '2026-09', completed_sessions: 4, estimated_charge: 6000, uncovered_sessions: 4, outside_contract_sessions: 2 },
          ] },
      }] }) });
    }

    if (pageName === 'calendar' && p.includes('/student-classes')) {
      if (mode === 'error') return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '課表資料暫時無法載入' }) });
      if (mode === 'empty') {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], total: 0 }) });
      }
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ id: 5101, student_id: 2000, student_name: mode === 'long' ? '測試學生超長姓名驗證行事曆卡片折行' : '測試學生甲', subject: 'Math', class_type: 'one_on_one', teacher_id: 3000, teacher_name: '測試老師', day_of_week: 1, start_time: '10:00', end_time: '12:00', duration_hours: 2, status: 'active', stop: 0 }], total: 1 }),
      });
    }

    if (pageName === 'calendar' && p.includes('/teachers')) {
      if (mode === 'empty') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 3000, username: '測試老師', name: '測試老師', branch_id: 1, branch_ids: [1] }] }) });
    }

    if (pageName === 'calendar' && p.includes('/schedules')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    }

    if (pageName === 'discrepancy' && p.includes('/schedule-discrepancies/summary')) {
      const total = mode === 'empty' ? 0 : 1;
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ pending: total, acknowledged: 0, resolved: 0, withdrawn: 0 }) });
    }

    if (pageName === 'discrepancy' && p.includes('/schedule-discrepancies')) {
      if (mode === 'error') return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '課表回報暫時無法載入' }) });
      if (mode === 'empty') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
      const studentName = mode === 'long' ? '測試學生超長姓名驗證回報卡片折行' : '測試學生甲';
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ id: 6101, reporter_name: '測試老師', branch_name: '測試分校', student_name: studentName, session_date: '2026-08-03', time_range: '10:00–12:00', corrected_time_range: '11:00–13:00', discrepancy_type_label: '時段不符', status: 'pending', notes: mode === 'long' ? '這是一段很長的老師回報備註，用來確認卡片會自然折行，不會把處理動作推到畫面外。'.repeat(2) : '請確認調課後的時段。' }] }),
      });
    }

    if (mode === 'dashboard' && p.endsWith('/alerts/tuition')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([{ id: 801, class_id: 801, student_id: 2001, student_name: '測試學生甲', subject: '數學', remaining_sessions: 1, alert_type: 'unpaid' }]),
      });
    }

    if (pageName === 'tuition' && p.includes('/alerts/tuition')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([{ id: 701, student_name: '測試學生甲', subject: '數學', payment_status: 'unpaid', charge: 12000, paid_amount: 0, outstanding: 12000, schedule_mode: 'count', remaining_sessions: 4, course_start_date: '2026-08-01', course_end_date: '2026-10-31', days_until_settlement: 2 }]),
      });
    }

    if (pageName === 'tuition' && p.includes('/accounting/settled-courses')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ student_class_id: 701, course_ref: '測試課程 #701', student_name: '測試學生甲', subject: '數學', schedule_mode: 'count', paid_amount: 12000, last_paid_at: '2026-08-20', legacy_paid_without_invoice: false, has_exception: false, overpaid_total: 0 }], summary: { course_count: 1, paid_total: 12000, legacy_count: 0, exception_count: 0, overpaid_total: 0 } }),
      });
    }

    if (pageName === 'tuition' && p.includes('/accounting/payments')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [], summary: { total_count: 0, total_amount: 0 } }),
      });
    }

    if (pageName === 'teachers' && p.includes('/teachers')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [
          { id: 7101, username: '測試老師甲', account: 'teacher-a', phone: '0912-345-678', branch_id: 1, branch_ids: [1, 2], status: 'active', employment_type: 'full_time', subject_ids: [1], subject_names: ['數學'], subject_level_scopes: [{ subject_id: 1, level: 'junior' }], rfid_by_branch: { '1': 'TEACHER-A-001', '2': 'TEACHER-A-002' } },
          { id: 7102, username: '測試老師乙', account: 'teacher-b', phone: '', branch_id: 1, branch_ids: [1], status: 'pending', employment_type: 'part_time', subject_ids: [], subject_names: [], subject_level_scopes: [], rfid: null },
          { id: 7103, username: '測試老師丙', account: 'teacher-c', phone: '', branch_id: 1, branch_ids: [1], status: 'suspended', employment_type: 'full_time', subject_ids: [], subject_names: [], subject_level_scopes: [], rfid: null },
        ] }),
      });
    }

    if (pageName === 'teachers' && p.includes('/subjects')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([{ id: 1, name: '數學' }]),
      });
    }

    if (pageName === 'teachers' && p.includes('/branches')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([{ id: 1, name: '大安分校' }, { id: 2, name: '石牌分校' }]),
      });
    }

    if (mode === 'dashboard' && p.includes('/director/operations-trust')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { decision_center: {
          score: 82,
          max: 100,
          status: 'yellow',
          headline: '有 1 項課務風險需要確認',
          decisions: [{ key: 'demo-risk', severity: 'warning', why: '測試課表需要主任確認。', next_step: '查看課表', action_label: '查看風險', target: 'course-mgmt', people_total: 0 }],
          policy_notes: [],
        }, generated_at: '2026-08-01T04:00:00Z' } }),
      });
    }

    if (mode === 'dashboard' && p.includes('/class-sessions')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ id: 901, student_class_id: 801, student_name: '測試學生甲', teacher_name: '測試老師', start_time: '10:00', end_time: '11:00', status: 'scheduled', subject: '數學' }] }),
      });
    }

    if (mode === 'dashboard' && p.includes('/learning-records')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ id: 1001, status: 'pending', student_name: '測試學生甲' }] }),
      });
    }

    if ((mode === 'dashboard' || pageName === 'course') && p.includes('/exception-workflows')) {
      if (pageName === 'course' && mode === 'error') {
        return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '家長請假待辦載入失敗' }) });
      }
      if (pageName === 'course' && mode === 'empty') {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { count: 0 } }) });
      }
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ id: 27, status: 'open', student: { name: mode === 'long' ? '測試學生超長姓名用於驗證處理案件摘要折行' : '測試學生甲' }, class_session: { date: '2026-08-02', start_time: '10:00', end_time: '11:00' }, payload: { reason: mode === 'long' ? '這是一段很長的家長請假原因，用來驗證案件資訊可以折行且主要處理按鈕仍然可見。' : '身體不適' }, due_at: '2026-08-01T18:00:00+08:00' }] }),
      });
    }

    if (pageName === 'course' && p.includes('/student-classes')) {
      if (mode === 'empty') {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], total: 0 }) });
      }
      if (mode === 'booking-race') {
        return route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: [{
              id: 5201,
              student_id: 2000,
              student_name: '測試學生甲',
              subject: '英文',
              teacher_id: 3000,
              teacher_name: '測試老師',
              class_type: 'one_on_three',
              scheduling_policy: 'manual_occurrence',
              payment_type: 'session',
              sessions_purchased: 8,
              sessions_used: 7,
              remaining_sessions: 1,
              status: 'active',
              day_of_week: 6,
              start_time: '13:00',
              end_time: '15:00',
              duration_hours: 2,
              branch_id: 1,
            }],
            total: 1,
          }),
        });
      }
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ ID: 801, StudentID: 2000, student_name: '測試學生甲', SubjectID: 1, Subject: '數學', ClassType: 'one_on_one', TeacherID: 3000, teacher_name: '測試老師', Stop: 0 }], total: 1 }),
      });
    }

    if (mode === 'dashboard' && p.includes('/schedule-discrepancies/summary')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ pending: 1, acknowledged: 0, resolved: 0, withdrawn: 0 }),
      });
    }

    if (p.endsWith('/action-inbox/count')) {
      // Keep case lane for empty/error so empty/error UI is the case-lane states under test.
      const empty = mode === 'empty';
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          notifications_unread: empty ? 0 : 4,
          cases_unresolved: empty ? 1 : 2,
          cases_overdue: empty ? 0 : 1,
          cases_due_soon: empty ? 0 : 1,
          cases_candidate_ready: empty ? 0 : 1,
          urgent_total: empty ? 0 : 1,
          badge_total: empty ? 1 : 6,
        }),
      });
    }

    if (p.includes('/action-inbox') && !p.includes('/count')) {
      if (mode === 'error') {
        return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '案件狀態暫時無法更新' }) });
      }
      if (mode === 'empty') {
        return route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            cases: { data: [], total: 0, current_page: 1, last_page: 1, per_page: 20, has_more: false },
            summary: { cases_unresolved: 0 },
          }),
        });
      }
      const cases = mode === 'disabled' && requestedPage === 3
        ? [{ ...CASE_NORMAL, id: 'case-1051', student_name: '測試學生第 51 筆', title: '第 51 筆請假申請' }]
        : mode === 'long'
          ? [CASE_LONG, CASE_NORMAL]
          : [CASE_NORMAL, { ...CASE_NORMAL, id: 'case-1003', student_name: '測試學生乙', overdue: true, priority: 'overdue' }];
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          cases: {
            data: cases,
            total: mode === 'disabled' ? 51 : cases.length,
            current_page: mode === 'disabled' ? requestedPage : 1,
            last_page: mode === 'disabled' ? 3 : 1,
            per_page: 20,
            has_more: mode === 'disabled' && requestedPage < 3,
          },
          summary: { cases_unresolved: mode === 'disabled' ? 51 : cases.length, cases_candidate_ready: 1 },
        }),
      });
    }

    if (p.includes('/notifications')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: mode === 'dialog' || mode === 'long' ? [{
            id: 'notification-tuition-1',
            Type: 'tuition',
            Title: mode === 'long' ? '測試繳費通知超長標題用於驗證通知操作區在手機上仍然可讀且可操作' : '測試繳費通知',
            Body: mode === 'long'
              ? '家長已回報繳費，請登記後等待對帳。這段較長的內容用來驗證篩選、狀態與主要處理按鈕在長中文通知下不會被截斷或推出可視範圍。'
              : '家長已回報繳費，請登記後等待對帳。',
            SourceType: 'Invoice',
            Severity: 'high',
            read_at: null,
            ResolvedAt: null,
            Payload: { student_id: 2000, student_name: '測試學生甲', subject: '數學', charge: 1200 },
          }] : [],
          unread_count: mode === 'dashboard' || mode === 'dialog' || mode === 'long' ? 1 : 0,
          current_page: 1,
          last_page: 1,
          total: mode === 'dialog' || mode === 'long' ? 1 : 0,
        }),
      });
    }

    if (p.includes('/students')) {
      if (mode === 'empty') {
        return route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ data: [], total: 0 }),
        });
      }
      const count = mode === 'dense' ? 40 : 8;
      const list = fakeStudents(count, { longName: mode === 'long' });
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: list, total: list.length }),
      });
    }

    if (p.includes('/student-classes')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            {
              id: 5001,
              student_id: 2000,
              subject: 'Math',
              remaining_sessions: 8,
              sessions_purchased: 12,
              sessions_used: 4,
              payment_type: 'session',
              payment_status: 'paid',
              teacher_name: '測試老師甲',
              days_of_week: [1],
              branch_name: '測試分校',
              room_name: '101教室',
              status: 'active',
            },
            {
              id: 5002,
              student_id: 2000,
              subject: 'English',
              remaining_sessions: 1,
              sessions_purchased: 8,
              sessions_used: 7,
              payment_type: 'session',
              payment_status: 'paid',
              teacher_name: '測試老師乙',
              days_of_week: [2],
              branch_name: '測試分校',
              room_name: '202教室',
              status: 'active',
            },
            {
              id: 5003,
              student_id: 2000,
              subject: 'Science',
              remaining_sessions: 0,
              sessions_purchased: 8,
              sessions_used: 8,
              payment_type: 'session',
              payment_status: 'paid',
              teacher_name: '測試老師丙',
              days_of_week: [3],
              branch_name: '測試分校',
              room_name: '303教室',
              status: 'inactive',
              closed_reason: 'completed',
              // Server verdict (StudentClassActivity, in-app #427); the page reads it instead of re-deriving.
              history_reason: 'completed',
              is_active_course: false,
            },
          ],
        }),
      });
    }

    if (pageName === 'students' && p.includes('/class-sessions')) {
      if (mode === 'error') {
        return route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: '上課日期暫時無法載入' }) });
      }
      if (mode === 'sessions-empty') {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], projected: { by_class: {} } }) });
      }
      const materialized = [
        ...Array.from({ length: 7 }, (_, index) => ({
          id: 6100 + index,
          student_class_id: 5001,
          session_date: `2026-09-${String(index + 1).padStart(2, '0')}`,
          start_time: '16:00',
          end_time: '18:00',
          status: index === 0 ? 'attended' : 'scheduled',
        })),
        ...Array.from({ length: 4 }, (_, index) => ({
          id: 6200 + index,
          student_class_id: 5002,
          session_date: `2026-10-${String(index + 1).padStart(2, '0')}`,
          start_time: '17:00',
          end_time: '19:00',
          status: index === 0 ? 'attended' : 'scheduled',
        })),
      ];
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: materialized, projected: { by_class: {
          5003: [{ date: '2026-08-01', start_time: '15:00', end_time: '17:00' }],
        } } }),
      });
    }

    if (p.includes('/teachers') || p.includes('/subjects') || p.includes('/rooms') || p.includes('/temp-rfid')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([]) });
    }

    if (route.unmocked) return route.unmocked();
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
  });

  return { releaseHang: () => releaseHang?.() };
}
