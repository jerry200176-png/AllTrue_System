<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\ScheduleGuardService;
use App\Services\SubstituteService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #3590 item 9: a substitute on a makeup (extra) occurrence is busy for the busy-slot readers (flag on only). */
class MakeupSubstituteBusyV2Test extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-04-19';

    private int $aId;
    private int $bId;
    private int $studentId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-18 08:00:00', 'Asia/Taipei'));
        $mk = fn (string $n) => (int) User::create([
            'LoginName' => "{$n}@item9.example.com", 'Name' => $n, 'PSW' => 'x', 'type' => 'T',
            'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false,
        ])->id;
        $this->aId = $mk('teachera');
        $this->bId = $mk('teacherb');
        $stu = Student::create(['name' => 'S', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $this->studentId = (int) $stu->id;
        $sc = StudentClass::create([
            'StudentID' => $stu->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $this->aId, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16, 'SessionCount' => 8, 'SessionDuration' => 60,
            'RemainingSessions' => 6, 'UsedSessions' => 2, 'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        $cs = ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => self::DATE, 'StartTime' => '10:00', 'EndTime' => '11:00', 'Status' => 'scheduled']);
        // The makeup row keeps teacher A; the substitute B lives on the LearningRecord only.
        DB::table('schedules')->insert([
            'id' => 9, 'student_id' => $stu->id, 'teacher_id' => $this->aId, 'day_of_week' => 7, 'type' => 'extra', 'status' => 'scheduled',
            'deduction' => 1, 'branch_id' => 1, 'student_course_id' => $sc->ID, 'schedule_date' => self::DATE,
            'start_time' => '10:00', 'end_time' => '11:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        LearningRecord::create([
            'StudentClassID' => $sc->ID, 'ClassSessionID' => $cs->id, 'TeacherID' => $this->bId, 'Status' => 'pending',
            'Content' => '', 'SessionDate' => self::DATE, 'StartTime' => '10:00', 'EndTime' => '11:00',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function flag(bool $on): void
    {
        config(['feature_flags.values' => ['FEATURE_SCHEDULE_OCCURRENCE_V2' => false, 'FEATURE_SCHEDULE_OCCURRENCE_V2_CAMPUS_1' => $on]]);
    }

    private function guard(): array
    {
        return app(ScheduleGuardService::class)->validateScheduleOccurrence([
            'teacher_id' => $this->bId, 'branch_id' => 1, 'schedule_date' => self::DATE, 'start_time' => '10:00', 'end_time' => '11:00', 'class_type' => 'one_on_one',
        ]);
    }

    public function test_flag_on_substitute_on_a_makeup_is_busy_in_every_busy_slot_reader(): void
    {
        $this->flag(true);
        $svc = app(SubstituteService::class);

        $busy = $svc->collectTeacherBusySlots($this->bId, self::DATE);
        $this->assertSame([['start_time' => '10:00', 'end_time' => '11:00', 'campus_id' => 1, 'source' => 'schedules']], $busy);
        $this->assertCount(1, $svc->collectTeacherBusySlotsWithCapacity($this->bId, self::DATE));
        $this->assertNotEmpty($this->guard(), 'a second booking at 10:00 conflicts');

        // The occurrence's own student never blocks itself.
        $this->assertSame([], $svc->collectTeacherBusySlots($this->bId, self::DATE, [], $this->studentId));
        // A voided LR frees the substitute again (resolver rule).
        DB::table('LearningRecord')->update(['VoidedAt' => now()]);
        $this->assertSame([], $svc->collectTeacherBusySlots($this->bId, self::DATE));
        $this->assertSame([], $this->guard());
    }

    public function test_flag_off_substitute_on_a_makeup_is_not_busy(): void
    {
        $this->flag(false);
        $svc = app(SubstituteService::class);

        $this->assertSame([], $svc->collectTeacherBusySlots($this->bId, self::DATE));
        $this->assertSame([], $svc->collectTeacherBusySlotsWithCapacity($this->bId, self::DATE));
        $this->assertSame([], $this->guard());
    }
}
