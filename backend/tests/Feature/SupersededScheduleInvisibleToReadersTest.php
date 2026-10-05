<?php

namespace Tests\Feature;

use App\Console\Commands\RecoverTeacherRfidCollisionSignIns;
use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\BackfillScheduleOccurrenceIdentityService;
use App\Services\ClassSessionIndexReadService;
use App\Services\Scheduling\NonstandardDurationInventoryReporter;
use App\Services\SessionDeductionService;
use Illuminate\Support\Carbon;
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

    /** Teacher A gets an earlier row (id 200) in the given status and a later 'scheduled' row (id 201) on $date. */
    private function seedEarlyRowAndLaterLiveRow(string $date, string $earlyStatus): void
    {
        $base = [
            'student_id' => 1, 'day_of_week' => 1, 'type' => 'normal', 'deduction' => 1, 'branch_id' => 1,
            'student_course_id' => $this->sc->ID, 'schedule_date' => $date, 'teacher_id' => $this->aId,
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('schedules')->insert($base + ['id' => 200, 'status' => $earlyStatus, 'start_time' => '08:00:00', 'end_time' => '10:00:00']);
        DB::table('schedules')->insert($base + ['id' => 201, 'status' => 'scheduled', 'start_time' => '10:00:00', 'end_time' => '12:00:00']);
    }

    public function test_teacher_attendance_first_class_ignores_superseded_row(): void
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $this->aId, 'token' => $token, 'expires_at' => now()->addDay()]);
        $firstClass = fn () => $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/teacher-attendance/today')->assertOk()->json('first_class_start_time');

        $this->seedEarlyRowAndLaterLiveRow(now()->toDateString(), Schedule::STATUS_SUPERSEDED);
        $this->assertSame('10:00:00', $firstClass());

        DB::table('schedules')->where('id', 200)->update(['status' => 'scheduled']);
        $this->assertSame('08:00:00', $firstClass(), 'control');
    }

    public function test_rfid_recovery_first_class_ignores_superseded_row(): void
    {
        $this->seedEarlyRowAndLaterLiveRow('2026-10-12', Schedule::STATUS_SUPERSEDED);
        $resolve = (new \ReflectionClass(RecoverTeacherRfidCollisionSignIns::class))->getMethod('resolveStatus');
        $status = fn () => $resolve->invoke(new RecoverTeacherRfidCollisionSignIns(), $this->aId, '2026-10-12 10:05:00');

        $this->assertSame('normal', $status());

        DB::table('schedules')->where('id', 200)->update(['status' => 'scheduled']);
        $this->assertSame('late', $status(), 'control: 08:00 row makes a 10:05 swipe late');
    }

    public function test_teacher_eligibility_ignores_superseded_row(): void
    {
        $dir = User::create([
            'LoginName' => 'dir-superseded@example.com', 'Name' => '主任', 'PSW' => 'x', 'type' => 'A',
            'phone' => '0910000019', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $dir->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dir->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        // 2026-08-03 is a Monday; only id 201 (10:00-12:00) may count toward weekday hours.
        $this->seedEarlyRowAndLaterLiveRow('2026-08-03', Schedule::STATUS_SUPERSEDED);
        $hours = fn () => $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/finance/teacher-eligibility?period=week&start=2026-08-03&end=2026-08-09&branch_id=1')
            ->assertOk()
            ->json('teachers.0.components.weekday_afternoon.metrics.daily_coverage_hours.2026-08-03');

        $this->assertEquals(2.0, $hours());

        DB::table('schedules')->where('id', 200)->update(['status' => 'scheduled']);
        $this->assertEquals(4.0, $hours(), 'control: scheduled 08:00-10:00 row is counted');
    }

    public function test_busy_slots_latest_status_ignores_superseded_after_leave_marker(): void
    {
        $base = [
            'student_id' => 1, 'day_of_week' => 5, 'type' => 'normal', 'deduction' => 1, 'branch_id' => 1,
            'student_course_id' => $this->sc->ID, 'schedule_date' => self::DATE, 'teacher_id' => $this->aId,
            'start_time' => '10:00:00', 'end_time' => '12:00:00', 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('schedules')->insert($base + ['id' => 300, 'status' => 'leave']);
        DB::table('schedules')->insert($base + ['id' => 301, 'status' => Schedule::STATUS_SUPERSEDED]);
        $svc = app(SubstituteService::class);

        // The leave marker frees A's contract session; the newer superseded row must not undo that.
        $this->assertSame([], $svc->collectTeacherBusySlots($this->aId, self::DATE));

        DB::table('schedules')->where('id', 301)->update(['status' => 'scheduled']);
        $this->assertNotEmpty($svc->collectTeacherBusySlots($this->aId, self::DATE), 'control: newer scheduled row is live');
    }

    public function test_session_dates_ignore_superseded_row_as_leave(): void
    {
        Carbon::setTestNow('2026-10-06 09:00:00');
        $user = User::create(['LoginName' => 'sd-sup@example.com', 'Name' => 'sd', 'PSW' => bcrypt('x'), 'type' => 'A', 'status' => 'active']);
        UserCampus::create(['UserID' => $user->id, 'CampusID' => 1, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        // 3-session Friday contract: 10-09, 10-16, 10-23 with no ClassSession rows. A leave row on 10-16 pushes
        // the walk to 10-30; a superseded row on 10-16 must leave the date in place.
        $sc = StudentClass::create([
            'StudentID' => $this->sc->StudentID, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $this->aId,
            'ClassType' => 'one_on_one', 'by1' => 1, 'Period' => 4, 'StartDate' => '2026-10-09', 'TotalHours' => 6,
            'SessionCount' => 3, 'SessionDuration' => 120, 'RemainingSessions' => 3, 'UsedSessions' => 0,
            'Charge' => 1600, 'Pay' => 4800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count', 'week' => 5,
        ]);
        $setRow = fn (string $status) => DB::table('schedules')->updateOrInsert(['id' => 500], [
            'student_id' => $this->sc->StudentID, 'day_of_week' => 5, 'type' => 'normal', 'deduction' => 1,
            'branch_id' => 1, 'student_course_id' => $sc->ID, 'teacher_id' => $this->aId, 'status' => $status,
            'schedule_date' => '2026-10-16', 'start_time' => '10:00:00', 'end_time' => '12:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $range = ['branch_id' => 1, 'range_start' => '2026-10-01', 'range_end' => '2026-10-31'];
        $datesOf = function ($res) use ($sc) {
            $res->assertOk();
            $p = $res->json((string) $sc->ID) ?? [];

            return array_values(array_unique(array_merge(
                array_column($p['materialized'] ?? [], 'session_date'),
                array_column($p['projected'] ?? [], 'session_date')
            )));
        };
        $viaBody = fn () => $datesOf($this->withHeaders($headers)->postJson('/api/v1/student-classes/session-dates', $range + [
            'courses' => [['id' => $sc->ID, 'first_class_date' => '2026-10-09', 'sessions_purchased' => 3, 'days_of_week' => [5]]],
        ]));
        $viaQuery = fn () => $datesOf($this->withHeaders($headers)->getJson('/api/v1/student-classes/session-dates?' . http_build_query($range)));

        $setRow(Schedule::STATUS_SUPERSEDED);
        foreach ([$viaBody, $viaQuery] as $read) {
            $this->assertContains('2026-10-16', $read(), 'superseded row is not a leave');
        }

        $setRow('leave');
        foreach ([$viaBody, $viaQuery] as $read) {
            $this->assertNotContains('2026-10-16', $read(), 'control: a leave row removes the date');
        }
        Carbon::setTestNow();
    }

    public function test_partial_makeup_detection_ignores_superseded_extra_row(): void
    {
        $this->session->update(['EndTime' => '11:00:00']); // 60 min vs the 120 min contract length
        DB::table('schedules')->insert([
            'id' => 400, 'student_id' => 1, 'day_of_week' => 5, 'type' => 'extra', 'deduction' => 1, 'branch_id' => 1,
            'student_course_id' => $this->sc->ID, 'schedule_date' => self::DATE, 'teacher_id' => $this->aId,
            'status' => Schedule::STATUS_SUPERSEDED, 'start_time' => '10:00:00', 'end_time' => '11:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $resolve = fn () => (new \ReflectionMethod(SessionDeductionService::class, 'resolvePartialMakeupMinutes'))
            ->invoke(null, $this->sc->fresh(), (int) $this->session->id);

        $this->assertNull($resolve());

        DB::table('schedules')->where('id', 400)->update(['status' => 'scheduled']);
        $this->assertSame(60, $resolve(), 'control: a scheduled extra row is a makeup');
    }

    public function test_nonstandard_duration_makeup_keys_ignore_superseded_extra_row(): void
    {
        DB::table('schedules')->insert([
            'id' => 410, 'student_id' => 1, 'day_of_week' => 5, 'type' => 'extra', 'deduction' => 1, 'branch_id' => 1,
            'student_course_id' => $this->sc->ID, 'schedule_date' => self::DATE, 'teacher_id' => $this->aId,
            'status' => Schedule::STATUS_SUPERSEDED, 'start_time' => '10:00:00', 'end_time' => '11:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $keys = fn () => (new \ReflectionMethod(NonstandardDurationInventoryReporter::class, 'loadMakeupKeys'))
            ->invoke(new NonstandardDurationInventoryReporter(), [(int) $this->sc->ID]);

        $this->assertSame([], $keys());

        DB::table('schedules')->where('id', 410)->update(['status' => 'scheduled']);
        $this->assertNotEmpty($keys(), 'control');
    }
}
