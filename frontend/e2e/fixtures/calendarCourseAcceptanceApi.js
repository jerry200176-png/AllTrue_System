// Synthetic-only API for the full calendar/course spec. The app and browser guard are the shipping code.
export const CALENDAR_COURSE_LOCAL_PORT = 5197;
export const calendarCourseFixtureUser = {
  id: 9001, name: 'Synthetic Director', role: 'director', campuses: [9],
  must_change_password: false, capabilities: ['director'], capability_campuses: { director: [9] },
};
const course = {
  id: 9009, student_id: 9002, student_name: '合成堂數課程學生', teacher_id: 9003, teacher_name: '合成老師',
  branch_id: 9, subject: '數學', subject_name: '數學', class_type: 'one_on_two', Stop: 1, status: 'inactive',
  closed_reason: 'contract_amended', history_reason: null, is_active_course: false, ScheduleMode: 'count',
  payment_type: 'session', payment_status: 'paid', sessions_purchased: 4, sessions_used: 2, used_sessions: 2,
  remaining_sessions: 2, start_time: '17:00', end_time: '19:00', duration_hours: 2,
  first_class_date: '2026-08-05', start_date: '2026-08-05', days_of_week: [3],
};
const rows = ['2026-08-05', '2026-08-12'].map((date, index) => ({
  id: 9100 + index, student_class_id: course.id, student_id: course.student_id, teacher_id: course.teacher_id,
  session_date: date, start_time: '17:00', end_time: '19:00', status: 'attended',
}));
const liveCourse = {
  ...course, id: 9011, student_id: 9012, student_name: '合成日曆學生', status: 'active',
  closed_reason: null, is_active_course: true, Stop: 0, teacher_id: 9003, room_id: 19,
  start_time: '10:00', end_time: '11:00', duration_hours: 1, week: 3, time: '10:00',
  first_class_date: '2026-08-01', start_date: '2026-08-01', end_date: '2026-08-31',
};
const liveRow = {
  id: 9111, student_class_id: liveCourse.id, student_id: liveCourse.student_id, student_name: liveCourse.student_name,
  teacher_id: 9003, room_id: 19, subject: '數學', session_date: '2026-08-05',
  start_time: '10:00', end_time: '11:00', status: 'scheduled',
};
const orphan = {
  id: 9221, branch_id: 9, student_course_id: liveCourse.id, student_name: liveCourse.student_name, subject: '數學',
  schedule_date: '2026-08-07', start_time: '16:00', end_time: '17:00', teacher_id: 9003, room_id: 19,
  status: 'scheduled', original_schedule_id: 9220,
};

export function calendarCourseAcceptanceApi() {
  return {
    name: 'synthetic-campus9-case',
    configureServer(server) {
      server.middlewares.use((req, res, next) => {
        const url = new URL(req.url, 'http://127.0.0.1');
        const send = (body, status = 200) => {
          res.writeHead(status, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify(body));
        };
        if (!url.pathname.startsWith('/api/v1/')) return next();
        if (req.method !== 'GET') return send({ message: 'Calendar acceptance fixture only serves reads.' }, 405);
        if (url.pathname === '/api/v1/student-classes/session-dates') {
          const start = url.searchParams.get('range_start');
          const end = url.searchParams.get('range_end');
          if (url.searchParams.get('branch_id') !== '9' || [...url.searchParams].length !== 3 || !start || !end || start > end) {
            return send({}, 400);
          }
          return send({
            [course.id]: { materialized: rows.filter((row) => row.session_date >= start && row.session_date <= end), projected: [] },
            [liveCourse.id]: {
              materialized: [liveRow].filter((row) => row.session_date >= start && row.session_date <= end), projected: [],
            },
          });
        }

        if (url.pathname === '/api/v1/me') return send(calendarCourseFixtureUser);
        if (url.pathname === '/api/v1/campuses') return send([{ id: 9, name: '合成分校 9' }]);
        if (url.pathname === '/api/v1/bindings/stats') {
          return send({ bound_count: 2, unverified_bound_count: 1, student_total: 3 });
        }
        if (url.pathname === '/api/v1/bindings') {
          const data = [
            { id: 9511, student_id: 9012, student_name: '合成已驗證學生', status: 'verified', verified_at: '2026-08-05' },
            { id: 9512, student_id: 9013, student_name: '合成待驗證學生', status: 'unverified' },
          ];
          return send({ data, total: data.length, current_page: 1, last_page: 1 });
        }
        if (url.pathname === '/api/v1/teachers') return send([{ id: 9003, name: '合成老師', status: 'active' }]);
        if (url.pathname === '/api/v1/classrooms' || url.pathname === '/api/v1/rooms') {
          return send([{ id: 19, name: '合成教室', branch_id: 9 }]);
        }
        if (url.pathname === '/api/v1/schedules') return send({ data: [orphan], current_page: 1, last_page: 1, total: 1 });
        if (url.pathname === '/api/v1/student-classes') {
          if (url.searchParams.get('branch_id') !== '9') return send({}, 403);
          const name = url.searchParams.get('name');
          const data = [course, liveCourse].filter((item) => !name || item.student_name.includes(name));
          return send({ data, total: data.length, current_page: 1, last_page: 1 });
        }
        if (url.pathname === '/api/v1/class-sessions/projection') {
          const start = url.searchParams.get('start');
          const end = url.searchParams.get('end');
          if (url.searchParams.get('branch_id') !== '9' || !start || !end || start >= end) return send({}, 400);
          const data = [...rows, liveRow].filter((row) => row.session_date >= start && row.session_date <= end);
          const byClass = Object.fromEntries([course.id, liveCourse.id].map((id) => [id, data.filter((row) => row.student_class_id === id)]));
          return send({ api_kind: 'projection', completeness: 'full', data, by_class: byClass });
        }
        return next();
      });
    },
  };
}
