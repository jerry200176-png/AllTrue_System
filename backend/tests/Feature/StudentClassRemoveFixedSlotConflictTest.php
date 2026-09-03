<?php

namespace Tests\Feature;

use App\Http\Controllers\StudentClassController;
use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Exceptions\SlotOccupiedException;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentClassRemoveFixedSlotConflictTest extends TestCase
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

    public function test_removing_thursday_does_not_conflict_with_retained_locked_wednesday(): void
    {
        [$token, $course] = $this->seedWednesdayThursdayCourse();

        $retainedWednesday = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-04-15',
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'scheduled',
        ]);
        LearningRecord::create([
            'StudentClassID' => $course->ID,
            'ClassSessionID' => $retainedWednesday->id,
            'TeacherID' => 99,
            'Subject' => 'Math',
            'Content' => 'approved history',
            'SessionDate' => '2026-04-15',
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'approved',
        ]);
        $removedThursday = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-04-16',
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'scheduled',
        ]);

        $response = $this->updateFixedSlots($token, $course, [3], [
            ['day' => 3, 'start_time' => '16:00', 'duration_minutes' => 120],
        ]);

        $response->assertOk();
        $this->assertSame(3, (int) $course->fresh()->week);
        $this->assertNull($course->fresh()->week1);
        $this->assertNotSame(
            '該時段已有課程，無法調課至此時段（請先取消原時段的課或改選其他時段）',
            (string) $response->json('message'),
            'Removing a fixed slot must not validate the retained slot as a new move.'
        );
        $this->assertSame('2026-04-22', substr((string) $removedThursday->fresh()->SessionDate, 0, 10));
    }

    public function test_removing_thursday_from_unlocked_weekly_schedule_saves(): void
    {
        [$token, $course] = $this->seedWednesdayThursdayCourse();

        foreach (['2026-04-15', '2026-04-16', '2026-04-22', '2026-04-23'] as $date) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => '16:00:00',
                'EndTime' => '18:00:00',
                'Status' => 'scheduled',
            ]);
        }

        $response = $this->updateFixedSlots($token, $course, [3], [
            ['day' => 3, 'start_time' => '16:00', 'duration_minutes' => 120],
        ]);

        $response->assertOk();
        $this->assertSame(3, (int) $course->fresh()->week);
        $this->assertNull($course->fresh()->week1);
    }

    public function test_true_new_target_occupied_by_locked_session_still_throws(): void
    {
        [$token, $course] = $this->seedWednesdayThursdayCourse();
        $source = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-04-16',
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'scheduled',
        ]);
        ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-04-15',
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'attended',
        ]);

        $controller = app(StudentClassController::class);
        $method = new \ReflectionMethod($controller, 'remapFutureScheduledSessionsToContract');
        $method->setAccessible(true);

        $this->expectException(SlotOccupiedException::class);
        $method->invoke(
            $controller,
            collect([$source]),
            [['weekday' => 3, 'time' => '16:00', 'duration_minutes' => 120]],
            120
        );
    }

    private function updateFixedSlots(string $token, StudentClass $course, array $days, array $slots)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", [
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'duration_hours' => 2,
            'days_of_week' => $days,
            'start_time' => '16:00',
            'day_time_slots' => $slots,
            'payment_type' => 'session',
        ]);
    }

    private function seedWednesdayThursdayCourse(): array
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '固定時段移除測試',
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
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-03-01',
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 500,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 4,
            'UsedSessions' => 4,
            'ClassType' => 'one_on_one',
            'week' => 3,
            'time' => '16:00:00',
            'week1' => 4,
            'time1' => '16:00:00',
        ]);

        $past = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-04-08',
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'attended',
        ]);
        StudentSignIn::create([
            'StudentClassID' => $course->ID,
            'StudentID' => $student->id,
            'TeacherID' => 99,
            'GradeID' => 1,
            'SubjectID' => 1,
            'CampusID' => 1,
            'SignInDT' => '2026-04-08 16:00:00',
            'MDT' => now(),
            'ClassSessionID' => $past->id,
            'Status' => 'present',
            'SessionDeducted' => 1,
        ]);

        return [$token, $course];
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director-remove-slot-' . bin2hex(random_bytes(4)) . '@test.com',
            'Name' => '主任測試',
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
}
