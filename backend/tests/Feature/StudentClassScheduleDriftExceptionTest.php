<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentClassScheduleDriftExceptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * add-session on a non-contract weekday marks IsContractException=1.
     */
    public function test_add_session_on_non_contract_weekday_sets_exception_flag(): void
    {
        Carbon::setTestNow('2026-04-14 10:00:00'); // Tuesday
        try {
            [$token, $sc] = $this->setupCourse(['week' => 5, 'time' => '17:00:00']); // contract = Friday

            $res = $this->withAuth($token)->postJson(
                "/api/v1/student-classes/{$sc->ID}/add-session",
                ['session_date' => '2026-04-14', 'start_time' => '18:00', 'duration_minutes' => 120]
            );

            $res->assertStatus(201);
            $csId = $res->json('class_session_id');
            $cs = ClassSession::find($csId);
            $this->assertTrue((bool) $cs->IsContractException, 'Non-contract weekday session must be flagged');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * add-session on a contract weekday+time clears the exception flag.
     */
    public function test_add_session_on_contract_weekday_clears_exception_flag(): void
    {
        Carbon::setTestNow('2026-04-10 10:00:00'); // Thursday
        try {
            [$token, $sc] = $this->setupCourse(['week' => 5, 'time' => '17:00:00']); // Friday

            $res = $this->withAuth($token)->postJson(
                "/api/v1/student-classes/{$sc->ID}/add-session",
                ['session_date' => '2026-04-17', 'start_time' => '17:00', 'duration_minutes' => 120]
            );

            $res->assertStatus(201);
            $csId = $res->json('class_session_id');
            $cs = ClassSession::find($csId);
            $this->assertFalse((bool) $cs->IsContractException, 'Contract-matching session must NOT be flagged');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Exception-only sessions → schedule_drift=false, contract_exception_count > 0.
     */
    public function test_index_no_drift_when_only_exception_sessions(): void
    {
        Carbon::setTestNow('2026-04-14 10:00:00');
        try {
            [$token, $sc] = $this->setupCourse(['week' => 5, 'time' => '17:00:00']);

            // One contract session (Friday)
            ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => '2026-04-17',
                'StartTime' => '17:00:00',
                'EndTime' => '19:00:00',
                'Status' => 'scheduled',
                'IsContractException' => false,
            ]);
            // One exception session (Tuesday)
            ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => '2026-04-14',
                'StartTime' => '18:00:00',
                'EndTime' => '20:00:00',
                'Status' => 'scheduled',
                'IsContractException' => true,
            ]);

            $res = $this->withAuth($token)->getJson(
                "/api/v1/student-classes?branch_id=1&student_id={$sc->StudentID}"
            );
            $res->assertOk();
            $course = collect($res->json('data'))->firstWhere('id', $sc->ID);

            $this->assertFalse($course['schedule_drift'] ?? true, 'Only exceptions present — drift must be false');
            $this->assertSame(1, $course['contract_exception_count'] ?? 0, 'Should report 1 exception');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_class_sessions_api_exposes_contract_exception_for_quota_display(): void
    {
        Carbon::setTestNow('2026-04-14 10:00:00');
        try {
            [$token, $sc] = $this->setupCourse(['week' => 5, 'time' => '17:00:00']);

            $normal = ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => '2026-04-17',
                'StartTime' => '17:00:00',
                'EndTime' => '19:00:00',
                'Status' => 'scheduled',
                'IsContractException' => false,
            ]);
            $exception = ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => '2026-04-14',
                'StartTime' => '18:00:00',
                'EndTime' => '20:00:00',
                'Status' => 'scheduled',
                'IsContractException' => true,
            ]);

            $res = $this->withAuth($token)->getJson(
                "/api/v1/class-sessions?student_class_id={$sc->ID}&branch_id=1"
            );
            $res->assertOk();

            $byId = collect($res->json('data'))->keyBy('id');
            $this->assertFalse((bool) $byId[$normal->id]['is_contract_exception']);
            $this->assertTrue((bool) $byId[$exception->id]['is_contract_exception']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Exception + real drift → schedule_drift=true (exceptions must not mask real drift).
     */
    public function test_index_drift_true_when_exception_and_real_drift_coexist(): void
    {
        Carbon::setTestNow('2026-04-14 10:00:00');
        try {
            [$token, $sc] = $this->setupCourse(['week' => 5, 'time' => '17:00:00']);

            // Exception session (Tuesday, legitimate add-on)
            ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => '2026-04-14',
                'StartTime' => '18:00:00',
                'EndTime' => '20:00:00',
                'Status' => 'scheduled',
                'IsContractException' => true,
            ]);
            // Non-exception but wrong time (Friday 10:00 instead of 17:00) → real drift
            ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => '2026-04-17',
                'StartTime' => '10:00:00',
                'EndTime' => '12:00:00',
                'Status' => 'scheduled',
                'IsContractException' => false,
            ]);

            $res = $this->withAuth($token)->getJson(
                "/api/v1/student-classes?branch_id=1&student_id={$sc->StudentID}"
            );
            $res->assertOk();
            $course = collect($res->json('data'))->firstWhere('id', $sc->ID);

            $this->assertTrue($course['schedule_drift'] ?? false, 'Real drift must not be masked by exceptions');
            $this->assertSame(1, $course['contract_exception_count'] ?? 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * force_partial_rebuild does NOT remap exception sessions; non-exception sessions are synced.
     */
    public function test_force_partial_rebuild_preserves_exception_sessions(): void
    {
        Carbon::setTestNow('2026-04-14 10:00:00');
        try {
            [$token, $sc] = $this->setupCourse([
                'week' => 5, 'time' => '17:00:00',
                'SessionCount' => 8, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            ]);

            // Exception session on Tuesday — must survive rebuild
            $exception = ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => '2026-04-14',
                'StartTime' => '18:00:00',
                'EndTime' => '20:00:00',
                'Status' => 'scheduled',
                'IsContractException' => true,
            ]);
            // Non-exception on Friday with wrong time — should be fixed
            $drifted = ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => '2026-04-17',
                'StartTime' => '10:00:00',
                'EndTime' => '12:00:00',
                'Status' => 'scheduled',
                'IsContractException' => false,
            ]);

            $res = $this->withAuth($token)->putJson(
                "/api/v1/student-classes/{$sc->ID}",
                [
                    'subject' => 'Math',
                    'class_type' => 'one_on_one',
                    'rate_per_30min' => 500,
                    'duration_hours' => 2,
                    'payment_type' => 'session',
                    'sessions_purchased' => 8,
                    'days_of_week' => [5],
                    'day_time_slots' => [['day' => 5, 'start_time' => '17:00', 'duration_hours' => 2]],
                    'start_time' => '17:00',
                    'first_class_date' => '2026-04-04',
                    'force_partial_rebuild' => true,
                ]
            );
            $res->assertOk();

            $exception->refresh();
            $this->assertSame('2026-04-14', Carbon::parse($exception->SessionDate)->toDateString(),
                'Exception session date must be preserved');
            $this->assertStringStartsWith('18:00', (string) $exception->StartTime,
                'Exception session time must be preserved');

            $drifted->refresh();
            $this->assertStringStartsWith('17:00', (string) $drifted->StartTime,
                'Non-exception session should be synced to contract time');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * Locked sessions (with approved LR) are not affected by the exception flag —
     * the locking mechanism still takes priority.
     */
    public function test_locked_session_not_modified_regardless_of_exception(): void
    {
        Carbon::setTestNow('2026-04-14 10:00:00');
        try {
            [$token, $sc] = $this->setupCourse(['week' => 5, 'time' => '17:00:00']);

            $cs = ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => '2026-04-17',
                'StartTime' => '17:00:00',
                'EndTime' => '19:00:00',
                'Status' => 'scheduled',
                'IsContractException' => false,
            ]);

            LearningRecord::create([
                'StudentClassID' => $sc->ID,
                'ClassSessionID' => $cs->id,
                'TeacherID' => 99,
                'Subject' => 'Math',
                'Content' => 'test',
                'SessionDate' => '2026-04-17',
                'StartTime' => '17:00:00',
                'EndTime' => '19:00:00',
                'Status' => 'approved',
                'ApprovedBy' => 1,
                'ApprovedAt' => now(),
            ]);

            // Try add-session on same slot — should be blocked by lock, not by exception
            $res = $this->withAuth($token)->postJson(
                "/api/v1/student-classes/{$sc->ID}/add-session",
                ['session_date' => '2026-04-17', 'start_time' => '17:00', 'duration_minutes' => 120]
            );

            $this->assertSame(409, $res->status(), 'Locked session must reject duplicate add-session');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * A non-exception, non-locked session on a non-contract weekday without the flag
     * still triggers schedule_drift=true (no false negatives after this change).
     */
    public function test_unflagged_non_contract_session_still_triggers_drift(): void
    {
        Carbon::setTestNow('2026-04-14 10:00:00');
        try {
            [$token, $sc] = $this->setupCourse(['week' => 5, 'time' => '17:00:00']);

            ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => '2026-04-16', // Wednesday, not in contract
                'StartTime' => '10:00:00',
                'EndTime' => '12:00:00',
                'Status' => 'scheduled',
                'IsContractException' => false,
            ]);

            $res = $this->withAuth($token)->getJson(
                "/api/v1/student-classes?branch_id=1&student_id={$sc->StudentID}"
            );
            $res->assertOk();
            $course = collect($res->json('data'))->firstWhere('id', $sc->ID);
            $this->assertTrue($course['schedule_drift'] ?? false,
                'Unflagged non-contract session must still trigger drift');
            $this->assertSame(0, $course['contract_exception_count'] ?? -1);
        } finally {
            Carbon::setTestNow();
        }
    }

    // --- Helpers ---

    private function setupCourse(array $overrides = []): array
    {
        $token = $this->createDirectorToken([1]);

        $student = Student::create([
            'name' => '例外堂次測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $sc = $this->createStudentClass($student->id, $overrides);
        return [$token, $sc];
    }

    private function withAuth(string $token)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'drift-exc-' . uniqid() . '@example.com',
            'Name' => '堂次例外測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        $defaults = [
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 68,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-04',
            'TotalHours' => 16,
            'Charge' => 20000,
            'Pay' => null,
            'Paid' => 0,
            'Rate' => 2500,
            'RoomID' => '1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'ClassType' => 'one_on_one',
        ];

        return StudentClass::create(array_merge($defaults, $overrides));
    }
}
