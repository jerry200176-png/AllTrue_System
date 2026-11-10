import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

// Local-only Vite fixture: real App/Login/pages, synthetic API, a real ZIP/XML
// XLSX download. Never imported by the production build or production smoke.
export function directorAcceptanceApi() {
  const root = fileURLToPath(new URL('../../..', import.meta.url));
  const generated = spawnSync('python3', ['-c', `import io,sys,zipfile
sys.path.insert(0, 'scripts/tests')
from test_student_bill_xlsx_acceptance import workbook_parts
output=io.BytesIO()
with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as archive:
 for path,text in workbook_parts().items(): archive.writestr(path,text)
sys.stdout.buffer.write(output.getvalue())`], { cwd: root, maxBuffer: 1024 * 1024 });
  if (generated.status !== 0) throw new Error('Synthetic native XLSX fixture unavailable.');
  const user = { id: 9001, name: 'Synthetic Director', role: 'director', campuses: [1],
    must_change_password: false, capabilities: ['director'], capability_campuses: { director: [1] } };
  const student = { id: 1, name: '=合成學生', campus_id: 1, status: 'active' };
  const record = { id: 501, Status: 'approved', student_id: 1, StudentClassID: 7, ClassSessionID: 1501,
    TeacherID: 9001, student_name: student.name, Subject: '數學', SessionDate: '2026-11-01',
    StartTime: '10:00', EndTime: '11:00', Progress: 'Synthetic progress', Comment: 'Synthetic comment' };
  const received = { materializer: 0, reschedule: 0 };
  return {
    name: 'director-acceptance-local-api',
    configureServer(server) {
      server.middlewares.use(async (req, res, next) => {
        const url = new URL(req.url, 'http://127.0.0.1');
        if (req.method === 'GET' && url.pathname === '/__director-acceptance-counts') {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          return res.end(JSON.stringify(received));
        }
        if (req.method === 'GET' && ['/version.json', '/deployment.json'].includes(url.pathname)) {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          return res.end(JSON.stringify({ build_sha: '0'.repeat(40), frontend_sha: '0'.repeat(40), synthetic_local: true }));
        }
        if (!url.pathname.startsWith('/api/v1/')) return next();
        const json = (body, status = 200) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };
        const path = url.pathname.slice('/api/v1'.length);
        if (['GET', 'HEAD'].includes(req.method) && ['/class-sessions', '/class-sessions/projection'].includes(path)) received.materializer += 1;
        if (req.method === 'POST' && path === '/learning-records/reschedule-session') received.reschedule += 1;
        if (req.method === 'POST') {
          let text = '';
          for await (const part of req) text += part;
          let body; try { body = JSON.parse(text); } catch { return json({}, 400); }
          if (path === '/auth/login' && body.account === 'synthetic-director' && body.password === 'synthetic-only') {
            return json({ data: { session: { access_token: 'synthetic-local-only', user }, user } });
          }
          if (path === '/accounting/bills/export' && body.branch_id === 1 && body.filter === 'all'
            && body.search === student.name && JSON.stringify(body.rows) === '[{"id":7,"package_id":null}]') {
            res.writeHead(200, { 'Content-Type': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
              'Content-Disposition': 'attachment; filename="synthetic.xlsx"' });
            return res.end(generated.stdout);
          }
          return json({ message: 'Synthetic fixture refuses business mutations.' }, 405);
        }
        if (req.method !== 'GET') return json({}, 405);
        if (path === '/me') return json(user);
        if (path === '/campuses') return json([{ id: 1, name: 'Synthetic Campus' }]);
        if (path === '/students') return json([student]);
        if (path === '/teachers') return json([]);
        if (path === '/alerts/tuition') return json([{ id: 7, student_id: 1, student_name: student.name,
          subject: '數學', schedule_mode: 'count', remaining_sessions: 4, payment_status: 'unpaid',
          payable_status: 'invoiced', payable_outstanding: 100, paid_amount: 0, due_date: '2026-01-01' }]);
        if (path === '/learning-records') {
          const records = !url.searchParams.has('status') || url.searchParams.get('status') === 'approved' ? [record] : [];
          return json({ data: records, total: records.length, current_page: 1, last_page: 1,
            status_counts: { approved: 1, pending: 0, changes_requested: 0, rejected: 0 } });
        }
        if (path === '/learning-records/status-counts') return json({ approved: 1, pending: 0, changes_requested: 0, rejected: 0 });
        return json({ data: [], total: 0, unread_count: 0, current_page: 1, last_page: 1 });
      });
    },
  };
}
