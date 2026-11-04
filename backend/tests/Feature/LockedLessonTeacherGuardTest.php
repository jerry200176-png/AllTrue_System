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
use App\Exceptions\ApprovedAssessmentTeacherLockedException;
use App\Services\ClassSessionIndexReadService;
use App\Services\OccurrenceAssignmentService;
use App\Services\Scheduling\ContractTeacherChangeCascade;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * in-app #499 / #4901: one guard (LearningRecordMutableOwnership::assertLessonTeacherUnlocked) keeps a lesson with an approved
 * assessment on its author across every writer; batch passes skip and report. Synthetic ids only.
 */
class LockedLessonTeacherGuardTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-25';
    private const FUTURE = '2026-11-20';

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
            $u = User::create(['LoginName' => "{$n}@lock4901.example.com", 'Name' => $n, 'PSW' => 'x', 'type' => $type, 'phone' => '09' . random_int(10000000, 99999999)]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $u->id, 'Admin' => $admin, 'Approved' => 1]);

            return $u;
        };
        $dir = $mk('dirL', 'A', 1);
        [$this->old, $this->new, $this->sub] = [(int) $mk('oldL', 'T', 0)->id, (int) $mk('newL', 'T', 0)->id, (int) $mk('subL', 'T', 0)->id];
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dir->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
        $stu = Student::create(['name' => 'SL', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
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

    public function test_legacy_pin_pass_skips_a_lesson_locked_to_another_author_and_reports_it(): void
    {
        $s = $this->taughtSession($this->sub, $this->old); // signed in by sub, approved assessment by old

        $skipped = ContractTeacherChangeCascade::pinPastSessionsToFormerTeacherAfterContractTeacherChange((int) $this->course->ID, $this->old, $this->new, '2026-11-01');

        $this->assertSame([(int) $s->id], $skipped);
        $this->assertSame(0, Schedule::count(), 'no pin over the locked lesson');
    }

    public function test_legacy_pin_pass_still_pins_a_lesson_without_an_approved_assessment(): void
    {
        $this->taughtSession($this->sub, null);

        $skipped = ContractTeacherChangeCascade::pinPastSessionsToFormerTeacherAfterContractTeacherChange((int) $this->course->ID, $this->old, $this->new, '2026-11-01');

        $this->assertSame([], $skipped);
        $this->assertSame($this->sub, (int) Schedule::where('status', 'scheduled')->whereNotNull('original_schedule_id')->value('teacher_id'));
    }

    public function test_writer_refuses_assign_and_restore_on_a_locked_lesson_but_allows_the_author(): void
    {
        $s = $this->taughtSession($this->old, $this->old);
        $writer = app(OccurrenceAssignmentService::class);

        try {
            $writer->assignTeacher($s, $this->new, null);
            $this->fail('expected the lock');
        } catch (ApprovedAssessmentTeacherLockedException $e) {
            $this->assertSame($this->old, $e->authorTeacherId);
            $this->assertStringContainsString('已核准', $e->getMessage());
        }
        $this->assertSame(0, Schedule::count());
        $writer->assignTeacher($s, $this->old, null); // the author itself is fine
        $this->assertSame(2, Schedule::count());
    }

    public function test_writer_allows_a_lesson_without_a_record_and_a_future_lesson(): void
    {
        $writer = app(OccurrenceAssignmentService::class);
        $past = $this->taughtSession($this->old, null);
        $future = ClassSession::create(['StudentClassID' => $this->course->ID, 'SessionDate' => self::FUTURE, 'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'scheduled']);

        $this->assertSame($this->new, (int) $writer->assignTeacher($past, $this->new, null)->teacher_id);
        $this->assertSame($this->new, (int) $writer->assignTeacher($future, $this->new, null)->teacher_id);
    }

    public function test_flag_on_pin_pass_skips_the_locked_lesson_and_reports_it(): void
    {
        $locked = $this->taughtSession($this->new, $this->sub); // conflicting evidence would pin the old contract teacher, not the author

        $skipped = ContractTeacherChangeCascade::pinTaughtOccurrencesBeforeContractTeacherChange($this->course, $this->sub, '2026-11-01', null);
        $this->assertSame([(int) $locked->id], $skipped);
        $this->assertSame(0, Schedule::count());
    }

    public function test_calendar_row_shows_the_approved_author_over_a_stray_pin(): void
    {
        $row = (object) ['id' => 1, 'StudentClassID' => 1, 'StudentID' => 1, 'TeacherID' => 7, 'substitute_teacher_id' => 9, 'SessionDate' => '2026-10-25', 'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'attendance_sign_in_at' => null, 'attendance_memo' => null, 'Note' => null,
            'learning_record_id' => 5, 'learning_record_status' => 'approved', 'learning_record_progress' => 'p', 'learning_record_teacher_id' => 7];
        $this->assertSame(7, app(ClassSessionIndexReadService::class)->transformRow($row)->teacher_id);
    }

    private function taughtSession(int $signInTeacher, ?int $author): ClassSession
    {
        $s = ClassSession::create(['StudentClassID' => $this->course->ID, 'SessionDate' => self::DATE, 'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'attended']);
        StudentSignIn::create([
            'StudentClassID' => $this->course->ID, 'StudentID' => $this->course->StudentID, 'TeacherID' => $signInTeacher, 'RecordedByUserID' => $signInTeacher,
            'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => self::DATE . ' 13:00:00', 'MDT' => now(),
            'ClassSessionID' => $s->id, 'Status' => 'present', 'SessionDeducted' => 1,
        ]);
        if ($author) {
            LearningRecord::create(['StudentClassID' => $this->course->ID, 'ClassSessionID' => $s->id, 'TeacherID' => $author, 'Status' => 'approved',
                'Content' => 'filled body', 'Progress' => 'p', 'SessionDate' => self::DATE, 'StartTime' => '13:00', 'EndTime' => '15:00']);
        }

        return $s;
    }
}
