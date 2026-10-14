<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * In-app #406: saving a course whose teacher, class type, room and weekly slots are
 * unchanged must not be blocked by the recurring capacity guard, even when the slot is
 * already full. Changing the slot is still checked.
 */
class StudentClassUpdateNoAddedLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\DB::table('Subject')->where('Subject_Name', 'Math')->where('id', '<>', 1)->update(['Subject_Name' => 'Math (legacy)']); \Illuminate\Support\Facades\DB::table('Subject')->updateOrInsert(['id' => 1], ['School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => 'Math']); // fixtures use SubjectID 1 + subject=Math (ids are not reset between tests)
        Carbon::setTestNow(Carbon::parse('2026-04-12 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unchanged_schedule_saves_even_when_the_slot_is_already_full(): void
    {
        [$token, $course] = $this->seedDoubleBookedSlot();

        $this->update($token, $course, '15:00')->assertOk();
    }

    public function test_moving_into_the_occupied_slot_is_still_rejected(): void
    {
        [$token, $course] = $this->seedDoubleBookedSlot();

        $this->update($token, $course, '15:30')
            ->assertStatus(409)
            ->assertJsonPath('code', 'teacher_schedule_conflict');
    }

    private function update(string $token, StudentClass $course, string $start)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", [
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'duration_hours' => 2,
            'payment_type' => 'session',
            'days_of_week' => [7],
            'start_time' => $start,
            'day_time_slots' => [['day' => 7, 'start_time' => $start, 'duration_minutes' => 120]],
            'first_class_date' => '2026-03-01',
        ]);
    }

    /** @return array{0: string, 1: StudentClass} */
    private function seedDoubleBookedSlot(): array
    {
        $token = $this->createDirectorToken([1]);
        $courses = [];
        foreach (['滿堂既有學生甲', '滿堂既有學生乙'] as $name) {
            $student = Student::create([
                'name' => $name,
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);
            $courses[] = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => 99,
                'ClassType' => 'one_on_one',
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-03-01',
                'TotalHours' => 16,
                'Charge' => 0,
                'Paid' => 0,
                'Rate' => 500,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 8,
                'SessionDuration' => 120,
                'RemainingSessions' => 8,
                'UsedSessions' => 0,
                'week' => 7,
                'time' => '15:00:00',
            ]);
        }

        return [$token, $courses[0]];
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director-no-added-load-' . bin2hex(random_bytes(4)) . '@test.com',
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
