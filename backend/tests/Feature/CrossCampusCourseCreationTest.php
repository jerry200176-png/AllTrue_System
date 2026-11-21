<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ScheduleGuardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The course-creation path the screens (and AI-assisted entry through them) use, POST class-sessions/batch:
 * a future clash at another campus is refused, a past one is saved with a reminder header, a clean one is untouched.
 */
class CrossCampusCourseCreationTest extends TestCase
{
    use RefreshDatabase;

    private int $teacher;
    private string $token;
    private string $future;
    private string $past;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = (int) User::create(['LoginName' => 'creation-teacher@example.com', 'Name' => '老師', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922000000'])->id;
        foreach ([1, 2] as $campus) {
            UserCampus::create(['CampusID' => $campus, 'UserID' => $this->teacher, 'Admin' => 0, 'Approved' => 1]);
        }
        $director = User::create(['LoginName' => 'creation-director@example.com', 'Name' => '主任', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0900000000']);
        UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);

        // The teacher already teaches at campus 2, 16:00–17:00, one future and one past day.
        $this->future = Carbon::today()->addWeek()->toDateString();
        $this->past = Carbon::today()->subWeek()->toDateString();
        $other = $this->course(2, '他校生');
        foreach ([[$this->future, 'scheduled'], [$this->past, 'attended']] as [$date, $status]) {
            ClassSession::create(['StudentClassID' => $other->ID, 'SessionDate' => $date,
                'StartTime' => '16:00:00', 'EndTime' => '17:00:00', 'Status' => $status]);
        }
    }

    public function test_a_future_clash_is_refused_and_nothing_is_created(): void
    {
        $before = StudentClass::count();
        $this->create(['future_dates' => [$this->future], 'start_time' => '16:30'])
            ->assertStatus(409)->assertJsonPath('conflicts.0.type', 'cross_campus');
        $this->assertSame($before, StudentClass::count());
    }

    public function test_a_clean_slot_is_created_without_a_reminder(): void
    {
        $res = $this->create(['future_dates' => [$this->future], 'start_time' => '17:00'])->assertCreated();
        $this->assertFalse($res->headers->has(ScheduleGuardService::PAST_CLASH_HEADER));
    }

    public function test_a_past_clash_is_saved_with_a_reminder(): void
    {
        $res = $this->create(['confirmed_dates' => [$this->past], 'start_time' => '16:30'])->assertCreated();
        $reminder = rawurldecode((string) $res->headers->get(ScheduleGuardService::PAST_CLASH_HEADER));
        $this->assertStringContainsString('提醒', $reminder);
        $this->assertStringContainsString($this->past, $reminder);
        $this->assertStringNotContainsString('他校生', $reminder, 'other campus students stay private');
    }

    public function test_the_guard_notes_but_never_returns_a_past_clash(): void
    {
        $guard = app(ScheduleGuardService::class);
        $this->assertNull($guard->crossCampusConflict($this->teacher, 1, $this->past, '16:30', '17:30'));
        $this->assertCount(1, (array) request()->attributes->get(ScheduleGuardService::PAST_CLASH_ATTRIBUTE));
        $this->assertSame('cross_campus', $guard->crossCampusConflict($this->teacher, 1, $this->future, '16:30', '17:30')['type'] ?? null);
    }

    public function test_a_renewal_landing_on_the_other_campus_lesson_is_refused(): void
    {
        $source = $this->course(1, '續課生', [
            'Paid' => 1, 'Charge' => 4000, 'UsedSessions' => 7, 'RemainingSessions' => 1,
            'week' => Carbon::parse($this->future)->dayOfWeekIso, 'time' => '16:00:00',
        ]);
        $before = StudentClass::count();
        $this->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch",
            ['sessions' => 2, 'start_date' => $this->future, 'mode' => 'new_purchase'],
            ['Authorization' => "Bearer {$this->token}"])
            ->assertStatus(409)->assertJsonPath('conflicts.0.type', 'cross_campus');
        $this->assertSame($before, StudentClass::count(), 'the renewed course is rolled back');
    }

    private function create(array $overrides): \Illuminate\Testing\TestResponse
    {
        $student = Student::create(['name' => '新生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return $this->postJson('/api/v1/class-sessions/batch', $overrides + [
            'branch_id' => 1, 'student_id' => $student->id, 'teacher_id' => $this->teacher, 'subject' => 'Math',
            'class_type' => 'one_on_one', 'confirmed_dates' => [], 'future_dates' => [], 'duration_minutes' => 60,
            'price_per_session' => 500, 'payment_type' => 'session', 'total_classes' => 1,
        ], ['Authorization' => "Bearer {$this->token}"]);
    }

    private function course(int $campus, string $name, array $overrides = []): StudentClass
    {
        $studentId = Student::create(['name' => $name, 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => ''])->id;
        $id = DB::table('StudentClass')->insertGetId($overrides + [
            'StudentID' => $studentId, 'TeacherID' => $this->teacher, 'ClassType' => 'one_on_one',
            'GradeID' => 1, 'SubjectID' => 1, 'by1' => 1, 'Period' => 4, 'StartDate' => Carbon::today()->subMonth()->toDateString(),
            'TotalHours' => 16, 'SessionCount' => 8, 'SessionDuration' => 60, 'RemainingSessions' => 8,
            'UsedSessions' => 0, 'Charge' => 500, 'Pay' => 0, 'Paid' => 0, 'Rate' => 500, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);

        return StudentClass::findOrFail($id);
    }
}
