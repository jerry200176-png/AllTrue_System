<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleLeaveCascadeTest extends TestCase
{
    use RefreshDatabase;

    public function test_leave_marks_target_session_and_cascades_future_sessions_with_end_date_extension(): void
    {
        $token = $this->createDirectorToken([1], 'director-leave-cascade@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-leave-cascade@example.com');
        $student = $this->createStudent(1, '請假遞延測試');

        $confirmedDates = [Carbon::now()->previous(Carbon::WEDNESDAY)->toDateString()];
        $firstWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $futureDates = [];
        for ($index = 0; $index < 7; $index += 1) {
            $futureDates[] = $firstWednesday->copy()->addWeeks($index)->toDateString();
        }
        $courseRes = $this->createCourseViaBatchApi($token, $student->id, $teacherId, [
            'total_classes' => 8,
            'confirmed_dates' => $confirmedDates,
            'future_dates' => $futureDates,
            'days_of_week' => [3],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = (int) ($courseRes->json('ID') ?? $courseRes->json('id') ?? 0);
        if ($courseId <= 0) {
            $courseId = (int) (DB::table('StudentClass')
                ->where('StudentID', $student->id)
                ->where('TeacherID', $teacherId)
                ->max('ID') ?? 0);
        }
        $this->assertTrue($courseId > 0, 'Course ID should be available.');

        $sessions = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate', 'asc')
            ->orderBy('id', 'asc')
            ->get();
        $this->assertCount(8, $sessions);

        $targetLeave = $sessions[6];
        $targetLeaveDate = Carbon::parse($targetLeave->SessionDate)->toDateString();
        $nextSession = $sessions[7];
        $nextSessionOriginalDate = Carbon::parse($nextSession->SessionDate)->toDateString();
        $expectedShiftedDate = Carbon::parse($nextSessionOriginalDate)->addWeek()->toDateString();
        $expectedExtendedDate = Carbon::parse($expectedShiftedDate)->addWeek()->toDateString();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'day_of_week' => 3,
            'start_time' => '16:00',
            'end_time' => '18:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'leave',
            'type' => 'normal',
            'deduction' => 0,
            'branch_id' => 1,
            'schedule_date' => $targetLeaveDate,
            'student_course_id' => $courseId,
        ])->assertCreated();

        $targetLeave->refresh();
        $nextSession->refresh();

        // Session is preserved but marked as leave (not deleted)
        $this->assertDatabaseHas('ClassSession', [
            'id' => (int) $targetLeave->id,
            'Status' => 'leave',
        ]);
        $this->assertSame($expectedShiftedDate, Carbon::parse($nextSession->SessionDate)->toDateString());

        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $courseId,
            'SessionDate' => $expectedExtendedDate,
            'Status' => 'scheduled',
        ]);

        // 8 original + 1 appended = 9 total (leave session kept)
        $totalSessions = ClassSession::where('StudentClassID', $courseId)->count();
        $this->assertSame(9, $totalSessions);

        $course = StudentClass::findOrFail($courseId);
        $this->assertSame($expectedExtendedDate, Carbon::parse($course->EndDate)->toDateString());

        $res->assertJsonStructure([
            'schedule' => ['id'],
            'leave_session_date',
            'extended_end_date',
            'class_sessions',
        ]);
        $this->assertSame(
            $targetLeaveDate,
            (string) ($res->json('leave_session_date') ?? '')
        );
        $this->assertSame($expectedExtendedDate, (string) ($res->json('extended_end_date') ?? ''));
        // 9 sessions returned (leave session included)
        $this->assertCount(9, $res->json('class_sessions') ?? []);
    }

    public function test_bulk_holiday_leave_marks_all_eligible_sessions_in_date_range(): void
    {
        $token = $this->createDirectorToken([1], 'director-bulk-leave@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-bulk-leave@example.com');
        $studentA = $this->createStudent(1, '連假學生A');
        $studentB = $this->createStudent(1, '連假學生B');

        $firstWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $futureDates = [];
        for ($i = 0; $i < 4; $i++) {
            $futureDates[] = $firstWednesday->copy()->addWeeks($i)->toDateString();
        }

        $this->createCourseViaBatchApi($token, $studentA->id, $teacherId, [
            'total_classes' => 4,
            'confirmed_dates' => [],
            'future_dates' => $futureDates,
            'days_of_week' => [3],
            'start_time' => '16:00',
        ])->assertCreated();

        $this->createCourseViaBatchApi($token, $studentB->id, $teacherId, [
            'total_classes' => 4,
            'confirmed_dates' => [],
            'future_dates' => $futureDates,
            'days_of_week' => [3],
            'start_time' => '18:00',
        ])->assertCreated();

        $targetDate = $futureDates[0];

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules/bulk-leave', [
            'branch_id' => 1,
            'start_date' => $targetDate,
            'end_date' => $targetDate,
        ])->assertOk();

        $res->assertJsonStructure([
            'processed_count',
            'skipped_count',
            'skipped',
            'affected_course_ids',
        ]);

        $this->assertSame(2, $res->json('processed_count'));
        $this->assertSame(0, $res->json('skipped_count'));

        $leaveSessions = ClassSession::where('SessionDate', $targetDate)
            ->where('Status', 'leave')
            ->count();
        $this->assertSame(2, $leaveSessions);
    }

    public function test_bulk_leave_skips_sessions_with_approved_learning_records(): void
    {
        $token = $this->createDirectorToken([1], 'director-bulk-skip@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-bulk-skip@example.com');
        $student = $this->createStudent(1, '已核准學生');

        $firstWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $futureDates = [];
        for ($i = 0; $i < 4; $i++) {
            $futureDates[] = $firstWednesday->copy()->addWeeks($i)->toDateString();
        }

        $this->createCourseViaBatchApi($token, $student->id, $teacherId, [
            'total_classes' => 4,
            'confirmed_dates' => [],
            'future_dates' => $futureDates,
            'days_of_week' => [3],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')
            ->where('StudentID', $student->id)
            ->where('TeacherID', $teacherId)
            ->max('ID');

        $targetSession = ClassSession::where('StudentClassID', $courseId)
            ->where('SessionDate', $futureDates[0])
            ->first();
        $this->assertNotNull($targetSession);

        DB::table('LearningRecord')->insert([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $targetSession->id,
            'TeacherID' => $teacherId,
            'Subject' => 'Math',
            'SessionDate' => $futureDates[0],
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Content' => 'test',
            'Status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules/bulk-leave', [
            'branch_id' => 1,
            'start_date' => $futureDates[0],
            'end_date' => $futureDates[0],
        ])->assertOk();

        $this->assertSame(0, $res->json('processed_count'));
        $this->assertSame(1, $res->json('skipped_count'));

        $targetSession->refresh();
        $this->assertNotSame('leave', strtolower($targetSession->Status));
    }

    public function test_bulk_leave_rejects_wrong_campus(): void
    {
        $token = $this->createDirectorToken([2], 'director-wrong-campus@example.com');

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules/bulk-leave', [
            'branch_id' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-01',
        ])->assertForbidden();
    }

    public function test_retro_leave_voids_attendance_and_reverses_deduction(): void
    {
        $token = $this->createDirectorToken([1], 'director-retro@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-retro@example.com');
        $student = $this->createStudent(1, '補請假學生');

        $firstWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $futureDates = [];
        for ($i = 0; $i < 4; $i++) {
            $futureDates[] = $firstWednesday->copy()->addWeeks($i)->toDateString();
        }

        $this->createCourseViaBatchApi($token, $student->id, $teacherId, [
            'total_classes' => 4,
            'confirmed_dates' => [],
            'future_dates' => $futureDates,
            'days_of_week' => [3],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')
            ->where('StudentID', $student->id)
            ->where('TeacherID', $teacherId)
            ->max('ID');
        $this->assertTrue($courseId > 0);

        $course = StudentClass::findOrFail($courseId);
        $targetSession = ClassSession::where('StudentClassID', $courseId)
            ->where('SessionDate', $futureDates[0])
            ->first();
        $this->assertNotNull($targetSession);

        // Simulate attended: mark session and create sign-in + learning record
        $targetSession->Status = 'attended';
        $targetSession->save();

        $signIn = StudentSignIn::create([
            'StudentClassID' => $courseId,
            'StudentID'      => $student->id,
            'TeacherID'      => $teacherId,
            'ClassSessionID' => $targetSession->id,
            'SignInDT'       => $futureDates[0] . ' 16:00:00',
            'Status'         => 'present',
            'CampusID'       => 1,
            'SessionDeducted' => true,
        ]);

        $lr = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $targetSession->id,
            'TeacherID'      => $teacherId,
            'Subject'        => 'Math',
            'SessionDate'    => $futureDates[0],
            'StartTime'      => '16:00',
            'EndTime'        => '18:00',
            'Content'        => 'Test content',
            'Status'         => 'approved',
            'SessionDeducted' => true,
        ]);

        // Seed a deduct ledger entry (as would happen in normal flow)
        SessionDeductionLedger::create([
            'student_class_id' => $courseId,
            'class_session_id' => $targetSession->id,
            'event_type'       => 'deduct',
            'source'           => 'attendance',
        ]);

        $initialRemaining = (int) ($course->fresh()->RemainingSessions ?? 0);

        // Call retro-leave
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/schedules/retro-leave', [
            'student_course_id' => $courseId,
            'session_date'      => $futureDates[0],
            'reason'            => '家長臨時通知',
        ])->assertOk();

        // Session should be leave_adjusted
        $targetSession->refresh();
        $this->assertSame('leave_adjusted', $targetSession->Status);

        // Sign-in voided
        $signIn->refresh();
        $this->assertNotNull($signIn->VoidedAt);
        $this->assertSame('家長臨時通知', $signIn->VoidReason);

        // Learning record voided
        $lr->refresh();
        $this->assertNotNull($lr->VoidedAt);

        // Ledger has a reverse entry
        $this->assertDatabaseHas('session_deduction_ledger', [
            'student_class_id' => $courseId,
            'class_session_id' => $targetSession->id,
            'event_type'       => 'reverse',
            'source'           => 'retro_leave',
        ]);

        // Net deduction = 0 (1 deduct - 1 reverse)
        $this->assertSame(0, SessionDeductionLedger::netCount($courseId));

        // Response structure
        $res->assertJsonStructure([
            'message',
            'leave_session_date',
            'extended_end_date',
            'class_sessions',
        ]);

        // Should have appended one session (4 original + 1 new = 5)
        $totalSessions = ClassSession::where('StudentClassID', $courseId)->count();
        $this->assertSame(5, $totalSessions);
    }

    public function test_retro_leave_teacher_forbidden(): void
    {
        $dirToken = $this->createDirectorToken([1], 'director-retro-perm@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-retro-perm@example.com');
        $teacherToken = $this->createTeacherToken($teacherId, 1);
        $student = $this->createStudent(1, '權限測試學生');

        $firstWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $futureDates = [$firstWednesday->toDateString()];
        for ($i = 1; $i < 4; $i++) {
            $futureDates[] = $firstWednesday->copy()->addWeeks($i)->toDateString();
        }

        $this->createCourseViaBatchApi($dirToken, $student->id, $teacherId, [
            'total_classes' => 4,
            'confirmed_dates' => [],
            'future_dates' => $futureDates,
            'days_of_week' => [3],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')
            ->where('StudentID', $student->id)
            ->max('ID');

        $session = ClassSession::where('StudentClassID', $courseId)
            ->where('SessionDate', $futureDates[0])
            ->first();
        $session->Status = 'attended';
        $session->save();

        $this->withHeaders([
            'Authorization' => "Bearer {$teacherToken}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/schedules/retro-leave', [
            'student_course_id' => $courseId,
            'session_date'      => $futureDates[0],
        ])->assertForbidden();
    }

    public function test_retro_leave_idempotent_no_double_reverse(): void
    {
        $token = $this->createDirectorToken([1], 'director-idem@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-idem@example.com');
        $student = $this->createStudent(1, '冪等測試學生');

        $firstWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $futureDates = [];
        for ($i = 0; $i < 4; $i++) {
            $futureDates[] = $firstWednesday->copy()->addWeeks($i)->toDateString();
        }

        $this->createCourseViaBatchApi($token, $student->id, $teacherId, [
            'total_classes' => 4,
            'confirmed_dates' => [],
            'future_dates' => $futureDates,
            'days_of_week' => [3],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')
            ->where('StudentID', $student->id)
            ->max('ID');

        $session = ClassSession::where('StudentClassID', $courseId)
            ->where('SessionDate', $futureDates[0])
            ->first();
        $session->Status = 'attended';
        $session->save();

        SessionDeductionLedger::create([
            'student_class_id' => $courseId,
            'class_session_id' => $session->id,
            'event_type'       => 'deduct',
            'source'           => 'attendance',
        ]);

        // First retro-leave
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/schedules/retro-leave', [
            'student_course_id' => $courseId,
            'session_date'      => $futureDates[0],
        ])->assertOk();

        // Second retro-leave on same session — should get 422 since it's already leave_adjusted
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/schedules/retro-leave', [
            'student_course_id' => $courseId,
            'session_date'      => $futureDates[0],
        ])->assertStatus(422);

        // Only 1 reverse entry
        $reverseCount = SessionDeductionLedger::where('student_class_id', $courseId)
            ->where('class_session_id', $session->id)
            ->where('event_type', 'reverse')
            ->count();
        $this->assertSame(1, $reverseCount);
    }

    /**
     * @param  array<int>  $campusIds
     */
    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
            'MustChangePassword' => false,
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

    private function createTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName,
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922000000',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        return (int) $teacher->id;
    }

    private function createTeacherToken(int $teacherId, int $campusId): string
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $teacherId,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);
        return $token;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCourseViaBatchApi(string $token, int $studentId, int $teacherId, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'branch_id' => 1,
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 8,
            'confirmed_dates' => [],
            'future_dates' => [],
            'days_of_week' => [3],
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
            'start_time' => '16:00',
        ], $overrides);

        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', $payload);
    }
}
