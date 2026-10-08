<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * in-app #340/#343: paused (Stop=1) courses get no virtual 預排 dates, and a
 * cancelled ClassSession occupies its date even when its start time differs.
 */
class PausedCourseProjectionTest extends TestCase
{
    use RefreshDatabase;

    private int $campusId = 0;

    // 2026-06-06 and 2026-06-13 are Saturdays (week = 6).
    public function test_index_projection_skips_paused_date_mode_course_but_keeps_real_rows(): void
    {
        [$campus, $token, $courseId] = $this->seed1('date', 0);
        ClassSession::create(['StudentClassID' => $courseId, 'SessionDate' => '2026-06-06',
            'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'attended', 'Note' => '']);
        $active = $this->indexJson($token, $campus);
        $this->assertNotEmpty($active->json("projected.by_class.{$courseId}"), 'control: active course projects');

        DB::table('StudentClass')->where('ID', $courseId)->update(['Stop' => 1]);
        $paused = $this->indexJson($token, $campus);
        $paused->assertOk();
        $this->assertEmpty($paused->json("projected.by_class.{$courseId}") ?? []);
        $this->assertCount(1, $paused->json('data'));
    }

    public function test_session_dates_skips_paused_count_course_but_keeps_real_rows(): void
    {
        [, $token, $courseId] = $this->seed1('count', 1);
        ClassSession::create(['StudentClassID' => $courseId, 'SessionDate' => '2026-06-06',
            'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'attended', 'Note' => '']);

        $payload = $this->sessionDates($token, $courseId);
        $this->assertCount(1, $payload['materialized']);
        $this->assertSame([], $payload['projected']);
    }

    public function test_session_dates_active_course_still_projects(): void
    {
        [, $token, $courseId] = $this->seed1('count', 0);
        $payload = $this->sessionDates($token, $courseId);
        $this->assertNotEmpty($payload['projected']);
    }

    public function test_cancelled_session_with_other_start_time_blocks_projected_chip_on_that_date(): void
    {
        [, $token, $courseId] = $this->seed1('count', 0);
        ClassSession::create(['StudentClassID' => $courseId, 'SessionDate' => '2026-06-13',
            'StartTime' => '15:30', 'EndTime' => '17:30', 'Status' => 'cancelled', 'Note' => '']);

        $payload = $this->sessionDates($token, $courseId);
        $dates = array_column($payload['projected'], 'session_date');
        $this->assertNotEmpty($dates, 'other dates still project');
        $this->assertNotContains('2026-06-13', $dates);
        // The replacement 8th date (2026-08-01) falls past range_end and must not be returned.
        $this->assertNotContains('2026-08-01', $dates);
        $this->assertCount(7, $dates);
    }

    public function test_cancelled_off_pattern_extra_with_lingering_scheduled_row_keeps_contract_total(): void
    {
        [, $token, $courseId] = $this->seed1('count', 0);
        // Off-pattern (Wednesday) make-up: cancelled ClassSession, schedules row still 'scheduled'.
        ClassSession::create(['StudentClassID' => $courseId, 'SessionDate' => '2026-06-10',
            'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'cancelled', 'Note' => '']);
        DB::table('schedules')->insert([
            'student_id' => 0, 'teacher_id' => 0, 'subject' => 'Math', 'day_of_week' => 3,
            'start_time' => '10:00:00', 'end_time' => '12:00:00', 'class_type' => 'one_on_one',
            'status' => 'scheduled', 'type' => 'extra', 'deduction' => 1, 'branch_id' => $this->campusId,
            'schedule_date' => '2026-06-10', 'student_course_id' => $courseId, 'original_schedule_id' => 0,
        ]);

        $payload = $this->sessionDates($token, $courseId, '2026-12-31');
        $dates = array_column($payload['projected'], 'session_date');
        $this->assertNotContains('2026-06-10', $dates);
        $this->assertSame(8, count($dates) + count(array_filter(
            $payload['materialized'],
            fn ($m) => ($m['status'] ?? $m['Status'] ?? '') !== 'cancelled'
        )));
    }

    /** in-app #340: a paused monthly course's 預排 date can be cancelled one at a time, with no money effect. */
    public function test_paused_monthly_course_projected_date_can_be_cancelled_without_billing_effect(): void
    {
        [, $token, $courseId] = $this->seed1('date', 1);
        $before = (array) DB::table('StudentClass')->where('ID', $courseId)->first();

        $res = $this->ensureProjected($token, $courseId, ['cancel' => true]);

        $res->assertOk()->assertJsonPath('created', true)->assertJsonPath('session.status', 'cancelled');
        $row = DB::table('ClassSession')->where('StudentClassID', $courseId)->get();
        $this->assertCount(1, $row);
        $this->assertSame('cancelled', $row[0]->Status);
        $this->assertSame(0, DB::table('session_deduction_ledger')->where('student_class_id', $courseId)->count());
        $this->assertSame(0, DB::table('Invoice')->where('StudentClassID', $courseId)->count());
        $after = (array) DB::table('StudentClass')->where('ID', $courseId)->first();
        foreach (['Charge', 'Paid', 'SessionCount', 'RemainingSessions', 'Stop', 'closed_reason'] as $k) {
            $this->assertSame($before[$k] ?? null, $after[$k] ?? null, $k);
        }
    }

    public function test_paused_course_still_refuses_plain_materialization(): void
    {
        [, $token, $courseId] = $this->seed1('date', 1);
        $this->ensureProjected($token, $courseId)->assertStatus(422);
        $this->assertSame(0, DB::table('ClassSession')->where('StudentClassID', $courseId)->count());
    }

    public function test_closed_paused_course_refuses_cancel_even_with_flag(): void
    {
        [, $token, $courseId] = $this->seed1('date', 1);
        DB::table('StudentClass')->where('ID', $courseId)->update(['closed_reason' => 'completed']);
        $this->ensureProjected($token, $courseId, ['cancel' => true])->assertStatus(422);
        $this->assertSame(0, DB::table('ClassSession')->where('StudentClassID', $courseId)->count());
    }

    public function test_cancel_flag_is_refused_on_an_active_course(): void
    {
        [, $token, $courseId] = $this->seed1('date', 0);
        $this->ensureProjected($token, $courseId, ['cancel' => true])->assertStatus(422);
        $this->assertSame(0, DB::table('ClassSession')->where('StudentClassID', $courseId)->count());
    }

    public function test_cancel_never_touches_an_existing_attended_row(): void
    {
        [, $token, $courseId] = $this->seed1('date', 1);
        ClassSession::create(['StudentClassID' => $courseId, 'SessionDate' => '2026-06-06',
            'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'attended', 'Note' => '']);

        $this->ensureProjected($token, $courseId, ['cancel' => true])
            ->assertStatus(409)->assertJsonPath('code', 'SESSION_ALREADY_EXISTS');
        $this->assertSame('attended', DB::table('ClassSession')->where('StudentClassID', $courseId)->value('Status'));
    }

    private function ensureProjected(string $token, int $courseId, array $extra = [])
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/ensure-projected', array_merge([
                'student_class_id' => $courseId, 'session_date' => '2026-06-06',
                'start_time' => '10:00', 'branch_id' => $this->campusId,
            ], $extra));
    }

    private function sessionDates(string $token, int $courseId, string $rangeEnd = '2026-07-31'): array
    {
        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/student-classes/session-dates', [
                'branch_id' => $this->campusId,
                'range_start' => '2026-06-01',
                'range_end' => $rangeEnd,
                'courses' => [['id' => $courseId, 'first_class_date' => '2026-06-06',
                    'sessions_purchased' => 8, 'days_of_week' => [6]]],
            ]);
        $res->assertOk();

        return $res->json((string) $courseId);
    }

    private function indexJson(string $token, Campus $campus)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/class-sessions/projection?branch_id=' . $campus->id . '&start=2026-06-01&end=2026-06-30');
    }

    /** @return array{0:Campus,1:string,2:int} */
    private function seed1(string $mode, int $stop): array
    {
        $campus = Campus::factory()->create(['name' => '測試分校', 'code' => 'pp' . substr(uniqid(), -6)]);
        $this->campusId = (int) $campus->id;
        $user = User::create(['LoginName' => 'pp-' . uniqid() . '@test.com', 'Name' => '主任', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0911111111', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $student = Student::create(['name' => '學生', 'CampusID' => $campus->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $courseId = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $student->id, 'TeacherID' => $user->id, 'GradeID' => 1, 'SubjectID' => 1,
            'ClassType' => 'one_on_one', 'Charge' => 0, 'Paid' => 0, 'Rate' => 500, 'MDate' => now(),
            'Stop' => $stop, 'ScheduleMode' => $mode, 'SessionCount' => 8, 'SessionDuration' => 120,
            'StartDate' => '2026-06-06', 'EndDate' => '2026-07-31', 'week' => 6, 'time' => '10:00:00',
            'by1' => $user->id, 'Period' => 4, 'TotalHours' => 0,
        ]);

        return [$campus, $token, $courseId];
    }
}
