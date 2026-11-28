<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\SubstituteScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * In-app #611: dragging a substituted lesson (no teacher_id in the payload) is checked against, and keeps, the
 * substitute. The failing shape: a lesson moved once, then substituted (flag on, the live row has no anchor at its slot).
 */
class RescheduleKeepsSubstituteTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $aId;
    private int $bId;
    private StudentClass $sc;
    private ClassSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-18 08:00:00', 'Asia/Taipei'));
        $mk = function (string $name, string $type, int $admin) {
            $u = User::create(['LoginName' => "{$name}@r611.example.com", 'Name' => $name, 'PSW' => 'x', 'type' => $type,
                'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $u->id, 'Admin' => $admin, 'Approved' => 1]);

            return $u;
        };
        $dir = $mk('dir611', 'A', 1);
        $this->aId = (int) $mk('teachera611', 'T', 0)->id;
        $this->bId = (int) $mk('teacherb611', 'T', 0)->id;
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dir->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
        $this->sc = $this->course($this->aId);
        $this->session = ClassSession::create(['StudentClassID' => $this->sc->ID, 'SessionDate' => '2026-04-19',
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @dataProvider flags */
    public function test_drag_of_a_moved_then_substituted_lesson_checks_and_keeps_the_substitute(bool $v2): void
    {
        $this->movedThenSubstituted($v2);
        $this->busy($this->aId); // the contract teacher is busy at the target, the substitute is free

        $this->drag()->assertOk()->assertJsonPath('committed', true);

        $this->assertSame($this->bId, SubstituteScheduleService::teacherForOccurrence((int) $this->sc->ID, '2026-04-22', $this->aId, '15:00'));
        $this->assertDatabaseHas('schedules', ['student_course_id' => $this->sc->ID, 'status' => 'scheduled',
            'schedule_date' => '2026-04-22', 'teacher_id' => $this->bId]);
    }

    /** @dataProvider flags */
    public function test_drag_is_refused_when_the_substitute_is_busy(bool $v2): void
    {
        $this->movedThenSubstituted($v2);
        $this->busy($this->bId); // the substitute is busy at the target, the contract teacher is free
        $before = DB::table('schedules')->orderBy('id')->get()->toJson();

        $this->drag()->assertStatus(409)->assertJsonPath('code', 'schedule_conflict')->assertJsonPath('conflicts.0.type', 'teacher_capacity');

        $this->assertSame($before, DB::table('schedules')->orderBy('id')->get()->toJson());
        $this->assertSame('2026-04-21', Carbon::parse($this->session->fresh()->SessionDate)->toDateString());
    }

    public static function flags(): array
    {
        return ['flag on (TD-076)' => [true], 'flag off' => [false]];
    }

    private function movedThenSubstituted(bool $v2): void
    {
        config(['feature_flags.values' => ['FEATURE_SCHEDULE_OCCURRENCE_V2' => false, 'FEATURE_SCHEDULE_OCCURRENCE_V2_CAMPUS_1' => $v2]]);
        $this->api()->postJson('/api/v1/learning-records/reschedule-session', [
            'student_class_id' => $this->sc->ID, 'old_date' => '2026-04-19', 'old_start_time' => '13:00',
            'new_date' => '2026-04-21', 'start_time' => '15:00', 'end_time' => '17:00', 'ensure_schedule_exception' => true,
        ])->assertOk();
        $this->api()->postJson("/api/v1/class-sessions/{$this->session->id}/substitute", ['substitute_teacher_id' => $this->bId])->assertOk();
        $this->assertSame($this->bId, SubstituteScheduleService::teacherForOccurrence((int) $this->sc->ID, '2026-04-21', $this->aId, '15:00'));
    }

    /** The calendar drag payload: no teacher_id. */
    private function drag()
    {
        return $this->api()->postJson('/api/v1/learning-records/reschedule-session', [
            'student_class_id' => $this->sc->ID, 'old_date' => '2026-04-21', 'old_start_time' => '15:00',
            'new_date' => '2026-04-22', 'start_time' => '15:00', 'end_time' => '17:00', 'ensure_schedule_exception' => true,
        ]);
    }

    private function busy(int $teacherId): void
    {
        $other = $this->course($teacherId);
        ClassSession::create(['StudentClassID' => $other->ID, 'SessionDate' => '2026-04-22', 'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled']);
        DB::table('schedules')->insert(['student_id' => $other->StudentID, 'teacher_id' => $teacherId, 'day_of_week' => 3,
            'subject' => 'synthetic', 'type' => 'normal', 'status' => 'scheduled', 'deduction' => 1, 'branch_id' => 1,
            'student_course_id' => $other->ID, 'schedule_date' => '2026-04-22', 'start_time' => '15:00', 'end_time' => '17:00',
            'duration_hours' => 2, 'class_type' => 'one_on_one', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function course(int $teacherId): StudentClass
    {
        $stu = Student::create(['name' => 'R611 student', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create([
            'StudentID' => $stu->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacherId, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16, 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 6, 'UsedSessions' => 2, 'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
    }

    private function api()
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json']);
    }
}
