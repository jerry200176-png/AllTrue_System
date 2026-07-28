<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * F1 / #556 gap: atomic 調課 (reschedule-session) must mark IsContractException
 * when the new slot differs from StudentClass week/time, otherwise
 * force_partial_rebuild / syncFutureScheduledSessionTimes snaps the session
 * back to the contract clock — calendar "reverts after refresh/save".
 *
 * Symptom family: 新莊 巫宗育 週五 20:00→15:00 調課後重整回原時段.
 */
class RescheduleMarksContractExceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_atomic_same_day_reschedule_marks_contract_exception_and_survives_rebuild(): void
    {
        if (!Schema::hasColumn('ClassSession', 'IsContractException')) {
            $this->markTestSkipped('IsContractException column missing');
        }

        Carbon::setTestNow(Carbon::parse('2026-07-24 10:00:00')); // Friday
        try {
            [$token, $courseId, $session] = $this->seedFridayContractSession();

            $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->postJson('/api/v1/learning-records/reschedule-session', [
                'student_class_id' => $courseId,
                'old_date' => '2026-07-31',
                'old_start_time' => '20:00',
                'new_date' => '2026-07-31',
                'start_time' => '15:00',
                'end_time' => '17:00',
                'ensure_schedule_exception' => true,
            ])->assertOk()->assertJson(['committed' => true]);

            $session->refresh();
            $this->assertSame('15:00', substr((string) $session->StartTime, 0, 5));
            $this->assertTrue(
                (bool) $session->IsContractException,
                'Atomic 調課到非契約時段必須標記 IsContractException（與 PATCH class-sessions 對齊）'
            );

            // Course edit → save with force_partial_rebuild (堂次偏移「同步」路徑)
            $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->putJson("/api/v1/student-classes/{$courseId}", [
                'subject' => 'Math',
                'class_type' => 'one_on_one',
                'rate_per_30min' => 500,
                'duration_hours' => 2,
                'payment_type' => 'session',
                'sessions_purchased' => 8,
                'days_of_week' => [5],
                'day_time_slots' => [['day' => 5, 'start_time' => '20:00', 'duration_hours' => 2]],
                'start_time' => '20:00',
                'first_class_date' => '2026-07-03',
                'force_partial_rebuild' => true,
            ])->assertOk();

            $session->refresh();
            $this->assertSame(
                '15:00',
                substr((string) $session->StartTime, 0, 5),
                '調課後的 15:00 不可被 force_partial_rebuild 拉回契約 20:00'
            );
            $this->assertTrue((bool) $session->IsContractException);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @return array{0:string,1:int,2:ClassSession} */
    private function seedFridayContractSession(): array
    {
        $director = User::create([
            'LoginName' => 'rs-ce-' . uniqid() . '@example.com',
            'Name' => '調課契約例外測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '090' . random_int(1000000, 9999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $director->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $director->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        $teacher = User::create([
            'LoginName' => 'rs-ce-t-' . uniqid() . '@example.com',
            'Name' => '老師',
            'PSW' => 'x',
            'type' => 'T',
            'phone' => '091' . random_int(1000000, 9999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        $student = Student::create([
            'name' => '巫宗育測試',
            'CampusID' => 1,
            'ClassID' => 1,
            'SchoolName' => 'Test',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacher->id,
            'ClassType' => 'one_on_one',
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-07-03',
            'TotalHours' => 20,
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 6,
            'UsedSessions' => 2,
            'Charge' => 1600,
            'Pay' => 16000,
            'Paid' => 0,
            'Rate' => 800,
            'Stop' => 0,
            'MDate' => now(),
            'ScheduleMode' => 'count',
            'week' => 5,
            'time' => '20:00:00',
            'Memo' => '',
        ]);

        // Past attended session → immutable history so rebuild uses syncFuture path
        ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-07-17',
            'StartTime' => '20:00:00',
            'EndTime' => '22:00:00',
            'Status' => 'attended',
            'IsContractException' => 0,
        ]);

        $future = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-07-31', // Friday
            'StartTime' => '20:00:00',
            'EndTime' => '22:00:00',
            'Status' => 'scheduled',
            'IsContractException' => 0,
        ]);

        return [$token, (int) $course->ID, $future];
    }
}
