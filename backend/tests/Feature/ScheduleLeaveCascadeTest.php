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
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleLeaveCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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

    public function test_leave_by_session_repairs_existing_leave_without_duplicate_tail(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-01 08:00:00', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1], 'director-existing-leave@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-existing-leave@example.com');
        $student = $this->createStudent(1, '黃品皓');

        $futureDates = [
            '2026-04-07',
            '2026-04-14',
            '2026-04-21',
            '2026-04-28',
            '2026-05-05',
            '2026-05-12',
            '2026-05-19',
            '2026-05-26',
        ];

        $this->createCourseViaBatchApi($token, $student->id, $teacherId, [
            'total_classes' => 8,
            'confirmed_dates' => [],
            'future_dates' => $futureDates,
            'days_of_week' => [2],
            'start_time' => '16:30',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')
            ->where('StudentID', $student->id)
            ->where('TeacherID', $teacherId)
            ->max('ID');
        $this->assertTrue($courseId > 0);

        $leaveSession = ClassSession::where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '2026-04-28')
            ->first();
        $this->assertNotNull($leaveSession);

        // Simulate the historical half-written state: leave status exists, but
        // subsequent sessions were not shifted and no replacement tail was appended.
        $leaveSession->Status = 'leave';
        $leaveSession->save();
        DB::table('schedules')->insert([
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'day_of_week' => 2,
            'start_time' => '16:30',
            'end_time' => '18:30',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'leave',
            'type' => 'normal',
            'deduction' => 0,
            'branch_id' => 1,
            'schedule_date' => '2026-04-28',
            'student_course_id' => $courseId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(7, ClassSession::where('StudentClassID', $courseId)
            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted', 'excused'])
            ->count());

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules/leave-by-session', [
            'class_session_id' => (int) $leaveSession->id,
        ])->assertOk();

        $this->assertSame('2026-06-09', (string) $res->json('extended_end_date'));
        $this->assertSame(8, ClassSession::where('StudentClassID', $courseId)
            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted', 'excused'])
            ->count());
        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-06-09',
            'Status' => 'scheduled',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules/leave-by-session', [
            'class_session_id' => (int) $leaveSession->id,
        ])->assertStatus(422);

        $this->assertSame(1, ClassSession::where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '2026-06-09')
            ->count());
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

    public function test_retro_leave_targets_specific_session_when_same_course_has_two_sessions_on_same_day(): void
    {
        $token = $this->createDirectorToken([1], 'director-retro-specific@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-retro-specific@example.com');
        $student = $this->createStudent(1, '陳湘甯');

        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-01',
            'EndDate' => '2026-05-31',
            'TotalHours' => 40,
            'Memo' => 'retro-specific-session',
            'Paid' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'RemainingSessions' => 6,
            'UsedSessions' => 2,
            'SessionDuration' => 120,
            'ClassType' => 'one_on_one',
            'MDate' => now(),
            'Rate' => 500,
        ]);
        $courseId = (int) $course->ID;

        $earlySession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-04-30',
            'StartTime' => '18:30:00',
            'EndTime' => '20:30:00',
            'Status' => 'attended',
            'Note' => '',
        ]);
        $lateSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-04-30',
            'StartTime' => '20:00:00',
            'EndTime' => '22:00:00',
            'Status' => 'attended',
            'Note' => '',
        ]);
        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-05-07',
            'StartTime' => '18:30:00',
            'EndTime' => '20:30:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $earlySignIn = StudentSignIn::create([
            'StudentClassID' => $courseId,
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'ClassSessionID' => $earlySession->id,
            'SignInDT' => '2026-04-30 18:30:00',
            'Status' => 'present',
            'CampusID' => 1,
            'SessionDeducted' => true,
        ]);
        $lateSignIn = StudentSignIn::create([
            'StudentClassID' => $courseId,
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'ClassSessionID' => $lateSession->id,
            'SignInDT' => '2026-04-30 20:00:00',
            'Status' => 'present',
            'CampusID' => 1,
            'SessionDeducted' => true,
        ]);
        foreach ([$earlySession, $lateSession] as $session) {
            SessionDeductionLedger::create([
                'student_class_id' => $courseId,
                'class_session_id' => (int) $session->id,
                'event_type' => 'deduct',
                'source' => 'attendance',
            ]);
        }

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules/retro-leave', [
            'student_course_id' => $courseId,
            'class_session_id' => (int) $lateSession->id,
            'session_date' => '2026-04-30',
            'reason' => '同日第二堂補請假',
        ])->assertOk();

        $earlySession->refresh();
        $lateSession->refresh();
        $earlySignIn->refresh();
        $lateSignIn->refresh();

        $this->assertSame('attended', $earlySession->Status, '18:30 堂次不應被補請假誤改');
        $this->assertNull($earlySignIn->VoidedAt, '18:30 堂次點名不應被作廢');
        $this->assertSame('leave_adjusted', $lateSession->Status, '20:00 指定堂次應被補請假');
        $this->assertNotNull($lateSignIn->VoidedAt, '20:00 指定堂次點名應被作廢');
        $this->assertDatabaseHas('session_deduction_ledger', [
            'student_class_id' => $courseId,
            'class_session_id' => (int) $lateSession->id,
            'event_type' => 'reverse',
            'source' => 'retro_leave',
        ]);
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

    public function test_reschedule_to_future_resets_completed_session_to_scheduled_and_recomputes_remaining(): void
    {
        $token = $this->createDirectorToken([1], 'director-reschedule-future@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-reschedule-future@example.com');
        $student = $this->createStudent(1, '調課未上測試');

        $firstFriday = Carbon::now()->next(Carbon::FRIDAY);
        $futureDates = [];
        for ($i = 0; $i < 4; $i++) {
            $futureDates[] = $firstFriday->copy()->addWeeks($i)->toDateString();
        }

        $this->createCourseViaBatchApi($token, $student->id, $teacherId, [
            'total_classes' => 4,
            'confirmed_dates' => [],
            'future_dates' => $futureDates,
            'days_of_week' => [5],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')
            ->where('StudentID', $student->id)
            ->where('TeacherID', $teacherId)
            ->max('ID');
        $this->assertTrue($courseId > 0);

        $course = StudentClass::findOrFail($courseId);
        $targetSession = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate', 'asc')
            ->orderBy('id', 'asc')
            ->first();
        $this->assertNotNull($targetSession);

        $oldDate = Carbon::yesterday()->toDateString();
        $newDate = Carbon::tomorrow()->addDays(3)->toDateString();
        $targetSession->SessionDate = $oldDate;
        $targetSession->Status = 'completed';
        $targetSession->save();

        SessionDeductionService::recomputeCounters($courseId);
        $course->refresh();
        $this->assertSame(3, (int) $course->RemainingSessions);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/reschedule-session', [
            'student_class_id' => $courseId,
            'old_date' => $oldDate,
            'new_date' => $newDate,
            'start_time' => '16:00',
            'end_time' => '18:00',
        ])
            ->assertOk()
            ->assertJson([
                'reset_to_scheduled' => true,
            ]);

        $targetSession->refresh();
        $this->assertSame($newDate, Carbon::parse($targetSession->SessionDate)->toDateString());
        $this->assertSame('scheduled', strtolower((string) $targetSession->Status));

        $course->refresh();
        $this->assertSame(4, (int) $course->RemainingSessions);
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

    public function test_director_can_undo_leave_after_undo_window_expires(): void
    {
        // #142 §1 / #596: 取消請假不應受 30 秒 undo-toast 窗口限制；
        // 安全性由 cascade 的「下游已上課堂次」護欄把關（見下一個測試）。
        Carbon::setTestNow(Carbon::parse('2026-04-01 08:00:00', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1], 'director-undo-window@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-undo-window@example.com');
        $student = $this->createStudent(1, '逾窗撤銷測試');

        $firstWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $futureDates = [];
        for ($i = 0; $i < 8; $i++) {
            $futureDates[] = $firstWednesday->copy()->addWeeks($i)->toDateString();
        }
        $this->createCourseViaBatchApi($token, $student->id, $teacherId, [
            'total_classes' => 8,
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

        $leaveDate = $futureDates[6];
        $leaveRes = $this->withHeaders([
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
            'schedule_date' => $leaveDate,
            'student_course_id' => $courseId,
        ])->assertCreated();

        $scheduleId = (int) $leaveRes->json('schedule.id');
        $this->assertTrue($scheduleId > 0);
        $this->assertSame(9, ClassSession::where('StudentClassID', $courseId)->count());

        // Move well past the 30s undo-toast window.
        Carbon::setTestNow(Carbon::parse('2026-04-01 09:00:00', 'Asia/Taipei'));

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/schedules/{$scheduleId}/undo-leave")
            ->assertOk();

        // Leave session restored to scheduled, appended tail removed.
        $leaveSession = ClassSession::where('StudentClassID', $courseId)
            ->whereDate('SessionDate', $leaveDate)
            ->first();
        $this->assertNotNull($leaveSession);
        $this->assertSame('scheduled', strtolower((string) $leaveSession->Status));
        $this->assertSame(8, ClassSession::where('StudentClassID', $courseId)->count());
        $this->assertDatabaseMissing('schedules', ['id' => $scheduleId]);
    }

    public function test_undo_leave_still_blocked_when_downstream_session_attended(): void
    {
        // Safety net must survive removing the time window: if a later session is
        // already attended, the cascade refuses to auto-undo (regardless of age).
        Carbon::setTestNow(Carbon::parse('2026-04-01 08:00:00', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1], 'director-undo-guard@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-undo-guard@example.com');
        $student = $this->createStudent(1, '下游已上課護欄');

        $firstWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $futureDates = [];
        for ($i = 0; $i < 8; $i++) {
            $futureDates[] = $firstWednesday->copy()->addWeeks($i)->toDateString();
        }
        $this->createCourseViaBatchApi($token, $student->id, $teacherId, [
            'total_classes' => 8,
            'confirmed_dates' => [],
            'future_dates' => $futureDates,
            'days_of_week' => [3],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')
            ->where('StudentID', $student->id)
            ->where('TeacherID', $teacherId)
            ->max('ID');

        $leaveDate = $futureDates[2];
        $leaveRes = $this->withHeaders([
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
            'schedule_date' => $leaveDate,
            'student_course_id' => $courseId,
        ])->assertCreated();
        $scheduleId = (int) $leaveRes->json('schedule.id');

        // Mark a session after the leave date as attended.
        $downstream = ClassSession::where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '>', $leaveDate)
            ->where('Status', 'scheduled')
            ->orderBy('SessionDate')
            ->first();
        $this->assertNotNull($downstream);
        $downstream->Status = 'attended';
        $downstream->save();

        Carbon::setTestNow(Carbon::parse('2026-04-01 09:00:00', 'Asia/Taipei'));

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/schedules/{$scheduleId}/undo-leave")
            ->assertStatus(422);
        $this->assertStringContainsString('後續堂次', (string) $res->json('message'));
    }

    public function test_undo_leave_forbidden_for_teacher(): void
    {
        $dirToken = $this->createDirectorToken([1], 'director-undo-perm@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-undo-perm@example.com');
        $teacherToken = $this->createTeacherToken($teacherId, 1);
        $student = $this->createStudent(1, '撤銷權限測試');

        $firstWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $futureDates = [];
        for ($i = 0; $i < 4; $i++) {
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

        $leaveRes = $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
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
            'schedule_date' => $futureDates[1],
            'student_course_id' => $courseId,
        ])->assertCreated();
        $scheduleId = (int) $leaveRes->json('schedule.id');

        $this->withHeaders([
            'Authorization' => "Bearer {$teacherToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/schedules/{$scheduleId}/undo-leave")
            ->assertForbidden();
    }

    public function test_undo_leave_by_session_restores_scheduled(): void
    {
        // #142 §1 / #596: 前端持有 class_session_id，提供 by-session 取消路徑。
        Carbon::setTestNow(Carbon::parse('2026-04-01 08:00:00', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1], 'director-undo-bysess@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-undo-bysess@example.com');
        $student = $this->createStudent(1, '依堂次撤銷');

        $firstTuesday = Carbon::now()->next(Carbon::TUESDAY);
        $futureDates = [];
        for ($i = 0; $i < 8; $i++) {
            $futureDates[] = $firstTuesday->copy()->addWeeks($i)->toDateString();
        }
        $this->createCourseViaBatchApi($token, $student->id, $teacherId, [
            'total_classes' => 8,
            'confirmed_dates' => [],
            'future_dates' => $futureDates,
            'days_of_week' => [2],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')
            ->where('StudentID', $student->id)
            ->where('TeacherID', $teacherId)
            ->max('ID');

        $leaveDate = $futureDates[6];
        $leaveSession = ClassSession::where('StudentClassID', $courseId)
            ->whereDate('SessionDate', $leaveDate)
            ->first();
        $this->assertNotNull($leaveSession);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules/leave-by-session', [
            'class_session_id' => (int) $leaveSession->id,
        ])->assertOk();

        $leaveSession->refresh();
        $this->assertSame('leave', strtolower((string) $leaveSession->Status));
        $this->assertSame(9, ClassSession::where('StudentClassID', $courseId)->count());
        $this->assertDatabaseHas('schedules', [
            'student_course_id' => $courseId,
            'status' => 'leave',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules/undo-leave-by-session', [
            'class_session_id' => (int) $leaveSession->id,
        ])->assertOk();

        $leaveSession->refresh();
        $this->assertSame('scheduled', strtolower((string) $leaveSession->Status));
        $this->assertSame(8, ClassSession::where('StudentClassID', $courseId)->count());
        $this->assertDatabaseMissing('schedules', [
            'student_course_id' => $courseId,
            'status' => 'leave',
        ]);
    }

    public function test_undo_leave_by_session_rejects_non_leave_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-01 08:00:00', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1], 'director-undo-bysess-nonleave@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-undo-bysess-nonleave@example.com');
        $student = $this->createStudent(1, '非請假堂次');

        $firstTuesday = Carbon::now()->next(Carbon::TUESDAY);
        $futureDates = [];
        for ($i = 0; $i < 4; $i++) {
            $futureDates[] = $firstTuesday->copy()->addWeeks($i)->toDateString();
        }
        $this->createCourseViaBatchApi($token, $student->id, $teacherId, [
            'total_classes' => 4,
            'confirmed_dates' => [],
            'future_dates' => $futureDates,
            'days_of_week' => [2],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')
            ->where('StudentID', $student->id)
            ->max('ID');
        $scheduled = ClassSession::where('StudentClassID', $courseId)
            ->where('Status', 'scheduled')
            ->first();
        $this->assertNotNull($scheduled);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules/undo-leave-by-session', [
            'class_session_id' => (int) $scheduled->id,
        ])->assertStatus(422);
    }

    public function test_undo_leave_by_session_handles_signin_only_leave_desync(): void
    {
        // in-app #169：請假只記在 StudentSingIn（Status=leave），ClassSession.Status 仍為 scheduled。
        // 舊邏輯回 422「僅能撤銷請假狀態的堂次」；修正後應可撤銷（作廢請假簽到、堂次維持 scheduled）。
        Carbon::setTestNow(Carbon::parse('2026-04-01 08:00:00', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1], 'director-undo-desync@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-undo-desync@example.com');
        $student = $this->createStudent(1, '請假desync學生');

        $firstTuesday = Carbon::now()->next(Carbon::TUESDAY);
        $futureDates = [];
        for ($i = 0; $i < 4; $i++) {
            $futureDates[] = $firstTuesday->copy()->addWeeks($i)->toDateString();
        }
        $this->createCourseViaBatchApi($token, $student->id, $teacherId, [
            'total_classes' => 4,
            'confirmed_dates' => [],
            'future_dates' => $futureDates,
            'days_of_week' => [2],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')->where('StudentID', $student->id)->max('ID');
        $session = ClassSession::where('StudentClassID', $courseId)->where('Status', 'scheduled')->first();
        $this->assertNotNull($session);

        // 製造 desync：未作廢的請假簽到，但 ClassSession 仍 scheduled。
        StudentSignIn::create([
            'StudentClassID' => $courseId,
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'SubjectID' => 1,
            'GradeID' => 1,
            'Memo' => 'desync-leave',
            'SignInDT' => $session->SessionDate . ' 16:00:00',
            'SignOutDT' => null,
            'MDT' => now(),
            'ClassSessionID' => $session->id,
            'Status' => 'leave',
            'CampusID' => 1,
            'PersonType' => 'student',
            'SessionDeducted' => false,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules/undo-leave-by-session', [
            'class_session_id' => (int) $session->id,
        ])->assertOk();

        $this->assertSame(0, StudentSignIn::where('ClassSessionID', $session->id)
            ->whereNull('VoidedAt')
            ->whereIn('Status', ['leave', 'excused', 'leave_requested'])
            ->count(), '請假簽到應全部作廢');
        $session->refresh();
        $this->assertSame('scheduled', strtolower((string) $session->Status));
    }

    public function test_undo_leave_by_session_forbidden_for_teacher(): void
    {
        $dirToken = $this->createDirectorToken([1], 'director-undo-bysess-perm@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-undo-bysess-perm@example.com');
        $teacherToken = $this->createTeacherToken($teacherId, 1);

        $this->withHeaders([
            'Authorization' => "Bearer {$teacherToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules/undo-leave-by-session', [
            'class_session_id' => 999999,
        ])->assertForbidden();
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
