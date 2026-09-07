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

        $response = $this->updateFixedSlots($token, $course, [1, 3], [
            ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60],
            ['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60],
        ]);
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

        $response = $this->updateFixedSlots($token, $course, [1, 3], [
            ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60],
            ['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60],
        ]);
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

        $response = $this->updateFixedSlots($token, $course, [1, 3], [
            ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60],
            ['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60],
        ]);
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

        $response = $this->updateFixedSlots($token, $course, [1, 3], [
            ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60],
            ['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60],
        ]);
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

        $response = $this->updateFixedSlots($token, $course, [1, 3], [
            ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60],
            ['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60],
        ]);
        $response->assertStatus(409);
        $this->assertSame('teacher_schedule_conflict', $response->json('code'));
    }

    /**
     * Requirement 5: Repeated request is idempotent and creates no duplicate data.
     */
    public function test_repeated_request_is_idempotent_and_creates_no_duplicate_data(): void
    {
        [$token, $course] = $this->seedMondayCourseWithWednesdayException();
        $payload = [
            ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60],
            ['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60],
        ];

        $this->updateFixedSlots($token, $course, [1, 3], $payload)->assertOk();
        $firstIds = ClassSession::where('StudentClassID', $course->ID)->orderBy('id')->pluck('id')->all();

        $this->updateFixedSlots($token, $course, [1, 3], $payload)->assertOk();
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

        $response = $this->updateFixedSlots($token, $course, [1, 3], [
            ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60],
            ['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60],
        ]);
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
        $mockReflow->shouldReceive('move')->andThrow(
            new SlotOccupiedException((int) $course->ID, '2026-04-20', '17:00:00', (int) $wedSession->id)
        );
        $this->app->instance(ClassSessionContractReflowService::class, $mockReflow);

        $response = $this->updateFixedSlots($token, $course, [1, 3], [
            ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60],
            ['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60],
        ]);

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
            'StudentClassID' => $course->ID,
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'CampusID' => 1,
            'SignInDT' => '2026-04-15 17:00:00',
            'MDT' => now(),
            'ClassSessionID' => $wedSession->id,
            'Status' => 'present',
            'SessionDeducted' => 1,
        ]);

        $response = $this->updateFixedSlots($token, $course, [1, 3], [
            ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60],
            ['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60],
        ]);
        $response->assertOk();

        $this->assertSame(0, (int) $wedSession->fresh()->IsContractException);
        $this->assertSame('2026-04-15', substr((string) $wedSession->fresh()->SessionDate, 0, 10));
        $this->assertSame('17:00:00', (string) $wedSession->fresh()->StartTime);
        $this->assertSame('18:00:00', (string) $wedSession->fresh()->EndTime);
        $this->assertSame(1, ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-04-15')->count());
        $this->assertSame((int) $wedSession->id, (int) $signIn->fresh()->ClassSessionID);
    }

    private function updateFixedSlots(string $token, StudentClass $course, array $days, array $slots)
    {
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
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacherId,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 10, 'Charge' => 0,
            'Paid' => 0, 'Rate' => 500, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionCount' => 6, 'SessionDuration' => 60, 'RemainingSessions' => 5, 'UsedSessions' => 1,
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

        $this->createSessionRecord($course->ID, '2026-04-13', '17:00:00', '18:00:00', 'scheduled', 0);
        $this->createSessionRecord($course->ID, '2026-04-15', '17:00:00', '18:00:00', 'scheduled', 1);
        $this->createSessionRecord($course->ID, '2026-04-20', '17:00:00', '18:00:00', 'scheduled', 0);
        $this->createSessionRecord($course->ID, '2026-04-27', '17:00:00', '18:00:00', 'scheduled', 0);
        $this->createSessionRecord($course->ID, '2026-05-04', '17:00:00', '18:00:00', 'scheduled', 0);

        return [$token, $course, $teacherId];
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-rec-absorb-' . bin2hex(random_bytes(4)), 'Name' => '主任測試',
            'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678',
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['UserID' => $user->id, 'CampusID' => $cid]);
        }
        $token = 'test-token-' . bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }
}
