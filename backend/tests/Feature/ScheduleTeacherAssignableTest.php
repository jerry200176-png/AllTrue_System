<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * In-app #441: a schedules row owned by an id that is not a teacher of the caller's campuses hides the lesson from every
 * teacher's workbench while directors still see it (a course id was once stored as the teacher). The reschedule and
 * schedule writers refuse such an owner and write nothing; the contract teacher and campus teachers still work.
 */
class ScheduleTeacherAssignableTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $contractId;
    private int $otherId;
    private int $foreignId;
    private int $parentId;
    private StudentClass $sc;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-18 08:00:00', 'Asia/Taipei'));
        config(['feature_flags.values' => ['FEATURE_SCHEDULE_OCCURRENCE_V2' => false]]);
        $mk = function (string $name, string $type, int $campus, int $admin = 0) {
            $u = User::create(['LoginName' => "{$name}@assignable441.example.com", 'Name' => $name, 'PSW' => 'x', 'type' => $type,
                'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false, 'TeachingSessionCount' => 0]);
            UserCampus::create(['CampusID' => $campus, 'UserID' => $u->id, 'Admin' => $admin, 'Approved' => 1]);

            return $u;
        };
        $director = $mk('dir441', 'A', 1, 1);
        $this->contractId = (int) $mk('teacher441a', 'T', 1)->id;
        $this->otherId = (int) $mk('teacher441b', 'T', 1)->id;
        $this->foreignId = (int) $mk('teacher441c', 'T', 2)->id; // a real teacher, but of another campus
        $this->parentId = (int) User::create(['LoginName' => 'parent441@assignable441.example.com', 'Name' => 'parent441', 'PSW' => 'x', 'type' => 'P',
            'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false, 'TeachingSessionCount' => 0])->id; // a real user, no campus
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
        $student = Student::create(['name' => 'X student', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $this->sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $this->contractId, 'by1' => 1,
            'Period' => 8, 'StartDate' => '2026-04-01', 'TotalHours' => 16, 'Charge' => 0, 'Rate' => 1000,
            'SessionCount' => 8, 'RemainingSessions' => 5, 'SessionDuration' => 120, 'week' => 5, 'time' => '16:00:00',
            'class_type' => 'one_on_one', 'ScheduleMode' => 'count', 'Stop' => 0, 'Paid' => 1, 'MDT' => now(),
        ]);
        ClassSession::create(['StudentClassID' => $this->sc->ID, 'SessionDate' => '2026-04-24', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'scheduled']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The id production stored as a teacher (a course id): no User campus binding behind it. */
    private function phantom(): int
    {
        return 2391;
    }

    private function api()
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json']);
    }

    private function reschedule(array $extra)
    {
        return $this->api()->postJson('/api/v1/learning-records/reschedule-session', $extra + [
            'student_class_id' => $this->sc->ID, 'old_date' => '2026-04-24', 'old_start_time' => '16:00',
            'new_date' => '2026-04-25', 'start_time' => '16:00', 'end_time' => '18:00', 'ensure_schedule_exception' => true,
        ]);
    }

    private function scheduleBody(array $extra = []): array
    {
        return $extra + ['student_id' => $this->sc->StudentID, 'student_course_id' => $this->sc->ID, 'subject' => 'Math', 'day_of_week' => 6,
            'start_time' => '16:00', 'end_time' => '18:00', 'duration_hours' => 2, 'class_type' => 'one_on_one', 'status' => 'rescheduled',
            'type' => 'normal', 'deduction' => 0, 'branch_id' => 1, 'schedule_date' => '2026-04-25'];
    }

    public function test_reschedule_refuses_a_teacher_id_that_is_not_a_teacher_of_the_campus_and_writes_nothing(): void
    {
        foreach ([$this->phantom(), $this->parentId, $this->foreignId] as $bad) {
            $this->reschedule(['teacher_id' => $bad])->assertStatus(422)->assertJsonPath('code', 'teacher_not_assignable');
        }
        $this->assertSame(0, Schedule::count());
        $this->assertSame('2026-04-24', substr((string) ClassSession::where('StudentClassID', $this->sc->ID)->value('SessionDate'), 0, 10));

        $this->reschedule(['teacher_id' => $this->otherId])->assertOk();
        $this->assertContains($this->otherId, Schedule::pluck('teacher_id')->map(fn ($t) => (int) $t)->all());
    }

    public function test_reschedule_without_a_teacher_keeps_the_contract_teacher(): void
    {
        $this->reschedule([])->assertOk();
        $this->assertSame([$this->contractId], Schedule::pluck('teacher_id')->map(fn ($t) => (int) $t)->unique()->values()->all());
    }

    public function test_schedule_store_refuses_a_phantom_or_foreign_teacher_and_accepts_campus_teachers(): void
    {
        foreach ([$this->phantom(), $this->parentId, $this->foreignId] as $bad) {
            $this->api()->postJson('/api/v1/schedules', $this->scheduleBody(['teacher_id' => $bad]))
                ->assertStatus(422)->assertJsonPath('code', 'teacher_not_assignable');
        }
        $this->assertSame(0, Schedule::count());

        $this->api()->postJson('/api/v1/schedules', $this->scheduleBody(['teacher_id' => $this->otherId]))->assertCreated();
        $this->api()->postJson('/api/v1/schedules', $this->scheduleBody(['teacher_id' => $this->contractId, 'schedule_date' => '2026-05-02']))->assertCreated();
        $this->api()->postJson('/api/v1/schedules', $this->scheduleBody(['schedule_date' => '2026-05-09']))->assertCreated();
        $this->assertSame(3, Schedule::count());
    }

    public function test_schedule_update_refuses_a_new_phantom_owner_and_keeps_the_row(): void
    {
        $id = $this->api()->postJson('/api/v1/schedules', $this->scheduleBody(['teacher_id' => $this->otherId]))->assertCreated()->json('id');
        $this->api()->putJson("/api/v1/schedules/{$id}", ['teacher_id' => $this->phantom()])
            ->assertStatus(422)->assertJsonPath('code', 'teacher_not_assignable');
        $this->assertSame($this->otherId, (int) Schedule::find($id)->teacher_id);

        $this->api()->putJson("/api/v1/schedules/{$id}", ['teacher_id' => $this->contractId])->assertOk();
        $this->assertSame($this->contractId, (int) Schedule::find($id)->teacher_id);
    }
}
