<?php

namespace Tests\Feature;

use App\Exceptions\SlotOccupiedException;
use App\Http\Controllers\StudentClassController;
use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ClassSessionContractReflowService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class StudentClassAdoptExceptionRecurringScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-12 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Requirement 1: Exception covered by new fixed schedule succeeds without 409 and regularizes.
     */
    public function test_exception_session_covered_by_new_fixed_schedule_succeeds_without_409_and_regularizes(): void
    {
        [$token, $course] = $this->seedMondayCourseWithWednesdayException();
        $wedSession = ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-04-15')->firstOrFail();
        $this->assertSame(1, (int) $wedSession->IsContractException);

        $response = $this->updateFixedSlots($token, $course, [1, 3]);
        $response->assertOk();

        $this->assertSame(0, (int) $wedSession->fresh()->IsContractException, 'Exception must be regularized');
        $this->assertSame('2026-04-15', substr((string) $wedSession->fresh()->SessionDate, 0, 10));
        $this->assertSame('17:00:00', (string) $wedSession->fresh()->StartTime);
        $this->assertSame(1, (int) $course->fresh()->week);
        $this->assertSame(3, (int) $course->fresh()->week1);
    }

    /**
     * Requirement 2: No duplicate ClassSession created during exception adoption.
     */
    public function test_adoption_does_not_create_duplicate_class_session(): void
    {
        [$token, $course] = $this->seedMondayCourseWithWednesdayException();
        $initialTotalCount = ClassSession::where('StudentClassID', $course->ID)->count();
        $initialWedSession = ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-04-15')->firstOrFail();

        $response = $this->updateFixedSlots($token, $course, [1, 3]);
        $response->assertOk();

        $wedSessions = ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-04-15')->get();
        $this->assertCount(1, $wedSessions);
        $this->assertSame($initialWedSession->id, $wedSessions->first()->id);
        $this->assertSame($initialTotalCount, ClassSession::where('StudentClassID', $course->ID)->count());
    }

    /**
     * Requirement 3: Genuine external conflict still results in 409.
     */
    public function test_genuine_external_conflict_still_returns_409(): void
    {
        [$token, $course, $teacherId] = $this->seedMondayCourseWithWednesdayException();
        $extStudent = Student::create(['name' => '外部衝突學生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $extCourse = $this->createCourseRecord($extStudent->id, $teacherId, ['week' => 5]);
        $this->createSessionRecord($extCourse->ID, '2026-04-22', '17:00:00', '18:00:00');

        $response = $this->updateFixedSlots($token, $course, [1, 3]);
        $response->assertStatus(409);
        $this->assertSame('teacher_schedule_conflict', $response->json('code'));
        $this->assertNotEmpty($response->json('conflicts'));
    }

    /**
     * Requirement 4a: Non-corresponding exception at different time is NOT absorbed.
     */
    public function test_non_corresponding_exception_at_different_time_is_not_absorbed(): void
    {
        [$token, $course] = $this->seedMondayCourseWithWednesdayException();
        $eveningException = $this->createSessionRecord($course->ID, '2026-04-22', '19:00:00', '20:00:00', 'scheduled', 1);

        $response = $this->updateFixedSlots($token, $course, [1, 3]);
        $response->assertOk();
        $this->assertSame(1, (int) $eveningException->fresh()->IsContractException);
    }

    /**
     * Requirement 4b: Partially overlapping exception is rejected as conflict.
     */
    public function test_partially_overlapping_exception_is_rejected_as_conflict(): void
    {
        [$token, $course] = $this->seedMondayCourseWithWednesdayException();
        ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-04-15')->delete();
        $this->createSessionRecord($course->ID, '2026-04-15', '16:30:00', '17:30:00', 'scheduled', 1);

        $response = $this->updateFixedSlots($token, $course, [1, 3]);
        $response->assertStatus(409);
        $this->assertSame('teacher_schedule_conflict', $response->json('code'));
    }

    /**
     * Requirement 5: Repeated request is idempotent and creates no duplicate data.
     */
    public function test_repeated_request_is_idempotent_and_creates_no_duplicate_data(): void
    {
        [$token, $course] = $this->seedMondayCourseWithWednesdayException();

        $this->updateFixedSlots($token, $course, [1, 3])->assertOk();
        $firstIds = ClassSession::where('StudentClassID', $course->ID)->orderBy('id')->pluck('id')->all();

        $this->updateFixedSlots($token, $course, [1, 3])->assertOk();
        $secondIds = ClassSession::where('StudentClassID', $course->ID)->orderBy('id')->pluck('id')->all();

        $this->assertSame($firstIds, $secondIds);
    }

    /**
     * Requirement 6a: Existing exception 17:00–18:30 vs recurring 17:00–18:00 must NOT regularize or reflow.
     */
    public function test_exception_with_different_duration_is_not_regularized_and_no_duplicate_or_silent_reflow(): void
    {
        [$token, $course] = $this->seedMondayCourseWithWednesdayException();
        $wedSession = ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-04-15')->firstOrFail();
        $wedSession->update(['EndTime' => '18:30:00', 'IsContractException' => 1]);
        $initialTotalCount = ClassSession::where('StudentClassID', $course->ID)->count();

        $response = $this->updateFixedSlots($token, $course, [1, 3]);
        $response->assertStatus(409);
        $this->assertSame('teacher_schedule_conflict', $response->json('code'));

        $fresh = $wedSession->fresh();
        $this->assertSame(1, (int) $fresh->IsContractException);
        $this->assertSame('17:00:00', (string) $fresh->StartTime);
        $this->assertSame('18:30:00', (string) $fresh->EndTime);
        $this->assertSame($initialTotalCount, ClassSession::where('StudentClassID', $course->ID)->count());
        $this->assertSame(1, ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-04-15')->count());
    }

    /**
     * Requirement 6b: Direct syncFutureScheduledSessionTimes does not regularize different duration exception.
     */
    public function test_direct_sync_does_not_regularize_exception_with_different_duration(): void
    {
        [$token, $course] = $this->seedMondayCourseWithWednesdayException();
        $wedSession = ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-04-15')->firstOrFail();
        $wedSession->update(['EndTime' => '18:30:00', 'IsContractException' => 1]);

        $controller = app(StudentClassController::class);
        $method = new ReflectionMethod($controller, 'syncFutureScheduledSessionTimes');
        $method->setAccessible(true);
        $method->invoke($controller, (int) $course->ID, [
            ['weekday' => 1, 'time' => '17:00', 'duration_minutes' => 60],
            ['weekday' => 3, 'time' => '17:00', 'duration_minutes' => 60],
        ], 60, []);

        $fresh = $wedSession->fresh();
        $this->assertSame(1, (int) $fresh->IsContractException);
        $this->assertSame('18:30:00', (string) $fresh->EndTime);
    }

    /**
     * Requirement 6c: API-level atomicity: PUT rolls back BOTH session state and StudentClass contract fields on reflow failure.
     */
    public function test_api_update_rolls_back_contract_fields_and_sessions_when_reflow_fails(): void
    {
        [$token, $course] = $this->seedMondayCourseWithWednesdayException();

        $this->createSessionRecord($course->ID, '2026-04-17', '17:00:00', '18:00:00'); // Friday unlocked triggers remap
        $wedSession = ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-04-15')->firstOrFail();
        $this->assertSame(1, (int) $wedSession->IsContractException);
        $this->assertNull($course->week1);

        $mockReflow = Mockery::mock(ClassSessionContractReflowService::class);
        $mockReflow->shouldReceive('move')->andThrow(new SlotOccupiedException((int) $course->ID, '2026-04-20', '17:00:00', (int) $wedSession->id));
        $this->app->instance(ClassSessionContractReflowService::class, $mockReflow);

        $response = $this->updateFixedSlots($token, $course, [1, 3]);
        $response->assertStatus(422);

        // 1. Session state rolls back
        $this->assertSame(1, (int) $wedSession->fresh()->IsContractException);

        // 2. StudentClass contract fields remain unchanged
        $freshCourse = $course->fresh();
        $this->assertNull($freshCourse->week1, 'StudentClass week1 must remain null on failure');
        $this->assertNull($freshCourse->time1, 'StudentClass time1 must remain null on failure');
    }

    /**
     * Requirement 6d: Locked exact-match exception semantics:
     * An exception matching the recurring slot exactly that is locked (e.g. StudentSignIn)
     * is regularized (IsContractException: 1 -> 0), kept on its slot, and creates no duplicates.
     */
    public function test_locked_exact_match_exception_regularizes_and_remains_at_its_slot(): void
    {
        [$token, $course, $teacherId] = $this->seedMondayCourseWithWednesdayException();

        $wedSession = ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-04-15')->firstOrFail();
        $this->assertSame(1, (int) $wedSession->IsContractException);

        $student = Student::find($course->StudentID);
        $signIn = StudentSignIn::create([
            'StudentClassID' => $course->ID, 'StudentID' => $student->id, 'TeacherID' => $teacherId,
            'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => '2026-04-15 17:00:00',
            'MDT' => now(), 'ClassSessionID' => $wedSession->id, 'Status' => 'present', 'SessionDeducted' => 1,
        ]);

        $response = $this->updateFixedSlots($token, $course, [1, 3]);
        $response->assertOk();

        $this->assertSame(0, (int) $wedSession->fresh()->IsContractException);
        $this->assertSame('2026-04-15', substr((string) $wedSession->fresh()->SessionDate, 0, 10));
        $this->assertSame('17:00:00', (string) $wedSession->fresh()->StartTime);
        $this->assertSame('18:00:00', (string) $wedSession->fresh()->EndTime);
        $this->assertSame(1, ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-04-15')->count());
        $this->assertSame((int) $wedSession->id, (int) $signIn->fresh()->ClassSessionID);
    }

    /**
     * Requirement: Bounded horizon audit:
     * A concrete session of another student after contract EndDate must NOT block contract update.
     * But an external session within contract horizon must still block.
     */
    public function test_concrete_session_after_contract_end_date_does_not_block_recurring_update(): void
    {
        $token = $this->createDirectorToken([1]);
        $teacherId = 159;
        $studentA = Student::create(['name' => '學生A', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $courseA = $this->createCourseRecord($studentA->id, $teacherId, ['ScheduleMode' => 'date', 'StartDate' => '2026-04-01', 'EndDate' => '2026-04-30']);

        $studentB = Student::create(['name' => '學生B', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $courseB = $this->createCourseRecord($studentB->id, $teacherId, ['week' => 5]);
        $this->createSessionRecord($courseB->ID, '2026-05-13', '17:00:00', '18:00:00');

        $payload = [
            'subject' => 'English', 'class_type' => 'one_on_one', 'duration_hours' => 1,
            'days_of_week' => [3], 'start_time' => '17:00', 'payment_type' => 'period', 'end_date' => '2026-04-30',
            'day_time_slots' => [['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60]],
        ];

        // 1. External session after EndDate does NOT block
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/student-classes/{$courseA->ID}", $payload);
        $response->assertOk();

        // 2. External session within EndDate MUST block
        $studentC = Student::create(['name' => '學生C', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $courseC = $this->createCourseRecord($studentC->id, $teacherId, ['week' => 5]);
        $this->createSessionRecord($courseC->ID, '2026-04-22', '17:00:00', '18:00:00');

        $conflictResponse = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/student-classes/{$courseA->ID}", $payload);
        $conflictResponse->assertStatus(409);
        $this->assertSame('teacher_schedule_conflict', $conflictResponse->json('code'));
    }

    /**
     * In-app #347：把課程時段 17:00-18:00 改到 17:30（部分重疊）時，自己的舊堂次不可擋住自己。
     */
    public function test_partial_overlap_move_ignores_own_regular_sessions_but_not_other_students(): void
    {
        [$token, $course, $teacherId] = $this->seedMondayCourseWithWednesdayException();
        $slots = [['day' => 1, 'start_time' => '17:30', 'duration_minutes' => 60]];

        $this->updateFixedSlots($token, $course, [1], $slots)->assertOk();

        $other = Student::create(['name' => '真衝突', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $otherCourse = $this->createCourseRecord($other->id, $teacherId, ['week' => 5]);
        $this->createSessionRecord($otherCourse->ID, '2026-04-27', '17:30:00', '18:30:00');
        $this->updateFixedSlots($token, $course->fresh(), [1], $slots)->assertStatus(409);
    }

    /**
     * In-app #347：不同日期的學生不可跨日加總成「已滿」。
     */
    public function test_students_on_different_dates_are_not_pooled_into_full(): void
    {
        $token = $this->createDirectorToken([1]);
        $teacherId = 159;
        $mine = Student::create(['name' => '編輯者', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $course = $this->createCourseRecord($mine->id, $teacherId, ['ClassType' => 'one_on_two', 'week' => 3]);
        foreach (['2026-04-18', '2026-04-25'] as $d) { // two Saturdays, one student each
            $s = Student::create(['name' => 'S' . $d, 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
            $c = $this->createCourseRecord($s->id, $teacherId, ['ClassType' => 'one_on_two', 'week' => 5]);
            $this->createSessionRecord($c->ID, $d, '17:00:00', '18:00:00');
        }
        $resp = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/student-classes/{$course->ID}", [
                'subject' => 'English', 'class_type' => 'one_on_two', 'duration_hours' => 1,
                'days_of_week' => [6], 'start_time' => '17:00', 'payment_type' => 'session',
                'day_time_slots' => [['day' => 6, 'start_time' => '17:00', 'duration_minutes' => 60]],
            ]);
        $resp->assertOk();
    }

    /**
     * Two own regular rows on one date but only one new slot that day: the excess row is not remapped,
     * so a new slot partially overlapping it must still conflict.
     */
    public function test_excess_own_regular_row_still_conflicts_when_slots_shrink(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create(['name' => '縮減時段', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $course = $this->createCourseRecord($student->id, 159);
        $this->createSessionRecord($course->ID, '2026-04-27', '15:00:00', '16:00:00');
        $this->createSessionRecord($course->ID, '2026-04-27', '17:00:00', '18:00:00');

        $this->updateFixedSlots($token, $course, [1], [['day' => 1, 'start_time' => '16:30', 'duration_minutes' => 60]])
            ->assertStatus(409);
        // 15:00 moves to 16:30 and the excess 17:00 row stays: overlap. An exact match to an existing row is fine.
        $this->updateFixedSlots($token, $course->fresh(), [1], [['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60]])
            ->assertOk();
    }

    /**
     * A session locked by a sign-in is not remapped by the edit, so it is never excused as "will move":
     * it stays at 15:00-16:00 and overlaps the new 15:30 slot.
     */
    public function test_locked_own_row_still_conflicts_even_when_slot_count_covers_rows(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create(['name' => '鎖定列', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $course = $this->createCourseRecord($student->id, 159);
        $locked = $this->createSessionRecord($course->ID, '2026-04-27', '15:00:00', '16:00:00');
        $this->createSessionRecord($course->ID, '2026-04-27', '17:00:00', '18:00:00');
        StudentSignIn::create(['StudentClassID' => $course->ID, 'StudentID' => $student->id, 'TeacherID' => 159, 'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => '2026-04-27 15:00:00', 'MDT' => now(), 'ClassSessionID' => $locked->id, 'Status' => 'present', 'SessionDeducted' => 1]);

        $this->updateFixedSlots($token, $course, [1], [['day' => 1, 'start_time' => '15:30', 'duration_minutes' => 60], ['day' => 1, 'start_time' => '16:30', 'duration_minutes' => 60]])->assertStatus(409);
    }

    /** A pending-leave session is never moved by the sync, so it must still conflict with an overlapping new slot. */
    public function test_pending_leave_row_is_not_treated_as_remapped(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create(['name' => '請假待審', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $course = $this->createCourseRecord($student->id, 159);
        $this->createSessionRecord($course->ID, '2026-04-27', '17:00:00', '18:00:00', 'leave_requested');

        $this->updateFixedSlots($token, $course, [1], [['day' => 1, 'start_time' => '17:30', 'duration_minutes' => 60]])
            ->assertStatus(409);
    }

    /** A locked row already on a new slot keeps it; unlocked rows pair with the remaining slots. */
    public function test_aligned_locked_row_consumes_its_slot(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create(['name' => '鎖定對齊', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $course = $this->createCourseRecord($student->id, 159);
        $locked = $this->createSessionRecord($course->ID, '2026-04-27', '15:00:00', '16:00:00');
        $this->createSessionRecord($course->ID, '2026-04-27', '16:00:00', '17:00:00');
        $this->createSessionRecord($course->ID, '2026-04-27', '18:00:00', '19:00:00');
        StudentSignIn::create(['StudentClassID' => $course->ID, 'StudentID' => $student->id, 'TeacherID' => 159, 'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => '2026-04-27 15:00:00', 'MDT' => now(), 'ClassSessionID' => $locked->id, 'Status' => 'present', 'SessionDeducted' => 1]);

        $this->updateFixedSlots($token, $course, [1], [['day' => 1, 'start_time' => '15:00', 'duration_minutes' => 60], ['day' => 1, 'start_time' => '16:30', 'duration_minutes' => 60]])->assertOk();

        $this->assertSame(['15:00', '16:30', '18:00'], $this->startsOn($course, '2026-04-27'));
    }

    /** Locked rows do not count toward the remap budget: 2 remappable rows still fit 2 new slots. */
    public function test_locked_row_far_from_slots_does_not_inflate_remap_count(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create(['name' => '鎖定計數', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $course = $this->createCourseRecord($student->id, 159);
        $locked = $this->createSessionRecord($course->ID, '2026-04-27', '09:00:00', '10:00:00');
        $this->createSessionRecord($course->ID, '2026-04-27', '15:00:00', '16:00:00');
        $this->createSessionRecord($course->ID, '2026-04-27', '17:00:00', '18:00:00');
        StudentSignIn::create(['StudentClassID' => $course->ID, 'StudentID' => $student->id, 'TeacherID' => 159, 'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => '2026-04-27 09:00:00', 'MDT' => now(), 'ClassSessionID' => $locked->id, 'Status' => 'present', 'SessionDeducted' => 1]);

        $this->updateFixedSlots($token, $course, [1], [['day' => 1, 'start_time' => '15:30', 'duration_minutes' => 60], ['day' => 1, 'start_time' => '17:30', 'duration_minutes' => 60]])->assertOk();

        // Slots pair with unlocked rows only: 15:00→15:30, 17:00→17:30, locked 09:00 stays (no overlap).
        $this->assertSame(['09:00', '15:30', '17:30'], $this->startsOn($course, '2026-04-27'));
    }

    /**
     * A locked exception exactly on a new slot is adopted and consumes it, so only one unlocked row moves:
     * 16:00→17:30, 18:00 stays and overlaps 17:30-18:30. Guard and sync must agree (409).
     */
    public function test_locked_adopted_exception_consumes_its_slot_so_excess_row_conflicts(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create(['name' => '鎖定例外', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $course = $this->createCourseRecord($student->id, 159);
        $locked = $this->createSessionRecord($course->ID, '2026-04-27', '15:00:00', '16:00:00', 'scheduled', 1);
        $this->createSessionRecord($course->ID, '2026-04-27', '16:00:00', '17:00:00');
        $this->createSessionRecord($course->ID, '2026-04-27', '18:00:00', '19:00:00');
        StudentSignIn::create(['StudentClassID' => $course->ID, 'StudentID' => $student->id, 'TeacherID' => 159, 'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => '2026-04-27 15:00:00', 'MDT' => now(), 'ClassSessionID' => $locked->id, 'Status' => 'present', 'SessionDeducted' => 1]);

        $this->updateFixedSlots($token, $course, [1], [['day' => 1, 'start_time' => '15:00', 'duration_minutes' => 60], ['day' => 1, 'start_time' => '17:30', 'duration_minutes' => 60]])->assertStatus(409);
    }

    /** A moved own session's paired schedules row moves with it and must not self-conflict on a partial shift. */
    public function test_paired_schedule_row_of_moved_session_does_not_self_conflict(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create(['name' => '配對排程', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $course = $this->createCourseRecord($student->id, 159);
        $this->createSessionRecord($course->ID, '2026-04-27', '17:00:00', '18:00:00');
        DB::table('schedules')->insert(['student_id' => $student->id, 'branch_id' => 1, 'teacher_id' => 159,
            'student_course_id' => $course->ID, 'day_of_week' => 1, 'schedule_date' => '2026-04-27',
            'start_time' => '17:00:00', 'end_time' => '18:00:00', 'status' => 'scheduled', 'type' => 'normal', 'deduction' => 1]);

        $this->updateFixedSlots($token, $course, [1], [['day' => 1, 'start_time' => '17:30', 'duration_minutes' => 60]])->assertOk();
        $this->assertSame(['17:30'], $this->startsOn($course, '2026-04-27'));
    }

    /**
     * Codex P1: a shared class dedupes its own student in capacity counting, so a remap that lands an unlocked row
     * on top of a locked row of the same course must still be rejected (409) and leave sessions untouched.
     */
    public function test_shared_class_remap_onto_own_locked_row_is_rejected(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create(['name' => '共享自重疊', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $course = $this->createCourseRecord($student->id, 159, ['ClassType' => 'one_on_two']);
        $locked = $this->createSessionRecord($course->ID, '2026-04-27', '15:30:00', '16:30:00');
        $this->createSessionRecord($course->ID, '2026-04-27', '17:00:00', '18:00:00');
        $this->createSessionRecord($course->ID, '2026-04-27', '19:00:00', '20:00:00');
        StudentSignIn::create(['StudentClassID' => $course->ID, 'StudentID' => $student->id, 'TeacherID' => 159, 'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => '2026-04-27 15:30:00', 'MDT' => now(), 'ClassSessionID' => $locked->id, 'Status' => 'present', 'SessionDeducted' => 1]);

        $resp = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/student-classes/{$course->ID}", [
                'subject' => 'English', 'class_type' => 'one_on_two', 'duration_hours' => 1,
                'days_of_week' => [1], 'start_time' => '15:00', 'payment_type' => 'session',
                'day_time_slots' => [['day' => 1, 'start_time' => '15:00', 'duration_minutes' => 60], ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60]],
            ]);
        $resp->assertStatus(409);
        $this->assertSame('teacher_schedule_conflict', $resp->json('code'));
        $this->assertStringContainsString('會與自己已鎖定或保留的堂次時間重疊', (string) $resp->json('message'));
        $this->assertCount(1, array_filter($resp->json('conflicts'), fn ($c) => ($c['schedule_date'] ?? '') === '2026-04-27'));

        $this->assertSame(['15:30', '17:00', '19:00'], $this->startsOn($course, '2026-04-27'));
    }

    /** Changing the teacher in the same edit must still plan (and self-check) the course's existing sessions. */
    public function test_teacher_change_with_slot_edit_still_checks_own_sessions(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create(['name' => '換老師', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $course = $this->createCourseRecord($student->id, 159, ['ClassType' => 'one_on_two']);
        $locked = $this->createSessionRecord($course->ID, '2026-04-27', '15:30:00', '16:30:00');
        $this->createSessionRecord($course->ID, '2026-04-27', '17:00:00', '18:00:00');
        $this->createSessionRecord($course->ID, '2026-04-27', '19:00:00', '20:00:00');
        StudentSignIn::create(['StudentClassID' => $course->ID, 'StudentID' => $student->id, 'TeacherID' => 159, 'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => '2026-04-27 15:30:00', 'MDT' => now(), 'ClassSessionID' => $locked->id, 'Status' => 'present', 'SessionDeducted' => 1]);
        $newTeacher = \App\Models\User::create(['LoginName' => 't160_' . uniqid() . '@x.com', 'Name' => 'T2', 'PSW' => 'x', 'type' => 'T', 'phone' => 912000160]);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/student-classes/{$course->ID}", [
                'subject' => 'English', 'class_type' => 'one_on_two', 'duration_hours' => 1, 'teacher_id' => $newTeacher->id,
                'days_of_week' => [1], 'start_time' => '15:00', 'payment_type' => 'session',
                'day_time_slots' => [['day' => 1, 'start_time' => '15:00', 'duration_minutes' => 60], ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60]],
            ])->assertStatus(409);
        $this->assertSame(['15:30', '17:00', '19:00'], $this->startsOn($course, '2026-04-27'));
    }

    /** Sync pairs new slots with unlocked rows only: a locked 09:00 row must not shift the pairing (15:00→20:00, 17:00→21:00). */
    public function test_sync_pairs_slots_with_unlocked_rows_only(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create(['name' => '鎖定配對', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $course = $this->createCourseRecord($student->id, 159);
        $locked = $this->createSessionRecord($course->ID, '2026-04-27', '09:00:00', '10:00:00');
        $this->createSessionRecord($course->ID, '2026-04-27', '15:00:00', '16:00:00');
        $this->createSessionRecord($course->ID, '2026-04-27', '17:00:00', '18:00:00');
        StudentSignIn::create(['StudentClassID' => $course->ID, 'StudentID' => $student->id, 'TeacherID' => 159, 'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => '2026-04-27 09:00:00', 'MDT' => now(), 'ClassSessionID' => $locked->id, 'Status' => 'present', 'SessionDeducted' => 1]);

        $this->updateFixedSlots($token, $course, [1], [['day' => 1, 'start_time' => '20:00', 'duration_minutes' => 60], ['day' => 1, 'start_time' => '21:00', 'duration_minutes' => 60]])->assertOk();
        $starts = ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-04-27')
            ->orderBy('StartTime')->pluck('StartTime')->map(fn ($t) => substr((string) $t, 0, 5))->all();
        $this->assertSame(['09:00', '20:00', '21:00'], $starts);
    }

    /** A date whose only own row is a pending leave is still self-checked (shared class dedupes the student). */
    public function test_shared_class_pending_leave_only_date_is_self_checked(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create(['name' => '共享請假', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $course = $this->createCourseRecord($student->id, 159, ['ClassType' => 'one_on_two']);
        $this->createSessionRecord($course->ID, '2026-04-27', '17:00:00', '18:00:00', 'leave_requested');

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/student-classes/{$course->ID}", [
                'subject' => 'English', 'class_type' => 'one_on_two', 'duration_hours' => 1,
                'days_of_week' => [1], 'start_time' => '17:30', 'payment_type' => 'session',
                'day_time_slots' => [['day' => 1, 'start_time' => '17:30', 'duration_minutes' => 60]],
            ])->assertStatus(409);
    }

    /** @return list<string> H:i starts of the course's sessions on $date */
    private function startsOn(StudentClass $course, string $date): array
    {
        return ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', $date)
            ->orderBy('StartTime')->pluck('StartTime')->map(fn ($t) => substr((string) $t, 0, 5))->all();
    }

    private function updateFixedSlots(string $token, StudentClass $course, array $days, ?array $slots = null)
    {
        $slots = $slots ?? array_map(fn ($d) => ['day' => $d, 'start_time' => '17:00', 'duration_minutes' => 60], $days);
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/student-classes/{$course->ID}", [
                'subject' => 'English', 'class_type' => 'one_on_one', 'duration_hours' => 1,
                'days_of_week' => $days, 'start_time' => '17:00', 'day_time_slots' => $slots,
                'payment_type' => 'session',
            ]);
    }

    private function createSessionRecord(int $courseId, string $date, string $start, string $end, string $status = 'scheduled', int $isEx = 0): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => $date, 'StartTime' => $start,
            'EndTime' => $end, 'Status' => $status, 'IsContractException' => $isEx,
        ]);
    }

    private function createCourseRecord(int $studentId, int $teacherId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => \App\Services\FrontendSubjectIdResolver::resolve('English'), 'TeacherID' => $teacherId, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-04-01', 'TotalHours' => 10, 'Charge' => 0, 'Paid' => 0, 'Rate' => 500, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 6, 'SessionDuration' => 60, 'RemainingSessions' => 5, 'UsedSessions' => 1,
            'ClassType' => 'one_on_one', 'week' => 1, 'time' => '17:00:00',
        ], $overrides));
    }

    private function seedMondayCourseWithWednesdayException(): array
    {
        $token = $this->createDirectorToken([1]);
        $teacherId = 159;
        $student = Student::create(['name' => '排課吸收測試學生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $course = $this->createCourseRecord($student->id, $teacherId);
        $past = $this->createSessionRecord($course->ID, '2026-04-06', '17:00:00', '18:00:00', 'attended', 0);
        StudentSignIn::create(['StudentClassID' => $course->ID, 'StudentID' => $student->id, 'TeacherID' => $teacherId, 'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => '2026-04-06 17:00:00', 'MDT' => now(), 'ClassSessionID' => $past->id, 'Status' => 'present', 'SessionDeducted' => 1]);
        foreach (['2026-04-13', '2026-04-20', '2026-04-27', '2026-05-04'] as $d) {
            $this->createSessionRecord($course->ID, $d, '17:00:00', '18:00:00', 'scheduled', 0);
        }
        $this->createSessionRecord($course->ID, '2026-04-15', '17:00:00', '18:00:00', 'scheduled', 1);
        return [$token, $course, $teacherId];
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create(['LoginName' => 'dir-rec-absorb-' . bin2hex(random_bytes(4)), 'Name' => '主任測試', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678']);
        foreach ($campusIds as $cid) {
            UserCampus::create(['UserID' => $user->id, 'CampusID' => $cid]);
        }
        $token = 'test-token-' . bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }
}
