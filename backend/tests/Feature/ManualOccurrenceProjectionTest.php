<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManualOccurrenceProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_fortnightly_pause_resume_and_read_projection_investigation(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-27 08:00:00', 'Asia/Taipei'));
        try {
            $token = $this->createDirectorToken([1]);
            $headers = ['Authorization' => "Bearer {$token}"];
            foreach ([['count', 'auto_recurrence'], ['date', 'auto_recurrence'], ['count', 'manual_occurrence'], ['date', 'manual_occurrence']] as [$mode, $policy]) {
                $sc = $this->createStudentClassOnCampus(1, [
                    'ScheduleMode' => $mode, 'StartDate' => '2026-11-03', 'EndDate' => '2026-12-01',
                    'week' => 2, 'time' => '23:00:00', 'SessionCount' => 8, 'scheduling_policy' => $policy,
                ]);
                $this->assertSame($policy, DB::table('StudentClass')->where('ID', $sc->ID)->value('scheduling_policy'));
                $dates = ['2026-11-03', '2026-11-17', '2026-12-01'];
                foreach ($dates as $date) $this->createClassSession((int) $sc->ID, $date, 'scheduled');
                $rows = fn () => DB::table('ClassSession')->where('StudentClassID', $sc->ID)->orderBy('id')
                    ->get(['id', 'SessionDate', 'StartTime', 'EndTime', 'Status'])->map(fn ($r) => (array) $r)->all();
                $before = $rows();
                $this->postJson("/api/v1/student-classes/{$sc->ID}/pause", ['action' => 'pause'], $headers)->assertOk();
                $this->assertSame(['cancelled', 'cancelled', 'cancelled'], array_column($rows(), 'Status'));
                $this->postJson("/api/v1/student-classes/{$sc->ID}/pause", ['action' => 'resume'], $headers)->assertOk();
                $this->assertSame($before, $rows(), "{$mode}: resume must restore exact original rows before reads");
                $index = $this->getJson("/api/v1/class-sessions?branch_id=1&student_class_id={$sc->ID}&start=2026-11-03&end=2026-12-01", $headers);
                $index->assertOk();
                $this->assertSame($before, $rows(), "{$mode}: range index must not add materialized rows");
                $projected = $index->json("projected.by_class.{$sc->ID}") ?? [];
                $projectedDates = array_column($projected, 'session_date');
                $control = $this->createStudentClassOnCampus(1, [
                    'ScheduleMode' => $mode, 'StartDate' => '2026-11-03', 'EndDate' => '2026-12-01',
                    'week' => 2, 'time' => '23:00:00', 'SessionCount' => 8, 'scheduling_policy' => $policy,
                ]);
                foreach ($dates as $date) $this->createClassSession((int) $control->ID, $date, 'scheduled');
                $controlRows = fn () => DB::table('ClassSession')->where('StudentClassID', $control->ID)->orderBy('id')
                    ->get(['id', 'SessionDate', 'StartTime', 'EndTime', 'Status'])->map(fn ($r) => (array) $r)->all();
                $controlBefore = $controlRows();
                $controlIndex = $this->getJson("/api/v1/class-sessions?branch_id=1&student_class_id={$control->ID}&start=2026-11-03&end=2026-12-01", $headers);
                $controlIndex->assertOk();
                $this->assertSame($controlBefore, $controlRows(), "{$mode}: control read must not add materialized rows");
                $controlProjectedDates = array_column($controlIndex->json("projected.by_class.{$control->ID}") ?? [], 'session_date');
                $this->assertSame($controlProjectedDates, $projectedDates, "{$mode}: projection must match unpaused control");
                $payload = fn ($id) => [
                    'branch_id' => 1, 'range_start' => '2026-11-03', 'range_end' => '2026-12-01',
                    'courses' => [['id' => $id, 'first_class_date' => '2026-11-03', 'sessions_purchased' => 8, 'days_of_week' => [2]]],
                ];
                $datesResult = $this->postJson('/api/v1/student-classes/session-dates', $payload($sc->ID), $headers);
                $controlDates = $this->postJson('/api/v1/student-classes/session-dates', $payload($control->ID), $headers);
                $datesResult->assertOk(); $controlDates->assertOk();
                $datesProjected = array_column($datesResult->json("{$sc->ID}.projected") ?? [], 'session_date');
                $controlDatesProjected = array_column($controlDates->json("{$control->ID}.projected") ?? [], 'session_date');
                $this->assertSame($controlDatesProjected, $datesProjected, "{$mode}: session-dates must match unpaused control");
                $projection = $this->getJson("/api/v1/class-sessions/projection?branch_id=1&student_class_id={$sc->ID}&start=2026-11-03&end=2026-12-01", $headers);
                $controlProjection = $this->getJson("/api/v1/class-sessions/projection?branch_id=1&student_class_id={$control->ID}&start=2026-11-03&end=2026-12-01", $headers);
                $projection->assertOk(); $controlProjection->assertOk();
                $projectionDates = array_column($projection->json("projected.by_class.{$sc->ID}") ?? [], 'session_date');
                $controlProjectionDates = array_column($controlProjection->json("projected.by_class.{$control->ID}") ?? [], 'session_date');
                $this->assertSame($controlProjectionDates, $projectionDates, "{$mode}: projection endpoint must match unpaused control");
                $this->assertSame($before, $rows(), "{$mode}: session-dates must not add materialized rows");
                $this->assertSame($controlBefore, $controlRows(), "{$mode}: control session-dates must not add materialized rows");
                if ($policy === 'manual_occurrence') {
                    $this->assertSame([], $projectedDates);
                    $this->assertSame([], $datesProjected);
                } else {
                    $this->assertSame(['2026-11-10', '2026-11-24'], array_values(array_diff($datesProjected, $dates)));
                }
            }
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public static function manualModes(): array
    {
        return [['count'], ['date']];
    }

    /** @dataProvider manualModes */
    public function test_manual_occurrences_never_produce_virtual_recurring_dates(string $mode): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-27 08:00:00', 'Asia/Taipei'));
        try {
            $token = $this->createDirectorToken([1]);
            $headers = ['Authorization' => "Bearer {$token}"];
            $sc = $this->createStudentClassOnCampus(1, [
                'ScheduleMode' => $mode, 'scheduling_policy' => 'manual_occurrence',
                'StartDate' => '2026-11-03', 'EndDate' => '2026-12-01', 'week' => 2,
                'time' => '23:00:00', 'SessionCount' => 8,
            ]);
            $this->assertSame('manual_occurrence', DB::table('StudentClass')->where('ID', $sc->ID)->value('scheduling_policy'));
            foreach (['2026-11-03', '2026-11-17', '2026-12-01'] as $date) $this->createClassSession((int) $sc->ID, $date, 'scheduled');
            $rows = fn () => DB::table('ClassSession')->where('StudentClassID', $sc->ID)->orderBy('id')
                ->get(['id', 'SessionDate', 'StartTime', 'EndTime', 'Status'])->map(fn ($r) => (array) $r)->all();
            $before = $rows();
            $query = "branch_id=1&student_class_id={$sc->ID}&start=2026-11-03&end=2026-12-01";
            $index = $this->getJson('/api/v1/class-sessions?' . $query, $headers);
            $projection = $this->getJson('/api/v1/class-sessions/projection?' . $query, $headers);
            $dates = $this->postJson('/api/v1/student-classes/session-dates', [
                'branch_id' => 1, 'range_start' => '2026-11-03', 'range_end' => '2026-12-01',
                'courses' => [['id' => $sc->ID, 'first_class_date' => '2026-11-03', 'sessions_purchased' => 8, 'days_of_week' => [2]]],
            ], $headers);
            $getDates = $this->getJson('/api/v1/student-classes/session-dates?branch_id=1&range_start=2026-11-03&range_end=2026-12-01', $headers);
            $index->assertOk(); $projection->assertOk(); $dates->assertOk(); $getDates->assertOk();
            $getSplit = $getDates->json("{$sc->ID}");
            $this->assertIsArray($getSplit);
            $this->assertArrayHasKey('projected', $getSplit);
            $this->assertArrayHasKey('materialized', $getSplit);
            $this->assertSame([], $getSplit['projected']);
            $this->assertCount(3, $getSplit['materialized']);
            $this->assertSame($before, $rows());
            $actual = [
                'index' => array_column($index->json("projected.by_class.{$sc->ID}") ?? [], 'session_date'),
                'projection' => array_column($projection->json("projected.by_class.{$sc->ID}") ?? [], 'session_date'),
                'session-dates' => array_column($dates->json("{$sc->ID}.projected") ?? [], 'session_date'),
            ];
            $this->assertSame(['index' => [], 'projection' => [], 'session-dates' => []], $actual);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    /** @dataProvider manualModes */
    public function test_manual_course_with_no_rows_does_not_project_dates(string $mode): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-27 08:00:00', 'Asia/Taipei'));
        try {
            $token = $this->createDirectorToken([1]);
            $headers = ['Authorization' => "Bearer {$token}"];
            $sc = $this->createStudentClassOnCampus(1, [
                'ScheduleMode' => $mode, 'scheduling_policy' => 'manual_occurrence',
                'StartDate' => '2026-11-03', 'EndDate' => '2026-12-01', 'week' => 2,
                'time' => '23:00:00', 'SessionCount' => 8,
            ]);
            $request = ['branch_id' => 1, 'range_start' => '2026-11-03', 'range_end' => '2026-12-01',
                'courses' => [['id' => $sc->ID, 'first_class_date' => '2026-11-03', 'sessions_purchased' => 8, 'days_of_week' => [2]]]];
            $response = $this->postJson('/api/v1/student-classes/session-dates', $request, $headers);
            $response->assertOk();
            $split = $response->json("{$sc->ID}");
            $this->assertIsArray($split);
            $this->assertArrayHasKey('materialized', $split); $this->assertArrayHasKey('projected', $split);
            $this->assertSame([], $split['materialized']); $this->assertSame([], $split['projected']);
            $getDates = $this->getJson('/api/v1/student-classes/session-dates?branch_id=1&range_start=2026-11-03&range_end=2026-12-01', $headers);
            $getDates->assertOk();
            $getSplit = $getDates->json("{$sc->ID}");
            $this->assertIsArray($getSplit); $this->assertArrayHasKey('projected', $getSplit);
            $this->assertSame([], $getSplit['projected']);
            $query = "branch_id=1&student_class_id={$sc->ID}&start=2026-11-03&end=2026-12-01";
            $index = $this->getJson('/api/v1/class-sessions?' . $query, $headers);
            $projection = $this->getJson('/api/v1/class-sessions/projection?' . $query, $headers);
            $index->assertOk(); $projection->assertOk();
            $this->assertSame([], $index->json('data'));
            $this->assertSame([], $projection->json('data'));
            $this->assertEmpty($index->json("projected.by_class.{$sc->ID}"));
            $this->assertEmpty($projection->json("projected.by_class.{$sc->ID}"));
            $this->assertSame(0, DB::table('ClassSession')->where('StudentClassID', $sc->ID)->count());
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_legacy_manual_package_member_is_not_projected_when_siblings_are_loaded(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-27 08:00:00', 'Asia/Taipei'));
        try {
            $token = $this->createDirectorToken([1]);
            $headers = ['Authorization' => "Bearer {$token}"];
            $manual = $this->createStudentClassOnCampus(1, [
                'ScheduleMode' => 'count', 'scheduling_policy' => 'manual_occurrence',
                'StartDate' => '2026-11-03', 'EndDate' => '2026-12-01', 'week' => 2,
                'time' => '23:00:00', 'SessionCount' => 8,
            ]);
            $package = \App\Models\CoursePackage::create([
                'student_id' => $manual->StudentID, 'campus_id' => 1, 'name' => 'Synthetic package',
                'billing_mode' => 'session', 'total_sessions' => 16, 'remaining_sessions' => 16,
                'used_sessions' => 0, 'rate' => 500, 'rate_unit' => 'session',
                'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true,
            ]);
            $manual->PackageID = $package->id; $manual->save();
            $auto = $manual->replicate(); $auto->scheduling_policy = 'auto_recurrence'; $auto->SubjectID = 2; $auto->save();
            foreach (['2026-11-03', '2026-11-17', '2026-12-01'] as $date) {
                $this->createClassSession((int) $manual->ID, $date, 'scheduled');
                $this->createClassSession((int) $auto->ID, $date, 'scheduled');
            }
            $before = DB::table('ClassSession')->orderBy('id')->get(['id', 'StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status'])->map(fn ($r) => (array) $r)->all();
            $body = fn ($ids) => ['branch_id' => 1, 'range_start' => '2026-11-03', 'range_end' => '2026-12-01',
                'courses' => array_map(fn ($id) => ['id' => $id, 'first_class_date' => '2026-11-03', 'sessions_purchased' => 8, 'days_of_week' => [2]], $ids)];
            $one = $this->postJson('/api/v1/student-classes/session-dates', $body([$manual->ID]), $headers);
            $one->assertOk();
            $manualSplit = $one->json("{$manual->ID}");
            $this->assertIsArray($manualSplit); $this->assertCount(3, $manualSplit['materialized']);
            $this->assertSame([], $manualSplit['projected']);
            $both = $this->postJson('/api/v1/student-classes/session-dates', $body([$manual->ID, $auto->ID]), $headers);
            $both->assertOk();
            $this->assertSame([], $both->json("{$manual->ID}.projected"));
            $autoProjected = array_column($both->json("{$auto->ID}.projected") ?? [], 'session_date');
            $this->assertContains('2026-11-10', $autoProjected);
            $after = DB::table('ClassSession')->orderBy('id')->get(['id', 'StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status'])->map(fn ($r) => (array) $r)->all();
            $this->assertSame($before, $after);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_manual_projection_guard_keeps_attended_cancelled_and_scheduled_rows(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-27 08:00:00', 'Asia/Taipei'));
        try {
            $token = $this->createDirectorToken([1]);
            $headers = ['Authorization' => "Bearer {$token}"];
            $sc = $this->createStudentClassOnCampus(1, [
                'ScheduleMode' => 'date', 'scheduling_policy' => 'manual_occurrence',
                'StartDate' => '2026-11-03', 'EndDate' => '2026-12-01', 'week' => 2,
                'time' => '23:00:00', 'SessionCount' => 8,
            ]);
            $this->createClassSession((int) $sc->ID, '2026-11-03', 'attended');
            $this->createClassSession((int) $sc->ID, '2026-11-17', 'cancelled');
            $this->createClassSession((int) $sc->ID, '2026-12-01', 'scheduled');
            $rows = fn () => DB::table('ClassSession')->where('StudentClassID', $sc->ID)->orderBy('id')
                ->get(['id', 'SessionDate', 'StartTime', 'EndTime', 'Status'])->map(fn ($r) => (array) $r)->all();
            $before = $rows();
            $result = $this->postJson('/api/v1/student-classes/session-dates', [
                'branch_id' => 1, 'range_start' => '2026-11-03', 'range_end' => '2026-12-01',
                'courses' => [['id' => $sc->ID, 'first_class_date' => '2026-11-03', 'sessions_purchased' => 8, 'days_of_week' => [2]]],
            ], $headers);
            $result->assertOk();
            $split = $result->json("{$sc->ID}");
            $this->assertIsArray($split);
            $this->assertSame([], $split['projected']);
            $this->assertSame(['attended', 'scheduled'], array_column($split['materialized'], 'status'));
            $this->assertSame([$before[0]['id'], $before[2]['id']], array_column($split['materialized'], 'id'));
            $this->assertSame($before, $rows());
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_unknown_policy_preserves_existing_projection_behavior(): void
    {
        $reader = app(\App\Services\SessionProjectionReadService::class);
        $unknown = new StudentClass(); $unknown->setRawAttributes(['ID' => 100]); $unknown->setRelation('student', null);
        $auto = new StudentClass(); $auto->setRawAttributes(['ID' => 101, 'scheduling_policy' => 'auto_recurrence']); $auto->setRelation('student', null);
        $manual = new StudentClass(); $manual->setRawAttributes(['ID' => 102, 'scheduling_policy' => 'manual_occurrence']); $manual->setRelation('student', null);
        $dates = ['2026-11-10'];
        $this->assertCount(1, $reader->buildProjectedFromEffectiveDates(99, $dates, [], null));
        $this->assertCount(1, $reader->buildProjectedFromEffectiveDates(100, $dates, [], $unknown));
        $this->assertCount(1, $reader->buildProjectedFromEffectiveDates(101, $dates, [], $auto));
        $this->assertSame([], $reader->buildProjectedFromEffectiveDates(102, $dates, [], $manual));
    }

    public function test_manual_projection_read_remains_forbidden_across_campuses(): void
    {
        $token = $this->createDirectorToken([2]);
        $course = $this->createStudentClassOnCampus(1, ['scheduling_policy' => 'manual_occurrence']);
        $headers = ['Authorization' => "Bearer {$token}"];
        $body = ['branch_id' => 1, 'range_start' => '2026-11-03', 'range_end' => '2026-12-01',
            'courses' => [['id' => $course->ID, 'first_class_date' => '2026-11-03', 'sessions_purchased' => 8, 'days_of_week' => [2]]]];
        $this->postJson('/api/v1/student-classes/session-dates', $body, $headers)->assertForbidden();
        $this->getJson('/api/v1/student-classes/session-dates?branch_id=1', $headers)->assertForbidden();
        $this->getJson('/api/v1/class-sessions/projection?branch_id=1&start=2026-11-03&end=2026-12-01', $headers)->assertForbidden();
        $this->assertSame(0, DB::table('ClassSession')->where('StudentClassID', $course->ID)->count());
    }

    public function test_owning_teacher_sees_existing_manual_rows_without_projection(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-27 08:00:00', 'Asia/Taipei'));
        try {
            $token = $this->createTeacherToken([1]);
            $sc = $this->createStudentClassOnCampus(1, [
                'TeacherID' => $this->lastTeacherUserId, 'ScheduleMode' => 'date', 'scheduling_policy' => 'manual_occurrence',
                'StartDate' => '2026-11-03', 'EndDate' => '2026-12-01', 'week' => 2, 'time' => '23:00:00', 'SessionCount' => 8,
            ]);
            $id = $this->createClassSession((int) $sc->ID, '2026-11-03', 'scheduled');
            $headers = ['Authorization' => "Bearer {$token}"];
            $query = "branch_id=1&student_class_id={$sc->ID}&start=2026-11-03&end=2026-12-01";
            $index = $this->getJson('/api/v1/class-sessions?' . $query, $headers);
            $projection = $this->getJson('/api/v1/class-sessions/projection?' . $query, $headers);
            $index->assertOk(); $projection->assertOk();
            $this->assertEmpty($index->json("projected.by_class.{$sc->ID}"));
            $this->assertEmpty($projection->json("projected.by_class.{$sc->ID}"));
            $this->assertSame([$id], array_column($index->json('data'), 'id'));
            $dates = $this->postJson('/api/v1/student-classes/session-dates', [
                'branch_id' => 1, 'range_start' => '2026-11-03', 'range_end' => '2026-12-01',
                'courses' => [['id' => $sc->ID, 'first_class_date' => '2026-11-03', 'sessions_purchased' => 8, 'days_of_week' => [2]]],
            ], $headers);
            $dates->assertOk();
            $this->assertSame([], $dates->json("{$sc->ID}.projected"));
            $this->assertSame([$id], array_column($dates->json("{$sc->ID}.materialized"), 'id'));
            $this->assertSame(1, DB::table('ClassSession')->where('StudentClassID', $sc->ID)->count());
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    private int $lastTeacherUserId = 0;

    private function createTeacherToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'teach-pauseauthz-' . uniqid() . '@test.com',
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0912345679',
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        }
        $this->lastTeacherUserId = (int) $user->id;
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-pauseauthz-' . uniqid() . '@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function createStudentClassOnCampus(int $campusId, array $overrides = []): StudentClass
    {
        $student = Student::create([
            'name' => '暫停授權測試生-' . uniqid(),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }

    private function createClassSession(int $courseId, string $date, string $status): int
    {
        return DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => $status,
        ]);
    }
}
