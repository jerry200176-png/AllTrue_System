<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
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
