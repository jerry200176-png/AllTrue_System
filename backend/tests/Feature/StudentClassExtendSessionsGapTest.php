<?php

namespace Tests\Feature;

use App\Http\Controllers\StudentClassController;
use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentClassExtendSessionsGapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_extend_sessions_fills_missing_contract_gap_before_appending_tail_session(): void
    {
        $student = Student::create([
            'name' => '簡采柔',
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
            'StartDate' => '2026-04-01',
            'TotalHours' => 24,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 500,
            'RoomID' => '1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 12,
            'SessionDuration' => 120,
            'RemainingSessions' => 12,
            'UsedSessions' => 0,
            'ClassType' => 'one_on_one',
            'week' => 3,
            'time' => '18:00:00',
        ]);

        foreach ([
            '2026-04-01', '2026-04-08', '2026-04-15', '2026-04-22',
            // 2026-04-29 is missing; old code appended 2026-06-24 as the 13th visible session.
            '2026-05-06', '2026-05-13', '2026-05-20', '2026-05-27',
            '2026-06-03', '2026-06-10', '2026-06-17', '2026-06-24',
        ] as $date) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => '18:00:00',
                'EndTime' => '20:00:00',
                'Status' => 'scheduled',
            ]);
        }

        app(StudentClassController::class)->extendSessionsIfNeeded($course, 12);

        $activeDates = ClassSession::where('StudentClassID', $course->ID)
            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted', 'excused'])
            ->orderBy('SessionDate')
            ->pluck('SessionDate')
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->all();

        $this->assertSame([
            '2026-04-01', '2026-04-08', '2026-04-15', '2026-04-22',
            '2026-04-29',
            '2026-05-06', '2026-05-13', '2026-05-20', '2026-05-27',
            '2026-06-03', '2026-06-10', '2026-06-17',
        ], $activeDates);

        $this->assertSame(
            'cancelled',
            (string) ClassSession::where('StudentClassID', $course->ID)
                ->whereDate('SessionDate', '2026-06-24')
                ->value('Status')
        );
    }

    /**
     * Count-mode refill must not recreate a scheduled session on the same date/time
     * as an admin-cancelled ClassSession (FR: 取消了又補回同一堂).
     */
    public function test_extend_sessions_does_not_resurrect_cancelled_contract_day(): void
    {
        $student = Student::create([
            'name' => '張測試',
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
            'StartDate' => '2026-04-01',
            'TotalHours' => 24,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 500,
            'RoomID' => '1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 12,
            'SessionDuration' => 120,
            'RemainingSessions' => 12,
            'UsedSessions' => 0,
            'ClassType' => 'one_on_one',
            'week' => 3,
            'time' => '18:00:00',
        ]);

        $dates = [
            '2026-04-01', '2026-04-08', '2026-04-15', '2026-04-22',
            '2026-04-29', '2026-05-06', '2026-05-13', '2026-05-20', '2026-05-27',
            '2026-06-03', '2026-06-10', '2026-06-17',
        ];
        foreach ($dates as $date) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => '18:00:00',
                'EndTime' => '20:00:00',
                'Status' => 'scheduled',
            ]);
        }

        ClassSession::where('StudentClassID', $course->ID)
            ->whereDate('SessionDate', '2026-05-13')
            ->update(['Status' => 'cancelled']);

        app(StudentClassController::class)->extendSessionsIfNeeded($course, 12);

        $may13 = ClassSession::where('StudentClassID', $course->ID)
            ->whereDate('SessionDate', '2026-05-13')
            ->orderBy('id')
            ->get();

        $this->assertCount(1, $may13, 'must not insert a second ClassSession on the cancelled date');
        $this->assertSame('cancelled', strtolower((string) $may13[0]->Status));

        $active = ClassSession::where('StudentClassID', $course->ID)
            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted', 'excused'])
            ->count();
        $this->assertSame(12, $active);
    }

    public function test_same_day_class_sessions_read_materializes_sparse_count_course_for_teacher_roster(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 08:00:00', 'Asia/Taipei'));

        $teacher = User::create([
            'LoginName' => 'teacher-sparse-count@example.com',
            'Name' => '陸逸測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0900000200',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 3, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $rawToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacher->id, 'token' => $rawToken, 'expires_at' => now()->addDay()]);

        $student = Student::create([
            'name' => '周宏謙測試',
            'CampusID' => 3,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacher->id,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-06-06',
            'TotalHours' => 24,
            'Charge' => 13200,
            'Paid' => 1,
            'Rate' => 1100,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 12,
            'SessionDuration' => 120,
            'RemainingSessions' => 10,
            'UsedSessions' => 2,
            'ClassType' => 'one_on_three',
            'week' => 6,
            'time' => '10:00:00',
        ]);

        foreach (['2026-06-06', '2026-06-13'] as $date) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => '10:00:00',
                'EndTime' => '12:00:00',
                'Status' => 'attended',
            ]);
        }

        $this->withHeaders([
            'Authorization' => "Bearer {$rawToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?start=2026-06-20&end=2026-06-20&per_page=100')
            ->assertOk()
            ->assertJsonFragment([
                'student_class_id' => $course->ID,
                'student_id' => $student->id,
                'session_date' => '2026-06-20',
                'start_time' => '10:00',
                'status' => 'scheduled',
            ]);

        $activeCount = ClassSession::where('StudentClassID', $course->ID)
            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted', 'excused'])
            ->count();

        $this->assertSame(12, $activeCount);
        $course->refresh();
        $this->assertSame(10, (int) $course->RemainingSessions, 'materialization must not alter remaining sessions');
        $this->assertSame(2, (int) $course->UsedSessions, 'materialization must not alter used sessions');
    }
}
