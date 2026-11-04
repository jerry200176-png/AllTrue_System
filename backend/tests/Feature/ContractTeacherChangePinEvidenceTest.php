<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\SubstituteScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * in-app #499: the contract-teacher-change history pin must pin who taught (sign-in / approved assessment), and must
 * never add a second pin over a live one. Synthetic ids only.
 */
class ContractTeacherChangePinEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-25';

    private int $old;
    private int $new;
    private int $sub;
    private StudentClass $course;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-04 10:00:00', 'Asia/Taipei'));
        config(['feature_flags.values' => ['FEATURE_SCHEDULE_OCCURRENCE_V2' => false]]);
        $mk = function (string $n, string $type, int $admin) {
            $u = User::create(['LoginName' => "{$n}@pin499.example.com", 'Name' => $n, 'PSW' => 'x', 'type' => $type, 'phone' => '09' . random_int(10000000, 99999999)]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $u->id, 'Admin' => $admin, 'Approved' => 1]);

            return $u;
        };
        $dir = $mk('dir499', 'A', 1);
        [$this->old, $this->new, $this->sub] = [(int) $mk('old499', 'T', 0)->id, (int) $mk('new499', 'T', 0)->id, (int) $mk('sub499', 'T', 0)->id];
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dir->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
        $stu = Student::create(['name' => 'S499', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $this->course = StudentClass::create([
            'StudentID' => $stu->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $this->old, 'by1' => 1, 'Period' => 8,
            'StartDate' => '2026-08-01', 'TotalHours' => 16, 'Charge' => 0, 'Rate' => 1000, 'SessionCount' => 8, 'RemainingSessions' => 5,
            'SessionDuration' => 120, 'week' => 7, 'time' => '13:00:00', 'class_type' => 'one_on_one', 'ScheduleMode' => 'count', 'Stop' => 0, 'Paid' => 1, 'MDT' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_existing_pin_and_sign_in_at_the_new_teacher_get_no_second_pin(): void
    {
        $s = $this->taughtSession($this->new, true);
        $this->pinPair($this->new);
        $before = Schedule::count();

        $this->changeTeacherTo($this->new);

        $this->assertSame($before, Schedule::count(), 'no duplicate pin over the live one');
        $this->assertSame($this->new, $this->effective($s));
    }

    public function test_sign_in_teacher_who_is_neither_old_nor_new_gets_pinned(): void
    {
        $s = $this->taughtSession($this->sub, false);

        $this->changeTeacherTo($this->new);

        $this->assertSame($this->sub, (int) Schedule::where('status', 'scheduled')->whereNotNull('original_schedule_id')->value('teacher_id'));
        $this->assertSame($this->sub, $this->effective($s));
    }

    public function test_slot_without_evidence_or_pin_still_pins_the_former_teacher(): void
    {
        $s = ClassSession::create(['StudentClassID' => $this->course->ID, 'SessionDate' => self::DATE, 'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'scheduled']);

        $this->changeTeacherTo($this->new);

        $this->assertSame($this->old, (int) Schedule::where('status', 'scheduled')->whereNotNull('original_schedule_id')->value('teacher_id'));
        $this->assertSame($this->old, $this->effective($s));
    }

    private function taughtSession(int $teacherId, bool $withRecord): ClassSession
    {
        $s = ClassSession::create(['StudentClassID' => $this->course->ID, 'SessionDate' => self::DATE, 'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'attended']);
        StudentSignIn::create([
            'StudentClassID' => $this->course->ID, 'StudentID' => $this->course->StudentID, 'TeacherID' => $teacherId, 'RecordedByUserID' => $teacherId,
            'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => self::DATE . ' 13:00:00', 'MDT' => now(),
            'ClassSessionID' => $s->id, 'Status' => 'present', 'SessionDeducted' => 1,
        ]);
        if ($withRecord) {
            LearningRecord::create(['StudentClassID' => $this->course->ID, 'ClassSessionID' => $s->id, 'TeacherID' => $teacherId, 'Status' => 'approved',
                'Content' => 'filled body', 'Progress' => 'p', 'SessionDate' => self::DATE, 'StartTime' => '13:00', 'EndTime' => '15:00']);
        }

        return $s;
    }

    /** The substitute-style pair the course 3399 slots already had: a rescheduled anchor plus a scheduled child. */
    private function pinPair(int $teacherId): void
    {
        $row = fn (string $status, ?int $original) => Schedule::create([
            'student_id' => $this->course->StudentID, 'teacher_id' => $teacherId, 'subject' => 'Math', 'day_of_week' => 7, 'start_time' => '13:00', 'end_time' => '15:00',
            'duration_hours' => 2, 'class_type' => 'one_on_one', 'status' => $status, 'type' => 'normal', 'deduction' => 1, 'branch_id' => 1,
            'schedule_date' => self::DATE, 'student_course_id' => $this->course->ID, 'original_schedule_id' => $original,
        ]);
        $anchor = $row('rescheduled', null);
        $row('scheduled', (int) $anchor->id);
    }

    private function changeTeacherTo(int $teacherId): void
    {
        $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/student-classes/{$this->course->ID}", ['teacher_id' => $teacherId])->assertOk();
    }

    private function effective(ClassSession $s): int
    {
        return SubstituteScheduleService::effectiveInstructorUserId((int) $this->course->ID, self::DATE, (int) $this->course->fresh()->TeacherID, '13:00:00');
    }
}
