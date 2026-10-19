<?php
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/alltrueTestDatabase.php';
$d = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
try { alltrue_boot_race_worker($d); } catch (InvalidArgumentException $e) { exit(90); }
use Illuminate\Support\Facades\DB;
DB::purge(); DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
alltrue_install_sql_pause($d['pause'], 'synthetic scheduling barrier timed out');
$pending = $d['before_schedule'];
DB::connection()->beforeExecuting(function ($sql) use (&$pending) {
    if ($pending && str_contains(strtolower($sql), 'schedules') && str_contains(strtolower($sql), 'for update')) { touch($pending); $pending = null; }
});
$course = new App\Models\StudentClass; $course->setRawAttributes(['ID' => $d['course']]); $course->exists = true;
try {
    touch($d['started']);
    DB::transaction(fn () => app(App\Services\ScheduleGuardService::class)->validateCourseOccurrence($course, $d['campus'], '2026-12-29', '13:00', '15:00', true));
    $result = ['status' => 200];
} catch (Throwable $e) { $result = ['status' => 500, 'body' => ['error' => $e->errorInfo[1] ?? $e->getCode()]]; }
echo json_encode($result, JSON_THROW_ON_ERROR);
