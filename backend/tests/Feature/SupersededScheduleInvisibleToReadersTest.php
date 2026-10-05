<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\BackfillScheduleOccurrenceIdentityService;
use App\Services\ClassSessionIndexReadService;
use App\Services\ScheduleGuardService;
use App\Services\SubstituteScheduleService;
use App\Services\SubstituteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TD-076 Track B §4: `schedules.status='superseded'` is not live. Fixture: contract teacher A has the live
 * row (id 100); a superseded row (id 101) for teacher B sits on the same slot with original_schedule_id=100, the
 * exact shape that would win as a substitute if it were 'scheduled'. Each reader is also run against the same
 * rows with status flipped to 'scheduled' so the fixture is proven to bite.
 *
 * StaleScheduleExceptionFilter judges ClassSession evidence, not status; its callers (the busy-slot and guard
 * tests here) whitelist status='scheduled' before calling it, so it is covered through them.
 */
class SupersededScheduleInvisibleToReadersTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-09';

    private int $aId;
    private int $bId;
    private StudentClass $sc;
    private ClassSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $mk = function (string $email, string $phone) {
            $u = User::create([
                'LoginName' => $email, 'Name' => $email, 'PSW' => 'x', 'type' => 'T',
                'phone' => $phone, 'MustChangePassword' => false, 'employment_type' => 'full_time',
            ]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $u->id, 'Admin' => 0, 'Approved' => 1]);

            return (int) $u->id;
        };
        $this->aId = $mk('a-superseded@example.com', '0911006001');
        $this->bId = $mk('b-superseded@example.com', '0911006002');

        $stu = Student::create([
            'name' => '退場列學生', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $this->sc = StudentClass::create([
            'StudentID' => $stu->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $this->aId, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-09-01', 'TotalHours' => 20,
            'SessionCount' => 10, 'SessionDuration' => 120,
            'RemainingSessions' => 10, 'UsedSessions' => 0,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        $this->session = ClassSession::create([
            'StudentClassID' => $this->sc->ID, 'SessionDate' => self::DATE,
            'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'scheduled',
        ]);

        $base = [
            'student_id' => $stu->id, 'day_of_week' => 5, 'type' => 'normal', 'deduction' => 1,
            'branch_id' => 1, 'student_course_id' => $this->sc->ID,
            'schedule_date' => self::DATE, 'start_time' => '10:00:00', 'end_time' => '12:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('schedules')->insert($base + [
            'id' => 100, 'teacher_id' => $this->aId, 'status' => 'scheduled', 'original_schedule_id' => null,
        ]);
        DB::table('schedules')->insert($base + [
            'id' => 101, 'teacher_id' => $this->bId, 'status' => Schedule::STATUS_SUPERSEDED, 'original_schedule_id' => 100,
        ]);
    }

    private function setSupersededRowStatus(string $status): void
    {
        DB::table('schedules')->where('id', 101)->update(['status' => $status]);
    }

    public function test_class_session_index_ignores_superseded_substitute(): void
    {
        $read = function () {
            $svc = app(ClassSessionIndexReadService::class);
            $row = $svc->buildQuery(Request::create('/api/v1/class-sessions', 'GET', [
                'start' => self::DATE, 'end' => self::DATE,
            ]))->get()->firstWhere('id', $this->session->id);
            $this->assertNotNull($row);

            return $svc->transformRow($row);
        };

        $row = $read();
        $this->assertSame($this->aId, (int) $row->teacher_id);
        $this->assertNull($row->substitute_teacher_id);

        $this->setSupersededRowStatus('scheduled');
        $this->assertSame($this->bId, (int) $read()->substitute_teacher_id, 'control: scheduled row is the substitute');
    }

    public function test_effective_instructor_ignores_superseded_substitute(): void
    {
        $resolve = fn () => SubstituteScheduleService::effectiveInstructorUserId(
            (int) $this->sc->ID, self::DATE, $this->aId, '10:00:00');

        $this->assertSame($this->aId, $resolve());
        $this->assertNull(SubstituteScheduleService::resolveSubstituteUserId((int) $this->sc->ID, self::DATE, '10:00:00'));

        $this->setSupersededRowStatus('scheduled');
        $this->assertSame($this->bId, $resolve(), 'control');
    }

    public function test_teacher_busy_slots_ignore_superseded_row(): void
    {
        $svc = app(SubstituteService::class);

        $this->assertSame([], $svc->collectTeacherBusySlots($this->bId, self::DATE));
        $this->assertSame([], $svc->collectTeacherBusySlotsWithCapacity($this->bId, self::DATE));

        $this->setSupersededRowStatus('scheduled');
        $this->assertNotEmpty($svc->collectTeacherBusySlots($this->bId, self::DATE), 'control');
        $this->assertNotEmpty($svc->collectTeacherBusySlotsWithCapacity($this->bId, self::DATE), 'control');
    }

    public function test_schedule_guard_ignores_superseded_row(): void
    {
        $guard = fn () => app(ScheduleGuardService::class)->validateScheduleOccurrence([
            'teacher_id' => $this->bId, 'branch_id' => 1, 'schedule_date' => self::DATE,
            'start_time' => '10:00', 'end_time' => '12:00', 'class_type' => 'one_on_one',
        ]);

        $this->assertSame([], $guard());

        $this->setSupersededRowStatus('scheduled');
        $this->assertNotEmpty($guard(), 'control: a scheduled row makes teacher B busy');
    }

    public function test_backfill_live_set_excludes_superseded_row(): void
    {
        $plan = fn () => app(BackfillScheduleOccurrenceIdentityService::class)->plan((int) $this->sc->ID);
        $ids = fn (array $items) => array_map(fn ($i) => (int) $i['id'], $items);

        $p = $plan();
        $this->assertSame([100], $ids($p['candidates']));
        $this->assertSame([], $p['collisions']);
        $this->assertNotContains(101, $ids($p['superseded']));

        $this->setSupersededRowStatus('scheduled');
        $this->assertNotSame([], $plan()['collisions'], 'control: scheduled row collides with the live row');
    }
}
