const express = require('express');
const cors = require('cors');
const initSqlJs = require('sql.js');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const app = express();
app.use(cors());
app.use(express.json());

const DB_PATH = path.join(__dirname, 'alltrue.db');
let db;

// Save DB to disk periodically and on changes
function saveDb() {
    const data = db.export();
    const buffer = Buffer.from(data);
    fs.writeFileSync(DB_PATH, buffer);
}

async function start() {
    const SQL = await initSqlJs();

    // Load existing or create new
    if (fs.existsSync(DB_PATH)) {
        const fileBuffer = fs.readFileSync(DB_PATH);
        db = new SQL.Database(fileBuffer);
        console.log(`📦 Loaded existing database: ${DB_PATH}`);
    } else {
        db = new SQL.Database();
        console.log(`📦 Created new database: ${DB_PATH}`);
    }

    // --- Create Tables ---
    db.run(`
    CREATE TABLE IF NOT EXISTS profiles (
      id TEXT PRIMARY KEY,
      email TEXT UNIQUE,
      username TEXT NOT NULL,
      password TEXT NOT NULL DEFAULT '',
      role TEXT NOT NULL DEFAULT 'teacher',
      status TEXT DEFAULT 'pending',
      branch_id TEXT DEFAULT 'daan',
      created_at TEXT DEFAULT (datetime('now'))
    )
  `);
    db.run(`
    CREATE TABLE IF NOT EXISTS students (
      id TEXT PRIMARY KEY,
      name TEXT NOT NULL,
      grade TEXT DEFAULT '',
      school TEXT DEFAULT '',
      phone TEXT DEFAULT '',
      parent_phone TEXT DEFAULT '',
      parent_name TEXT DEFAULT '',
      rfid_card TEXT DEFAULT '',
      branch_id TEXT DEFAULT 'daan',
      remaining_lessons INTEGER DEFAULT 0,
      status TEXT DEFAULT 'active',
      created_at TEXT DEFAULT (datetime('now'))
    )
  `);
    db.run(`
    CREATE TABLE IF NOT EXISTS student_courses (
      id TEXT PRIMARY KEY,
      student_id TEXT,
      teacher_id TEXT,
      branch_id TEXT DEFAULT 'daan',
      subject TEXT NOT NULL DEFAULT 'Math',
      class_type TEXT DEFAULT 'one_on_one',
      rate_per_30min INTEGER DEFAULT 500,
      duration_hours REAL DEFAULT 2,
      payment_type TEXT DEFAULT 'session',
      sessions_purchased INTEGER DEFAULT 0,
      remaining_sessions INTEGER DEFAULT 0,
      day_of_week INTEGER DEFAULT 0,
      start_time TEXT DEFAULT '',
      end_time TEXT DEFAULT '',
      status TEXT DEFAULT 'active',
      created_at TEXT DEFAULT (datetime('now'))
    )
  `);
    db.run(`
    CREATE TABLE IF NOT EXISTS default_rates (
      id TEXT PRIMARY KEY,
      grade TEXT DEFAULT '',
      subject TEXT DEFAULT 'Math',
      class_type TEXT DEFAULT 'one_on_one',
      rate_per_30min INTEGER DEFAULT 500,
      created_at TEXT DEFAULT (datetime('now'))
    )
  `);
    db.run(`
    CREATE TABLE IF NOT EXISTS schedules (
      id TEXT PRIMARY KEY,
      student_id TEXT,
      teacher_id TEXT,
      subject TEXT DEFAULT '',
      day_of_week INTEGER DEFAULT 0,
      start_time TEXT DEFAULT '',
      end_time TEXT DEFAULT '',
      duration_hours REAL DEFAULT 2,
      class_type TEXT DEFAULT 'one_on_one',
      status TEXT DEFAULT 'scheduled',
      deduction INTEGER DEFAULT 1,
      branch_id TEXT DEFAULT 'daan',
      type TEXT DEFAULT 'normal',
      original_schedule_id TEXT DEFAULT '',
      schedule_date TEXT DEFAULT '',
      student_course_id TEXT DEFAULT '',
      created_at TEXT DEFAULT (datetime('now'))
    )
  `);
    db.run(`
    CREATE TABLE IF NOT EXISTS evaluations (
      id TEXT PRIMARY KEY,
      schedule_id TEXT,
      teacher_id TEXT,
      content TEXT DEFAULT '',
      homework TEXT DEFAULT '',
      status TEXT DEFAULT 'pending',
      approved_at TEXT,
      created_at TEXT DEFAULT (datetime('now'))
    )
  `);
    db.run(`
    CREATE TABLE IF NOT EXISTS teacher_branches (
      id TEXT PRIMARY KEY,
      teacher_id TEXT,
      branch_id TEXT
    )
  `);

    // --- Migration: add new columns to existing tables ---
    try { db.run(`ALTER TABLE student_courses ADD COLUMN remaining_sessions INTEGER DEFAULT 0`); } catch(e) { /* column already exists */ }
    try { db.run(`ALTER TABLE student_courses ADD COLUMN payment_status TEXT DEFAULT 'unpaid'`); } catch(e) { /* column already exists */ }
    try { db.run(`ALTER TABLE schedules ADD COLUMN type TEXT DEFAULT 'normal'`); } catch(e) { /* column already exists */ }
    try { db.run(`ALTER TABLE schedules ADD COLUMN original_schedule_id TEXT DEFAULT ''`); } catch(e) { /* column already exists */ }
    try { db.run(`ALTER TABLE schedules ADD COLUMN schedule_date TEXT DEFAULT ''`); } catch(e) { /* column already exists */ }
    try { db.run(`ALTER TABLE schedules ADD COLUMN student_course_id TEXT DEFAULT ''`); } catch(e) { /* column already exists */ }
    try { db.run(`ALTER TABLE profiles ADD COLUMN phone TEXT DEFAULT ''`); } catch(e) { /* column already exists */ }
    try { db.run(`ALTER TABLE students ADD COLUMN notes TEXT DEFAULT ''`); } catch(e) { /* column already exists */ }

    // --- One-time full data reset ---
    const studentCount = (db.exec("SELECT COUNT(*) FROM students")[0]?.values[0]?.[0]) || 0;
    const teacherCount = (db.exec("SELECT COUNT(*) FROM profiles WHERE role = 'teacher'")[0]?.values[0]?.[0]) || 0;
    if (studentCount > 0 || teacherCount > 0) {
        console.log(`🧹 Full data reset: ${studentCount} student(s), ${teacherCount} teacher(s)...`);
        db.run(`DELETE FROM evaluations`);
        db.run(`DELETE FROM schedules`);
        db.run(`DELETE FROM student_courses`);
        db.run(`DELETE FROM teacher_branches`);
        db.run(`DELETE FROM students`);
        db.run(`DELETE FROM profiles WHERE role = 'teacher'`);
        saveDb();
        console.log('🧹 All student and teacher data cleared. Director account kept.');
    }

    // --- Migration: initialize remaining_sessions for existing courses ---
    db.run(`UPDATE student_courses SET remaining_sessions = sessions_purchased WHERE remaining_sessions = 0 AND sessions_purchased > 0`);

    // Seed default director
    const directors = db.exec("SELECT id FROM profiles WHERE role = 'director' LIMIT 1");
    if (directors.length === 0 || directors[0].values.length === 0) {
        const id = crypto.randomUUID();
        db.run(
            `INSERT INTO profiles (id, email, username, password, role, status, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?)`,
            [id, 'director@alltrue.com', '主任', 'admin123', 'director', 'active', 'daan']
        );
        console.log('🔑 Created default director: director@alltrue.com / admin123');
        saveDb();
    }

    // --- Helper ---
    function queryAll(sql, params = []) {
        const stmt = db.prepare(sql);
        stmt.bind(params);
        const rows = [];
        while (stmt.step()) {
            rows.push(stmt.getAsObject());
        }
        stmt.free();
        return rows;
    }

    function queryOne(sql, params = []) {
        const rows = queryAll(sql, params);
        return rows[0] || null;
    }

    // --- Generic CRUD ---
    const TABLES = ['profiles', 'students', 'student_courses', 'default_rates', 'schedules', 'evaluations', 'teacher_branches'];

    TABLES.forEach(table => {
        // GET
        app.get(`/api/${table}`, (req, res) => {
            try {
                const conditions = [];
                const params = [];
                const skipKeys = ['select', 'order', 'limit', 'single'];

                for (const [key, val] of Object.entries(req.query)) {
                    if (skipKeys.includes(key)) continue;
                    if (key.endsWith('__neq')) {
                        conditions.push(`${key.replace('__neq', '')} != ?`);
                        params.push(val);
                    } else if (key.endsWith('__ilike')) {
                        conditions.push(`${key.replace('__ilike', '')} LIKE ? COLLATE NOCASE`);
                        params.push(val);
                    } else if (key.endsWith('__gte')) {
                        conditions.push(`${key.replace('__gte', '')} >= ?`);
                        params.push(val);
                    } else if (key.endsWith('__lte')) {
                        conditions.push(`${key.replace('__lte', '')} <= ?`);
                        params.push(val);
                    } else {
                        conditions.push(`${key} = ?`);
                        params.push(val);
                    }
                }

                const where = conditions.length ? 'WHERE ' + conditions.join(' AND ') : '';
                const order = req.query.order || 'created_at';
                const rows = queryAll(`SELECT * FROM ${table} ${where} ORDER BY ${order}`, params);

                // Auto-joins for student_courses
                if (table === 'student_courses') {
                    rows.forEach(row => {
                        row.student = row.student_id ? queryOne('SELECT name, remaining_lessons FROM students WHERE id = ?', [row.student_id]) : null;
                        row.teacher = row.teacher_id ? queryOne('SELECT username FROM profiles WHERE id = ?', [row.teacher_id]) : null;
                    });
                }

                // Auto-joins for schedules
                if (table === 'schedules') {
                    rows.forEach(row => {
                        row.student = row.student_id ? queryOne('SELECT name FROM students WHERE id = ?', [row.student_id]) : null;
                        row.teacher = row.teacher_id ? queryOne('SELECT username FROM profiles WHERE id = ?', [row.teacher_id]) : null;
                    });
                }

                // Auto-joins for evaluations
                if (table === 'evaluations') {
                    rows.forEach(row => {
                        if (row.schedule_id) {
                            const sched = queryOne('SELECT * FROM schedules WHERE id = ?', [row.schedule_id]);
                            if (sched) {
                                sched.student = sched.student_id ? queryOne('SELECT name FROM students WHERE id = ?', [sched.student_id]) : null;
                                sched.teacher = sched.teacher_id ? queryOne('SELECT username FROM profiles WHERE id = ?', [sched.teacher_id]) : null;
                            }
                            row.schedules = sched;
                        }
                    });
                }

                if (req.query.single === 'true') {
                    res.json({ data: rows[0] || null, error: null });
                } else {
                    res.json({ data: rows, error: null });
                }
            } catch (err) {
                res.json({ data: null, error: { message: err.message } });
            }
        });

        // POST
        app.post(`/api/${table}`, (req, res) => {
            try {
                const items = Array.isArray(req.body) ? req.body : [req.body];
                const inserted = [];
                for (const item of items) {
                    if (!item.id) item.id = crypto.randomUUID();
                    const keys = Object.keys(item);
                    const placeholders = keys.map(() => '?').join(', ');
                    db.run(`INSERT INTO ${table} (${keys.join(', ')}) VALUES (${placeholders})`, keys.map(k => item[k]));
                    inserted.push(item);
                }
                saveDb();
                res.json({ data: inserted, error: null });
            } catch (err) {
                res.json({ data: null, error: { message: err.message } });
            }
        });

        // PUT
        app.put(`/api/${table}/:id`, (req, res) => {
            try {
                const updates = req.body;
                const keys = Object.keys(updates);
                const setClause = keys.map(k => `${k} = ?`).join(', ');
                db.run(`UPDATE ${table} SET ${setClause} WHERE id = ?`, [...keys.map(k => updates[k]), req.params.id]);
                saveDb();
                const updated = queryOne(`SELECT * FROM ${table} WHERE id = ?`, [req.params.id]);
                res.json({ data: updated, error: null });
            } catch (err) {
                res.json({ data: null, error: { message: err.message } });
            }
        });

        // DELETE by id or by query filters
        app.delete(`/api/${table}/:id?`, (req, res) => {
            try {
                if (req.params.id) {
                    // Delete by id
                    db.run(`DELETE FROM ${table} WHERE id = ?`, [req.params.id]);
                } else {
                    // Delete by query filters (e.g. ?teacher_id=xxx)
                    const conditions = [];
                    const params = [];
                    for (const [key, val] of Object.entries(req.query)) {
                        conditions.push(`${key} = ?`);
                        params.push(val);
                    }
                    if (conditions.length === 0) {
                        return res.json({ data: null, error: { message: 'No filter provided for delete' } });
                    }
                    db.run(`DELETE FROM ${table} WHERE ${conditions.join(' AND ')}`, params);
                }
                saveDb();
                res.json({ data: null, error: null });
            } catch (err) {
                res.json({ data: null, error: { message: err.message } });
            }
        });
    });

    // --- Auth ---
    app.post('/api/auth/login', (req, res) => {
        const { email, password } = req.body;
        // Support login by email OR username
        const user = queryOne('SELECT * FROM profiles WHERE email = ? OR username = ?', [email, email]);
        if (!user) return res.json({ data: null, error: { message: '帳號不存在' } });
        if (user.password !== password) return res.json({ data: null, error: { message: '密碼不正確' } });
        res.json({
            data: {
                session: { user: { id: user.id, email: user.email } },
                user: { id: user.id, email: user.email }
            },
            error: null
        });
    });

    app.post('/api/auth/register', (req, res) => {
        const { email, password, username } = req.body;
        const existing = queryOne('SELECT id FROM profiles WHERE email = ?', [email]);
        if (existing) return res.json({ data: null, error: { message: '此 email 已被註冊' } });
        const id = crypto.randomUUID();
        const displayName = username || email.split('@')[0];
        db.run('INSERT INTO profiles (id, email, password, username, role, status) VALUES (?, ?, ?, ?, ?, ?)',
            [id, email, password, displayName, 'teacher', 'pending']);
        saveDb();
        res.json({
            data: {
                session: { user: { id, email } },
                user: { id, email }
            },
            error: null
        });
    });

    // --- Serve Frontend (production build) ---
    const frontendDist = path.join(__dirname, '..', 'frontend', 'dist');
    if (fs.existsSync(frontendDist)) {
        app.use(express.static(frontendDist));
        // SPA fallback: any non-API route serves index.html
        app.get('*', (req, res) => {
            if (!req.path.startsWith('/api')) {
                res.sendFile(path.join(frontendDist, 'index.html'));
            }
        });
        console.log('📂 Serving frontend from:', frontendDist);
    }

    // --- Start ---
    const PORT = 3001;
    // Get local IP for display
    const os = require('os');
    const nets = os.networkInterfaces();
    let localIP = 'unknown';
    for (const name of Object.keys(nets)) {
        for (const net of nets[name]) {
            if (net.family === 'IPv4' && !net.internal) {
                localIP = net.address;
                break;
            }
        }
        if (localIP !== 'unknown') break;
    }

    app.listen(PORT, '0.0.0.0', () => {
        console.log('');
        console.log('══════════════════════════════════════════');
        console.log('  ✅ AllTrue 教務管理系統已啟動！');
        console.log('══════════════════════════════════════════');
        console.log('');
        console.log(`  📍 本機:     http://localhost:${PORT}`);
        console.log(`  📍 區域網路: http://${localIP}:${PORT}`);
        console.log('');
        console.log('  👉 其他裝置請在瀏覽器輸入:');
        console.log(`     http://${localIP}:${PORT}`);
        console.log('');
        console.log('  📋 預設帳號: director@alltrue.com');
        console.log('  📋 預設密碼: admin123');
        console.log('══════════════════════════════════════════');
        console.log('');
    });

    // Auto-save every 30 seconds
    setInterval(saveDb, 30000);
}

start().catch(err => {
    console.error('Failed to start server:', err);
    process.exit(1);
});
