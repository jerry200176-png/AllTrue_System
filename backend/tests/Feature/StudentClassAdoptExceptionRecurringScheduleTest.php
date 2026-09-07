<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ScheduleGuardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentClassAdoptExceptionRecurringScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze test clock to Sunday 2026-04-12
        Carbon::setTestNow(Carbon::parse('2026-04-12 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Requirement 1: Exception covered by new fixed schedule succeeds without 409
     * and is regularized (IsContractException cleared to 0).
     */
    public function test_exception_session_covered_by_new_fixed_schedule_succeeds_without_409_and_regularizes(): void
    {
        [$token, $course, $teacherId] = $this->seedMondayCourseWithWednesdayException();

        $wednesdaySession = ClassSession::where('StudentClassID', $course->ID)
            ->whereDate('SessionDate', '2026-04-15')
            ->firstOrFail();

        $this->assertSame(1, (int) $wednesdaySession->IsContractException, 'Initially should be an exception');

        // Add Wednesday (day 3) 17:00-18:00 to the fixed schedule
        $response = $this->updateFixedSlots($token, $course, [1, 3], [
            ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60],
            ['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60],
        ]);

        if ($response->status() !== 200) {
            dump($response->json());
        }

        $response->assertOk();

        // Fresh check of the session: IsContractException must now be 0 (adopted)
        $this->assertSame(0, (int) $wednesdaySession->fresh()->IsContractException, 'Exception must be regularized');
        $this->assertSame('2026-04-15', substr((string) $wednesdaySession->fresh()->SessionDate, 0, 10));
        $this->assertSame('17:00:00', (string) $wednesdaySession->fresh()->StartTime);

        // Course schedule should now reflect week 1 and week 3
        $freshCourse = $course->fresh();
        $this->assertSame(1, (int) $freshCourse->week);
        $this->assertSame(3, (int) $freshCourse->week1);
    }

    /**
     * Requirement 2: No duplicate ClassSession created during exception adoption.
     */
    public function test_adoption_does_not_create_duplicate_class_session(): void
    {
        [$token, $course, $teacherId] = $this->seedMondayCourseWithWednesdayException();

        $initialTotalCount = ClassSession::where('StudentClassID', $course->ID)->count();
        $initialWedSession = ClassSession::where('StudentClassID', $course->ID)
            ->whereDate('SessionDate', '2026-04-15')
            ->firstOrFail();

        $response = $this->updateFixedSlots($token, $course, [1, 3], [
            ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60],
            ['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60],
        ]);

        $response->assertOk();

        // Exactly one session on Wednesday 2026-04-15
        $wedSessions = ClassSession::where('StudentClassID', $course->ID)
            ->whereDate('SessionDate', '2026-04-15')
            ->get();

        $this->assertCount(1, $wedSessions, 'Must have exactly one session on 2026-04-15, no duplicates');
        $this->assertSame($initialWedSession->id, $wedSessions->first()->id, 'Must reuse the existing session ID');

        // Total count of sessions must not have grown
        $afterTotalCount = ClassSession::where('StudentClassID', $course->ID)->count();
        $this->assertSame($initialTotalCount, $afterTotalCount, 'Total session count must remain constant');
    }

    /**
     * Requirement 3: Genuine external conflict still results in 409.
     */
    public function test_genuine_external_conflict_still_returns_409(): void
    {
        [$token, $course, $teacherId] = $this->seedMondayCourseWithWednesdayException();

        // Create an external student with a one_on_one session with the same teacher on Wednesday 17:00-18:00
        $externalStudent = Student::create([
            'name' => '外部衝突學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $externalCourse = StudentClass::create([
            'StudentID' => $externalStudent->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'ClassType' => 'one_on_one',
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-01',
            'TotalHours' => 10,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 500,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 10,
            'SessionDuration' => 60,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'week' => 5,
            'time' => '17:00:00',
        ]);
        ClassSession::create([
            'StudentClassID' => $externalCourse->ID,
            'SessionDate' => '2026-04-22',
            'StartTime' => '17:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'scheduled',
            'IsContractException' => 0,
        ]);

        // Attempting to add Wednesday 17:00-18:00 must be rejected due to external teacher capacity conflict
        $response = $this->updateFixedSlots($token, $course, [1, 3], [
            ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60],
            ['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60],
        ]);

        $response->assertStatus(409);
        $this->assertSame('teacher_schedule_conflict', $response->json('code'));
        $this->assertNotEmpty($response->json('conflicts'));
    }

    /**
     * Requirement 4a: Non-corresponding exception (different time) is NOT incorrectly absorbed.
     */
    public function test_non_corresponding_exception_at_different_time_is_not_absorbed(): void
    {
        [$token, $course, $teacherId] = $this->seedMondayCourseWithWednesdayException();

        // Add another exception session on Wednesday at 19:00-20:00
        $eveningException = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-04-22',
            'StartTime' => '19:00:00',
            'EndTime' => '20:00:00',
            'Status' => 'scheduled',
            'IsContractException' => 1,
        ]);

        // Add Wednesday 17:00-18:00 to fixed slots (19:00 is NOT part of this schedule)
        $response = $this->updateFixedSlots($token, $course, [1, 3], [
            ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60],
            ['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60],
        ]);

        $response->assertOk();

        // The 19:00 session must remain an exception!
        $this->assertSame(1, (int) $eveningException->fresh()->IsContractException, 'Non-matching time must remain exception');
    }

    /**
     * Requirement 4b: Non-corresponding exception that partially overlaps the new recurring slot
     * is NOT absorbed and is correctly reported as a conflict.
     */
    public function test_partially_overlapping_exception_is_rejected_as_conflict(): void
    {
        [$token, $course, $teacherId] = $this->seedMondayCourseWithWednesdayException();

        // Delete the 17:00-18:00 exception to isolate this test
        ClassSession::where('StudentClassID', $course->ID)
            ->whereDate('SessionDate', '2026-04-15')
            ->delete();

        // Create a partially overlapping exception on Wednesday: 16:30 - 17:30
        ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-04-15',
            'StartTime' => '16:30:00',
            'EndTime' => '17:30:00',
            'Status' => 'scheduled',
            'IsContractException' => 1,
        ]);

        // Attempt to add Wednesday 17:00-18:00 (overlaps with 16:30-17:30)
        $response = $this->updateFixedSlots($token, $course, [1, 3], [
            ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60],
            ['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60],
        ]);

        // Must fail with 409 because 16:30-17:30 is a true intra-course overlap conflict
        $response->assertStatus(409);
        $this->assertSame('teacher_schedule_conflict', $response->json('code'));
    }

    /**
     * Requirement 5: Repeated request / edit is idempotent and creates no duplicate data.
     */
    public function test_repeated_request_is_idempotent_and_creates_no_duplicate_data(): void
    {
        [$token, $course, $teacherId] = $this->seedMondayCourseWithWednesdayException();

        $payload = [
            ['day' => 1, 'start_time' => '17:00', 'duration_minutes' => 60],
            ['day' => 3, 'start_time' => '17:00', 'duration_minutes' => 60],
        ];

        // First update: absorbs Wednesday exception
        $res1 = $this->updateFixedSlots($token, $course, [1, 3], $payload);
        $res1->assertOk();

        $sessionsAfterFirst = ClassSession::where('StudentClassID', $course->ID)
            ->orderBy('id')
            ->get();
        $firstIds = $sessionsAfterFirst->pluck('id')->all();
        $firstCount = count($firstIds);

        // Second update: identical payload
        $res2 = $this->updateFixedSlots($token, $course, [1, 3], $payload);
        $res2->assertOk();

        $sessionsAfterSecond = ClassSession::where('StudentClassID', $course->ID)
            ->orderBy('id')
            ->get();
        $secondIds = $sessionsAfterSecond->pluck('id')->all();

        $this->assertSame($firstCount, count($secondIds), 'Repeated request must not change session count');
        $this->assertSame($firstIds, $secondIds, 'Repeated request must preserve identical session IDs');
    }

    private function updateFixedSlots(string $token, StudentClass $course, array $days, array $slots)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", [
            'subject' => 'English',
            'class_type' => 'one_on_one',
            'duration_hours' => 1,
            'days_of_week' => $days,
            'start_time' => '17:00',
            'day_time_slots' => $slots,
            'payment_type' => 'session',
        ]);
    }

    private function seedMondayCourseWithWednesdayException(): array
    {
        $token = $this->createDirectorToken([1]);
        $teacherId = 159;

        $student = Student::create([
            'name' => '排課吸收測試學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-01',
            'TotalHours' => 10,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 500,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 6,
            'SessionDuration' => 60,
            'RemainingSessions' => 5,
            'UsedSessions' => 1,
            'ClassType' => 'one_on_one',
            'week' => 1, // Monday
            'time' => '17:00:00',
        ]);

        // Past session on Monday 2026-04-06
        $past = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-04-06',
            'StartTime' => '17:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'attended',
            'IsContractException' => 0,
        ]);
        StudentSignIn::create([
            'StudentClassID' => $course->ID,
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'CampusID' => 1,
            'SignInDT' => '2026-04-06 17:00:00',
            'MDT' => now(),
            'ClassSessionID' => $past->id,
            'Status' => 'present',
            'SessionDeducted' => 1,
        ]);

        // Future Monday sessions (2026-04-13, 2026-04-20, 2026-04-27, 2026-05-04)
        foreach (['2026-04-13', '2026-04-20', '2026-04-27', '2026-05-04'] as $date) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => '17:00:00',
                'EndTime' => '18:00:00',
                'Status' => 'scheduled',
                'IsContractException' => 0,
            ]);
        }

        // Future Exception session on Wednesday 2026-04-15 17:00-18:00
        ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-04-15',
            'StartTime' => '17:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'scheduled',
            'IsContractException' => 1,
        ]);

        return [$token, $course, $teacherId];
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director-exception-test-' . bin2hex(random_bytes(4)) . '@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['UserID' => $user->id, 'CampusID' => $cid]);
        }
        $token = 'test-token-' . bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);
        return $token;
    }
}
