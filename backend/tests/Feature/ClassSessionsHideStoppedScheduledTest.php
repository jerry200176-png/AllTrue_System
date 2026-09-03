<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-07-18 Xindian attendance duplicate family (R20 / #189):
 * GET /api/v1/class-sessions must not return Stop=1 scheduled rows by default,
 * while still returning attended history on stopped courses.
 */
class ClassSessionsHideStoppedScheduledTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_index_hides_stop1_scheduled_but_keeps_attended(): void
    {
        [$token, $activeId, $orphanId, $attendedId] = $this->seedStop1Overlap();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?start=2026-07-18&end=2026-07-18&per_page=100');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($i) => (int) $i)->all();

        $this->assertContains($activeId, $ids, 'active course scheduled must remain visible');
        $this->assertNotContains($orphanId, $ids, 'Stop=1 scheduled must be hidden by default');
        $this->assertContains($attendedId, $ids, 'Stop=1 attended history must still be visible');

        $activeRow = collect($res->json('data'))->firstWhere('id', $activeId);
        $this->assertSame(0, (int) ($activeRow['course_stop'] ?? -1));
        $this->assertGreaterThan(0, (int) ($activeRow['course_session_count'] ?? 0));
    }

    public function test_include_stopped_scheduled_flag_brings_orphans_back(): void
    {
        [$token, , $orphanId] = $this->seedStop1Overlap();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?start=2026-07-18&end=2026-07-18&per_page=100&include_stopped_scheduled=1');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertContains($orphanId, $ids);
    }

    public function test_history_filter_excludes_future_scheduled_and_rescheduled_rows_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03 09:00:00'));
        [$token, , $orphanId] = $this->seedStop1Overlap();
        $courseId = (int) ClassSession::findOrFail($orphanId)->StudentClassID;

        $pastId = (int) ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-08-27',
            'StartTime' => '10:00', 'EndTime' => '11:00', 'Status' => 'rescheduled',
        ])->id;
        $futureRescheduledId = (int) ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => '2026-09-10',
            'StartTime' => '10:00', 'EndTime' => '11:00', 'Status' => 'rescheduled',
        ])->id;

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?start=2026-08-01&end=2026-09-30&per_page=100&include_stopped_scheduled=1&exclude_history_future=1');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertContains($pastId, $ids);
        $this->assertNotContains($futureRescheduledId, $ids, 'history must exclude future rescheduled rows on stopped courses');
    }

    public function test_history_filter_hides_future_reservations_after_count_capacity_is_used(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-03 09:00:00'));
        [$token, , $orphanId] = $this->seedStop1Overlap();
        $course = StudentClass::findOrFail((int) ClassSession::findOrFail($orphanId)->StudentClassID);
        $course->Stop = 0;
        $course->UsedSessions = 1;
        $course->RemainingSessions = 7;
        $course->save();

        foreach (range(1, 7) as $offset) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => Carbon::parse('2026-08-01')->addDays($offset)->toDateString(),
                'StartTime' => '10:00', 'EndTime' => '11:00', 'Status' => 'attended',
            ]);
        }
        $futureId = (int) ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => '2026-09-10',
            'StartTime' => '10:00', 'EndTime' => '11:00', 'Status' => 'scheduled',
        ])->id;

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?start=2026-08-01&end=2026-09-30&per_page=100&exclude_history_future=1');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertNotContains($futureId, $ids, 'a full count course must not expose future scheduled reservations');
    }

    public function test_future_scheduled_sign_in_residue_does_not_promote_the_row_to_attended(): void
    {
        [$token, $activeId] = $this->seedStop1Overlap();
        $course = StudentClass::findOrFail((int) ClassSession::findOrFail($activeId)->StudentClassID);
        $future = ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => '2026-09-10',
            'StartTime' => '10:00', 'EndTime' => '11:00', 'Status' => 'scheduled',
        ]);
        \Illuminate\Support\Facades\DB::table('StudentSingIn')->insert([
            'StudentClassID' => $course->ID, 'StudentID' => $course->StudentID,
            'TeacherID' => $course->TeacherID, 'ClassSessionID' => $future->id,
            'Status' => 'present', 'SessionDeducted' => 1, 'SignInDT' => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?start=2026-09-10&end=2026-09-10&per_page=100');

        $res->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', (int) $future->id);
        $this->assertNotNull($row);
        $this->assertSame('scheduled', strtolower((string) $row['status']));
    }

    /**
     * @return array{0:string,1:int,2:int,3:int}
     */
    private function seedStop1Overlap(): array
    {
        $campusId = 9;
        $teacher = User::create([
            'LoginName' => 't-stop-sched@example.com', 'Name' => '黃芝琳測試', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0912003001', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacher->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        $student = Student::create([
            'name' => '王品方測試', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);

        $active = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $teacher->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-07-01', 'TotalHours' => 20,
            'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 1, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        $stopped = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $teacher->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-05-01', 'TotalHours' => 20,
            'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 0, 'UsedSessions' => 8,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 1, 'Rate' => 800, 'Stop' => 1,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);

        $activeCs = ClassSession::create([
            'StudentClassID' => $active->ID, 'SessionDate' => '2026-07-18',
            'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => 'scheduled',
        ]);
        $orphanCs = ClassSession::create([
            'StudentClassID' => $stopped->ID, 'SessionDate' => '2026-07-18',
            'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => 'scheduled',
        ]);
        $attendedCs = ClassSession::create([
            'StudentClassID' => $stopped->ID, 'SessionDate' => '2026-07-18',
            'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'attended',
        ]);

        return [$token, (int) $activeCs->id, (int) $orphanCs->id, (int) $attendedCs->id];
    }
}
