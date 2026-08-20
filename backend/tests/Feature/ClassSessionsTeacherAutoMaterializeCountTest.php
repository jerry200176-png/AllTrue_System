<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\Schedule;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClassSessionsTeacherAutoMaterializeCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_same_day_index_materializes_sparse_count_mode_contract_gap(): void
    {
        // 2026-06-20 is Saturday (ISO 6) — matches handoff Bug 2 scenario.
        Carbon::setTestNow(Carbon::parse('2026-06-20 09:00:00', 'Asia/Taipei'));
        try {
            $campusId = 1;

            $teacher = User::create([
                'LoginName' => 'teacher-count-mat@example.com',
                'Name' => '堂數補建老師',
                'PSW' => 'secret',
                'type' => 'T',
                'phone' => '0911000111',
                'MustChangePassword' => false,
            ]);
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $teacher->id,
                'Admin' => 0,
                'Approved' => 1,
            ]);
            $token = bin2hex(random_bytes(16));
            AuthToken::create([
                'user_id' => $teacher->id,
                'token' => $token,
                'expires_at' => now()->addDay(),
            ]);

            $student = Student::create([
                'name' => '稀疏堂次學生',
                'CampusID' => $campusId,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);

            $course = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => $teacher->id,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-06-06',
                'TotalHours' => 24,
                'Charge' => 0,
                'Paid' => 1,
                'Rate' => 500,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 12,
                'SessionDuration' => 120,
                'RemainingSessions' => 10,
                'UsedSessions' => 2,
                'ClassType' => 'one_on_one',
                'week' => 6,
                'time' => '10:00:00',
            ]);

            foreach (['2026-06-06', '2026-06-13'] as $date) {
                ClassSession::create([
                    'StudentClassID' => $course->ID,
                    'SessionDate' => $date,
                    'StartTime' => '10:00:00',
                    'EndTime' => '12:00:00',
                    'Status' => 'attended',
                ]);
            }

            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->getJson('/api/v1/class-sessions?start=2026-06-20&end=2026-06-20&per_page=100');

            $response->assertOk();

            $this->assertDatabaseHas('ClassSession', [
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-06-20',
                'StartTime' => '10:00:00',
            ]);

            $rows = collect($response->json('data'));
            $matched = $rows->first(fn ($r) => (int) ($r['student_class_id'] ?? 0) === (int) $course->ID);
            $this->assertNotNull($matched, 'Same-day class-sessions list should include materialized count-mode slot');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_auto_materialize_skips_shared_package_members(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 09:00:00', 'Asia/Taipei'));
        try {
            $campusId = 1;

            $teacher = User::create([
                'LoginName' => 'teacher-pkg-skip@example.com',
                'Name' => '方案跳過老師',
                'PSW' => 'secret',
                'type' => 'T',
                'phone' => '0911000222',
                'MustChangePassword' => false,
            ]);
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $teacher->id,
                'Admin' => 0,
                'Approved' => 1,
            ]);
            $token = bin2hex(random_bytes(16));
            AuthToken::create([
                'user_id' => $teacher->id,
                'token' => $token,
                'expires_at' => now()->addDay(),
            ]);

            $student = Student::create([
                'name' => '共用方案學生',
                'CampusID' => $campusId,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);

            $course = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => $teacher->id,
                'PackageID' => 115,
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-06-06',
                'TotalHours' => 24,
                'Charge' => 0,
                'Paid' => 1,
                'Rate' => 500,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 12,
                'SessionDuration' => 120,
                'RemainingSessions' => 10,
                'UsedSessions' => 2,
                'ClassType' => 'one_on_one',
                'week' => 6,
                'time' => '10:00:00',
            ]);

            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-06-06',
                'StartTime' => '10:00:00',
                'EndTime' => '12:00:00',
                'Status' => 'attended',
            ]);

            $before = ClassSession::where('StudentClassID', $course->ID)->count();

            $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->getJson('/api/v1/class-sessions?start=2026-06-20&end=2026-06-20&per_page=100')->assertOk();

            $after = ClassSession::where('StudentClassID', $course->ID)->count();
            $this->assertSame($before, $after, 'shared-package count courses must not auto-materialize per course SessionCount');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_same_day_index_repairs_missing_session_for_normal_scheduled_exception(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 09:00:00', 'Asia/Taipei'));
        try {
            $teacher = User::create([
                'LoginName' => 'teacher-exception-repair@example.com',
                'Name' => '例外補建老師', 'PSW' => 'secret', 'type' => 'T',
                'phone' => '0911000333', 'MustChangePassword' => false,
            ]);
            $substitute = User::create([
                'LoginName' => 'teacher-exception-contract@example.com',
                'Name' => '原任老師', 'PSW' => 'secret', 'type' => 'T',
                'phone' => '0911000334', 'MustChangePassword' => false,
            ]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $substitute->id, 'Admin' => 0, 'Approved' => 1]);

            $token = bin2hex(random_bytes(16));
            AuthToken::create(['user_id' => $teacher->id, 'token' => $token, 'expires_at' => now()->addDay()]);
            $student = Student::create([
                'name' => '排課投影學生', 'CampusID' => 1, 'ClassID' => 1,
                'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
            ]);
            $course = StudentClass::create([
                'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
                'TeacherID' => $substitute->id, 'by1' => 1, 'Period' => 4,
                'StartDate' => '2026-06-01', 'TotalHours' => 0, 'Charge' => 0,
                'Paid' => 1, 'Rate' => 500, 'MDate' => now(), 'Stop' => 0,
                'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120,
                'RemainingSessions' => 8, 'UsedSessions' => 0, 'ClassType' => 'one_on_one',
            ]);

            $anchor = Schedule::create([
                'student_id' => $student->id, 'teacher_id' => $substitute->id,
                'subject' => '數學', 'day_of_week' => 6, 'start_time' => '10:00',
                'end_time' => '12:00', 'duration_hours' => 2, 'class_type' => 'one_on_one',
                'status' => 'rescheduled', 'type' => 'normal', 'deduction' => 1,
                'branch_id' => 1, 'schedule_date' => '2026-06-20',
                'student_course_id' => $course->ID,
            ]);
            Schedule::create([
                'student_id' => $student->id, 'teacher_id' => $teacher->id,
                'subject' => '數學', 'day_of_week' => 6, 'start_time' => '10:00',
                'end_time' => '12:00', 'duration_hours' => 2, 'class_type' => 'one_on_one',
                'status' => 'scheduled', 'type' => 'normal', 'deduction' => 1,
                'branch_id' => 1, 'schedule_date' => '2026-06-20',
                'student_course_id' => $course->ID, 'original_schedule_id' => $anchor->id,
            ]);

            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->getJson('/api/v1/class-sessions?teacher_id=' . $teacher->id
                . '&start=2026-06-20&end=2026-06-20&per_page=100');

            $response->assertOk();
            $this->assertDatabaseHas('ClassSession', [
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-06-20',
                'StartTime' => '10:00:00',
            ]);
            $this->assertNotEmpty($response->json('data'));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Count-mode analog of ClassSessionsTeacherAutoMaterializeMonthlyTest's
     * test_auto_materialize_does_not_n_plus_one_on_teacher_class_count: the loop in
     * autoMaterializeCountSessionsForRange() used to call extendSessionsIfNeeded()
     * once per count-mode contract, each doing its own uncached
     * ClassSession::where('StudentClassID', ...)->get() query — N+1, linear with the
     * teacher's count-mode contract count. That existence check is now batch-preloaded
     * once outside the loop (one `WHERE StudentClassID IN (...)` query for all
     * candidate classes), so it must appear exactly once regardless of contract count,
     * and the old per-class `WHERE StudentClassID = ?` shape of that same query must
     * not appear at all.
     *
     * Note: extendSessionsIfNeeded() still runs other genuinely per-class business
     * logic (SessionDeductionService::recomputeCounters, Subject lookup, upsertSlot
     * writes) that legitimately scales with contract count and is out of scope here —
     * this test isolates only the batchable existence-check query the fix targets.
     */
    public function test_auto_materialize_batches_existing_session_lookup_across_count_mode_contracts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 09:00:00', 'Asia/Taipei')); // Saturday (ISO 6)
        try {
            [, $queries2] = $this->materializeCountModeQueryLogForClasses(2);
            [, $queries8] = $this->materializeCountModeQueryLogForClasses(8);

            foreach ([2 => $queries2, 8 => $queries8] as $n => $queries) {
                $batchedLookups = array_filter($queries, fn ($q) => $this->isBatchedExistingSessionQuery($q));
                $perClassLookups = array_filter($queries, fn ($q) => $this->isPerClassExistingSessionQuery($q));

                $this->assertCount(
                    1,
                    $batchedLookups,
                    "N={$n}: batched existing-ClassSession lookup should run exactly once regardless of contract count"
                );
                $this->assertCount(
                    0,
                    $perClassLookups,
                    "N={$n}: old per-class existing-ClassSession lookup (N+1 shape) should not appear"
                );
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    private function isBatchedExistingSessionQuery(array $q): bool
    {
        $sql = $q['query'];

        return str_contains($sql, '`ClassSession`')
            && str_contains($sql, '`StudentClassID` in (')
            && str_contains($sql, 'order by `SessionDate`');
    }

    private function isPerClassExistingSessionQuery(array $q): bool
    {
        $sql = $q['query'];

        return str_contains($sql, '`ClassSession`')
            && str_contains($sql, '`StudentClassID` = ?')
            && str_contains($sql, 'order by `SessionDate`');
    }

    /**
     * Creates N sparse count-mode contracts (same shape as
     * test_teacher_same_day_index_materializes_sparse_count_mode_contract_gap: 2 of 12
     * sessions already recorded, weekly Saturday 10:00, today's slot missing) for one
     * teacher, hits the teacher same-day class-sessions endpoint once, and returns
     * [courseIds, queryLog] for that request. Also asserts each class's gap was
     * materialized exactly once (no duplicates from the batch preload).
     *
     * @return array{0: array<int, int>, 1: array<int, array{query: string, bindings: array, time: float}>}
     */
    private function materializeCountModeQueryLogForClasses(int $n): array
    {
        $campusId = 1;
        $teacher = User::create([
            'LoginName' => 'teacher-count-nplus1-' . uniqid() . '@example.com',
            'Name' => 'Count N+1 老師',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0911999000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacher->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        $courseIds = [];
        for ($i = 0; $i < $n; $i++) {
            $student = Student::create([
                'name' => "Count N+1 學生{$i}", 'CampusID' => $campusId, 'ClassID' => 1,
                'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
            ]);
            $course = StudentClass::create([
                'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacher->id,
                'by1' => 1, 'Period' => 4, 'StartDate' => '2026-06-06', 'TotalHours' => 24,
                'Charge' => 0, 'Paid' => 1, 'Rate' => 500, 'MDate' => now(), 'Stop' => 0,
                'ScheduleMode' => 'count', 'SessionCount' => 12, 'SessionDuration' => 120,
                'RemainingSessions' => 10, 'UsedSessions' => 2, 'ClassType' => 'one_on_one',
                'week' => 6, 'time' => '10:00:00',
            ]);
            $courseIds[] = $course->ID;

            foreach (['2026-06-06', '2026-06-13'] as $date) {
                ClassSession::create([
                    'StudentClassID' => $course->ID,
                    'SessionDate' => $date,
                    'StartTime' => '10:00:00',
                    'EndTime' => '12:00:00',
                    'Status' => 'attended',
                ]);
            }
        }

        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withHeaders($headers)->getJson('/api/v1/class-sessions?start=2026-06-20&end=2026-06-20&per_page=200')->assertOk();
        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        foreach ($courseIds as $courseId) {
            $this->assertSame(
                1,
                ClassSession::where('StudentClassID', $courseId)
                    ->where('SessionDate', '2026-06-20')
                    ->where('StartTime', '10:00:00')
                    ->count(),
                "每個 count-mode 契約的今日堂次應只被補建一次（class {$courseId}）"
            );
        }

        return [$courseIds, $queryLog];
    }
}
