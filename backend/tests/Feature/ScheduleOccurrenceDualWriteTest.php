<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\ScheduleChangeLog;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TD-076 Phase 2: dual-write behind FEATURE_SCHEDULE_OCCURRENCE_V2 (default off).
 * Chain inserts remain; readers are unchanged.
 */
class ScheduleOccurrenceDualWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('FEATURE_SCHEDULE_OCCURRENCE_V2');
        unset($_ENV['FEATURE_SCHEDULE_OCCURRENCE_V2'], $_SERVER['FEATURE_SCHEDULE_OCCURRENCE_V2']);
        parent::tearDown();
    }

    public function test_flag_off_leaves_identity_columns_null(): void
    {
        $this->setOccurrenceFlag(false);
        [$token, $courseId] = $this->seedPlainSession();

        $this->postReschedule($token, $this->firstMovePayload($courseId))->assertOk();

        $destination = Schedule::where('student_course_id', $courseId)
            ->where('status', 'scheduled')
            ->first();
        $this->assertNotNull($destination);
        $this->assertNull($destination->original_schedule_date);
        $this->assertNull($destination->original_start_time);
        $this->assertSame(0, ScheduleChangeLog::query()->count());
        $this->assertSame(2, Schedule::where('student_course_id', $courseId)->count());
    }

    public function test_flag_on_freezes_identity_across_second_reschedule_and_appends_one_log_per_execute(): void
    {
        $this->setOccurrenceFlag(true);
        [$token, $courseId] = $this->seedPlainSession();

        $this->postReschedule($token, $this->firstMovePayload($courseId))->assertOk();

        $firstDestination = Schedule::where('student_course_id', $courseId)
            ->where('status', 'scheduled')
            ->whereDate('schedule_date', '2026-08-02')
            ->first();
        $this->assertNotNull($firstDestination);
        $this->assertSame('2026-08-01', substr((string) $firstDestination->original_schedule_date, 0, 10));
        $this->assertSame('10:00', substr((string) $firstDestination->original_start_time, 0, 5));
        $this->assertSame(1, ScheduleChangeLog::query()->count());

        $this->postReschedule($token, [
            'student_class_id' => $courseId,
            'old_date' => '2026-08-02',
            'old_start_time' => '17:00',
            'new_date' => '2026-08-03',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'ensure_schedule_exception' => true,
        ])->assertOk();

        $live = Schedule::where('student_course_id', $courseId)
            ->where('status', 'scheduled')
            ->whereDate('schedule_date', '2026-08-03')
            ->first();
        $this->assertNotNull($live);
        $this->assertSame('2026-08-01', substr((string) $live->original_schedule_date, 0, 10));
        $this->assertSame('10:00', substr((string) $live->original_start_time, 0, 5));
        $this->assertSame(2, ScheduleChangeLog::query()->count());
        // GitHub #2001 fix: the old target row at 2026-08-02 is reused (flipped to
        // 'rescheduled') as the new anchor instead of being left behind as an orphan
        // and having a disconnected duplicate anchor inserted alongside it — so this
        // is 3 rows (2026-08-01 rescheduled, 2026-08-02 rescheduled, 2026-08-03
        // scheduled), not 4.
        $this->assertGreaterThanOrEqual(3, Schedule::where('student_course_id', $courseId)->count());

        $retry = $this->postReschedule($token, [
            'student_class_id' => $courseId,
            'old_date' => '2026-08-02',
            'old_start_time' => '17:00',
            'new_date' => '2026-08-03',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'ensure_schedule_exception' => true,
        ])->assertOk();
        $this->assertTrue((bool) $retry->json('idempotent_replay'));
        $this->assertSame(2, ScheduleChangeLog::query()->count());
    }

    private function setOccurrenceFlag(bool $on): void
    {
        $value = $on ? 'true' : 'false';
        putenv('FEATURE_SCHEDULE_OCCURRENCE_V2=' . $value);
        $_ENV['FEATURE_SCHEDULE_OCCURRENCE_V2'] = $value;
        $_SERVER['FEATURE_SCHEDULE_OCCURRENCE_V2'] = $value;
    }

    /** @return array<string, mixed> */
    private function firstMovePayload(int $courseId): array
    {
        return [
            'student_class_id' => $courseId,
            'old_date' => '2026-08-01',
            'old_start_time' => '10:00',
            'new_date' => '2026-08-02',
            'start_time' => '17:00',
            'end_time' => '19:00',
            'ensure_schedule_exception' => true,
        ];
    }

    private function postReschedule(string $token, array $payload)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/reschedule-session', $payload);
    }

    /** @return array{0: string, 1: int} */
    private function seedPlainSession(): array
    {
        $director = User::create([
            'LoginName' => 'dir-' . uniqid() . '@example.com', 'Name' => '主任', 'PSW' => 'x',
            'type' => 'A', 'phone' => '090' . random_int(1000000, 9999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        $teacher = User::create([
            'LoginName' => 't-' . uniqid() . '@example.com', 'Name' => '老師', 'PSW' => 'x',
            'type' => 'T', 'phone' => '091' . random_int(1000000, 9999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => '測試生-' . uniqid(), 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $teacher->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-01-01', 'TotalHours' => 20,
            'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 10, 'UsedSessions' => 0,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-08-01',
            'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'scheduled',
        ]);

        return [$token, (int) $sc->ID];
    }
}
