<?php
// Synthetic staging demo data (fake names/phones only). Piped to `php` in the app container by
// lifecycle/seed-demo.sh, which runs it right after `migrate:fresh` (nightly reset = same path).
// Passwords come from env, never from the repo. Dates are relative to today so every view has content.
chdir('/var/www');
require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Hash};

if (config('app.env') !== 'staging') { fwrite(STDERR, "refusing: not staging\n"); exit(2); }

$cols = [];
/** Insert with defaults for NOT NULL columns that have none, so schema drift does not break the seed. */
function ins(string $t, array $v): int {
    global $cols;
    $cols[$t] ??= DB::select('select column_name n, data_type d, is_nullable nl, column_default df, extra e
        from information_schema.columns where table_schema = database() and table_name = ?', [$t]);
    $have = array_keys($v);
    foreach ($cols[$t] as $c) {
        if (in_array($c->n, $have, true) || $c->nl === 'YES' || $c->df !== null || str_contains($c->e, 'auto_increment')) continue;
        $v[$c->n] = match (true) {
            str_contains($c->d, 'int') || in_array($c->d, ['decimal', 'double', 'float']) => 0,
            in_array($c->d, ['date']) => today()->toDateString(),
            in_array($c->d, ['datetime', 'timestamp']) => now()->toDateTimeString(),
            $c->d === 'time' => '00:00:00',
            $c->d === 'json' => '{}',
            default => '',
        };
    }
    return (int) DB::table($t)->insertGetId($v);
}
$n = [];
function step(string $name, callable $f) { global $n; try { $n[$name] = $f(); } catch (Throwable $e) { $n[$name] = 'ERR ' . substr($e->getMessage(), 0, 160); } }

$campuses = [];
foreach ([['示範A分校', 'demoa'], ['示範B分校', 'demob']] as $i => [$name, $code]) {
    $campuses[] = ins('Campus', ['name' => $name, 'code' => $code, 'active' => 1, 'Current' => $i === 0 ? 1 : 0]);
}
[$cA, $cB] = $campuses;
foreach ($campuses as $c) { foreach (['教室一', '教室二'] as $r) { try { ins('rooms', ['campus_id' => $c, 'name' => $r, 'capacity' => 8, 'is_active' => 1]); } catch (Throwable $e) {} } }

$user = function (string $login, string $name, string $type, string $pw, array $camps, int $admin) {
    $id = ins('User', ['LoginName' => $login, 'Name' => $name, 'type' => $type, 'status' => 'active', 'employment_type' => 'full_time',
        'PSW' => Hash::make($pw), 'MustChangePassword' => 0, 'phone' => '0900000000', 'TeachingSessionCount' => 0]);
    foreach ($camps as $c) ins('UserCampus', ['UserID' => $id, 'CampusID' => $c, 'Admin' => $admin, 'Approved' => 1]);
    if ($type === 'T') DB::table('Teacher')->insert(['id' => $id, 'CampusID' => $camps[0], 'T_Name' => $name, 'Phone' => '0900000000',
        'LineID' => '', 'TelegramID' => '', 'Notify_Token' => '', 'Enable' => 1, 'MDT' => now()]);
    return $id;
};
$director = $user('demo-director', '示範主任', 'D', getenv('DEMO_PW_DIRECTOR'), [$cA, $cB], 1);
$tA = $user('demo-teacher', '示範老師', 'T', getenv('DEMO_PW_TEACHER'), [$cA], 0);
$tB = $user('demo-teacher-b', '示範老師乙', 'T', bin2hex(random_bytes(12)), [$cB], 0);   // no known password: not a demo login
$teachers = [$cA => $tA, $cB => $tB];

$subjects = [];
foreach ($campuses as $c) foreach (['數學', '英文', '國文', '自然'] as $k => $s) {
    $subjects[$c][] = ins('Subject', ['School_id' => 1, 'Grade_no' => 3 + $k, 'Subject_Name' => $s, 'CampusID' => $c]);
}

$students = [];
$names = [['王小明', '陳小美', '林小華', '張小龍'], ['李小安', '黃小芸', '吳小傑', '周小晴']];
foreach ($campuses as $ci => $c) foreach ($names[$ci] as $k => $nm) {
    $ph = sprintf('09110%02d%03d', $ci + 1, $k + 1);
    $id = ins('Student', ['name' => $nm, 'CampusID' => $c, 'ClassID' => 3 + $k, 'SchoolName' => '示範國小', 'TelegramID' => '', 'enable' => 1,
        'MDT' => now(), 'Notify_Token' => '', 'Phone' => $ph, 'parent_name' => $nm . '家長', 'parent_phone' => $ph, 'status' => 'active']);
    $students[] = ['id' => $id, 'name' => $nm, 'phone' => $ph, 'campus' => $c, 'grade' => 3 + $k, 'k' => $k];
}
step('guardians', function () use ($students) {
    foreach ($students as $s) {
        $g = ins('guardians', ['display_name' => $s['name'] . '家長', 'phone' => $s['phone'], 'phone_normalized' => $s['phone']]);
        ins('student_guardians', ['student_id' => $s['id'], 'guardian_id' => $g, 'campus_id' => $s['campus'], 'role' => 'parent',
            'is_primary' => 1, 'status' => 'active', 'notify_learning_feedback' => 1, 'notify_tuition' => 1, 'source' => 'seed']);
    }
    return count($students);
});

$courses = []; $sessions = 0; $attended = 0; $records = 0;
step('courses', function () use (&$courses, &$sessions, &$attended, &$records, $students, $subjects, $teachers) {
    foreach ($students as $s) foreach ([0, 1] as $j) {
        if ($j === 1 && $s['k'] % 2) continue;
        $subj = $subjects[$s['campus']][($s['k'] + $j) % 4]; $t = $teachers[$s['campus']];
        $dow = 1 + (($s['k'] + $j * 2) % 5); $time = sprintf('%02d:00:00', 16 + $j);
        $cid = ins('StudentClass', ['StudentID' => $s['id'], 'GradeID' => $s['grade'], 'SubjectID' => $subj, 'TeacherID' => $t, 'by1' => $t,
            'Period' => 4, 'StartDate' => today()->subWeeks(5)->toDateTimeString(), 'week' => $dow, 'time' => $time, 'TotalHours' => 16,
            'Charge' => 4800, 'Pay' => 4800, 'Paid' => 1, 'Stop' => 0, 'Memo' => '示範課程']);
        $courses[] = ['id' => $cid, 'sid' => $s['id'], 'subj' => $subj, 't' => $t, 'camp' => $s['campus'], 'grade' => $s['grade']];
        $first = today()->subWeeks(4)->startOfWeek()->addDays($dow - 1);
        for ($w = 0; $w < 8; $w++) {
            $d = $first->copy()->addWeeks($w); $past = $d->lt(today());
            $status = $past ? (($w + $s['k']) % 5 === 4 ? 'leave' : 'completed') : 'scheduled';
            $sess = ins('ClassSession', ['StudentClassID' => $cid, 'SubjectID' => $subj, 'SessionDate' => $d->toDateString(), 'StartTime' => $time,
                'EndTime' => sprintf('%02d:00:00', (int) substr($time, 0, 2) + 1), 'Status' => $status, 'IsContractException' => 0, 'session_charge' => 1]);
            $sessions++;
            if ($status !== 'completed') continue;
            try {
                App\Models\StudentSignIn::create(['StudentClassID' => $cid, 'StudentID' => $s['id'], 'TeacherID' => $t, 'GradeID' => $s['grade'],
                    'SubjectID' => $subj, 'Hours' => 1, 'SignInDT' => $d->copy()->setTimeFromTimeString($time), 'SignOutDT' => $d->copy()->setTimeFromTimeString($time)->addHour(),
                    'MDT' => now(), 'ClassSessionID' => $sess, 'Status' => 'attended']);
                $attended++;
            } catch (Throwable $e) {}
            if ($w % 2 === 0) {
                try {
                    ins('LearningRecord', ['StudentID' => $s['id'], 'StudentClassID' => $cid, 'ClassSessionID' => $sess, 'TeacherID' => $t,
                        'CreatedByUserID' => $t, 'Content' => '今天完成單元練習，課堂參與度良好。', 'Status' => $w % 4 === 0 ? 'approved' : 'pending',
                        'SessionDate' => $d->toDateString(), 'StartTime' => $time, 'Performance' => '良好', 'Comment' => '示範評量']);
                    $records++;
                } catch (Throwable $e) {}
            }
        }
    }
    return count($courses);
});

$inv = 0;
step('billing', function () use ($courses, &$inv) {
    foreach ($courses as $i => $c) {
        $state = $i % 3;   // 0 paid, 1 unpaid, 2 partial
        $id = ins('Invoice', ['StudentID' => $c['sid'], 'StudentClassID' => $c['id'], 'IssueDate' => today()->startOfMonth()->toDateString(),
            'DueDate' => today()->startOfMonth()->addDays(14)->toDateString(), 'TotalAmount' => 4800, 'PaidAmount' => [4800, 0, 2400][$state],
            'Status' => ['paid', 'unpaid', 'partial'][$state], 'billing_period' => today()->format('Y-m'), 'Note' => '示範帳單']);
        ins('InvoiceItem', ['InvoiceID' => $id, 'StudentClassID' => $c['id'], 'Description' => '本月學費', 'Amount' => 4800,
            'PeriodStart' => today()->startOfMonth()->toDateString(), 'PeriodEnd' => today()->endOfMonth()->toDateString()]);
        if ($state !== 1) ins('Payment', ['InvoiceID' => $id, 'Amount' => [4800, 0, 2400][$state], 'PaidAt' => now()->subDays(3)->toDateTimeString(), 'Method' => 'cash']);
        $inv++;
    }
    return $inv;
});
step('notifications', function () use ($campuses, $students) {
    $k = 0;
    foreach ($campuses as $c) foreach ([['tuition', '示範：本月有未繳學費', 'warning'], ['attendance', '示範：本週有請假課程', 'info'], ['system', '示範：歡迎使用 staging', 'info']] as [$ty, $ti, $sv]) {
        ins('Notifications', ['CampusID' => $c, 'Type' => $ty, 'Severity' => $sv, 'Title' => $ti, 'Body' => '這是示範資料，不是真實通知。',
            'SourceType' => 'Seed', 'SourceID' => (string) ++$k, 'SourceKey' => 'seed-' . $k, 'OccurredAt' => now()->subHours($k)->toDateTimeString()]);
    }
    return $k;
});

echo json_encode(['campuses' => $campuses, 'director_id' => $director, 'teacher_id' => $tA, 'courses' => count($courses), 'sessions' => $sessions,
    'attended' => $attended, 'learning_records' => $records, 'steps' => $n,
    'students' => array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['name'], 'phone' => $s['phone']], $students)], JSON_UNESCAPED_UNICODE), "\n";
