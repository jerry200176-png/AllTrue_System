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
use Illuminate\Support\Facades\DB;
use App\Services\SubstituteScheduleService;
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

    public function test_legacy_pin_pass_pins_the_author_of_a_filled_assessment_even_when_another_teacher_signed_in(): void
    {
        $s = $this->taughtSession($this->old, $this->sub); // old signed in, sub authored (approved)

        ContractTeacherChangeCascade::pinPastSessionsToFormerTeacherAfterContractTeacherChange((int) $this->course->ID, $this->old, $this->new, '2026-11-01');

        $this->assertSame($this->sub, (int) Schedule::where('status', 'scheduled')->whereNotNull('original_schedule_id')->value('teacher_id'));
        $this->assertSame($this->sub, $this->effective($s));
    }

    public function test_legacy_pin_pass_still_pins_the_sign_in_teacher_without_a_record(): void
    {
        $this->taughtSession($this->sub, null);

        ContractTeacherChangeCascade::pinPastSessionsToFormerTeacherAfterContractTeacherChange((int) $this->course->ID, $this->old, $this->new, '2026-11-01');

        $this->assertSame($this->sub, (int) Schedule::where('status', 'scheduled')->whereNotNull('original_schedule_id')->value('teacher_id'));
    }

    public function test_an_unapproved_filled_author_is_pinned_and_a_system_backfill_or_voided_row_is_not(): void
    {
        $s = $this->taughtSession($this->old, $this->sub);
        LearningRecord::where('ClassSessionID', $s->id)->update(['Status' => 'pending', 'ApprovedAt' => null]);
        ContractTeacherChangeCascade::pinPastSessionsToFormerTeacherAfterContractTeacherChange((int) $this->course->ID, $this->old, $this->new, '2026-11-01');
        $this->assertSame($this->sub, (int) Schedule::where('status', 'scheduled')->whereNotNull('original_schedule_id')->value('teacher_id'));

        Schedule::query()->delete();
        LearningRecord::where('ClassSessionID', $s->id)->update(['Content' => '（系統補登/扣除堂數）', 'Progress' => null]);
        ContractTeacherChangeCascade::pinPastSessionsToFormerTeacherAfterContractTeacherChange((int) $this->course->ID, $this->old, $this->new, '2026-11-01');
        $this->assertSame($this->old, (int) Schedule::where('status', 'scheduled')->whereNotNull('original_schedule_id')->value('teacher_id'), 'back-fill: sign-in teacher');

        Schedule::query()->delete();
        LearningRecord::where('ClassSessionID', $s->id)->update(['Content' => 'x', 'Progress' => 'p', 'VoidedAt' => now()]);
        ContractTeacherChangeCascade::pinPastSessionsToFormerTeacherAfterContractTeacherChange((int) $this->course->ID, $this->old, $this->new, '2026-11-01');
        $this->assertSame($this->old, (int) Schedule::where('status', 'scheduled')->whereNotNull('original_schedule_id')->value('teacher_id'), 'voided: sign-in teacher');
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
            $this->assertStringContainsString('寫的評量', $e->getMessage());
        }
        $this->assertSame(0, Schedule::count());
        $writer->assignTeacher($s, $this->old, null); // the author itself is fine
        $this->assertSame(2, Schedule::count());

        // restore to the contract teacher (new != author) is refused too
        DB::table('StudentClass')->where('ID', $this->course->ID)->update(['TeacherID' => $this->new]);
        $this->expectException(ApprovedAssessmentTeacherLockedException::class);
        $writer->restoreContractTeacher($s->fresh(), null);
    }

    public function test_writer_allows_a_lesson_without_a_record_and_a_future_lesson(): void
    {
        $writer = app(OccurrenceAssignmentService::class);
        $past = $this->taughtSession($this->old, null);
        $future = ClassSession::create(['StudentClassID' => $this->course->ID, 'SessionDate' => self::FUTURE, 'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => 'scheduled']);

        $this->assertSame($this->new, (int) $writer->assignTeacher($past, $this->new, null)->teacher_id);
        $this->assertSame($this->new, (int) $writer->assignTeacher($future, $this->new, null)->teacher_id);
    }

    public function test_flag_on_pin_pass_pins_the_author_over_conflicting_evidence(): void
    {
        $locked = $this->taughtSession($this->new, $this->sub); // sign-in new, author sub: conflict would pin the old contract teacher

        ContractTeacherChangeCascade::pinTaughtOccurrencesBeforeContractTeacherChange($this->course, $this->new, '2026-11-01', null);

        $this->assertSame($this->sub, (int) Schedule::where('status', 'scheduled')->whereNotNull('original_schedule_id')->value('teacher_id'));
        $this->assertSame($this->sub, $this->effective($locked));
    }

    public function test_voided_record_does_not_lock_the_writer_and_the_sql_twin_matches_the_php_rule(): void
    {
        $writer = app(OccurrenceAssignmentService::class);
        $s = $this->taughtSession($this->old, $this->old);
        $rows = [
            ['Content' => 'body', 'Progress' => null], ['Content' => '', 'Progress' => 'p'], ['Content' => '（評量表）', 'Progress' => null],
            ['Content' => '（系統補登/扣除堂數）', 'Progress' => null], ['Content' => '（系統自動核准）', 'Progress' => null], ['Content' => '（系統補登）', 'Progress' => 'p'],
            ['Content' => "\n", 'Progress' => null], ['Content' => "\t", 'Progress' => "\n"], ['Content' => '   ', 'Progress' => ' '],
        ];
        foreach ($rows as $r) {
            LearningRecord::where('ClassSessionID', $s->id)->update($r);
            $lr = LearningRecord::where('ClassSessionID', $s->id)->first();
            $sql = (bool) DB::table('LearningRecord as lr')->where('lr.id', $lr->id)->selectRaw(\App\Support\LearningRecordMutableOwnership::filledByTeacherSql('lr') . ' as f')->value('f');
            $this->assertSame(\App\Support\LearningRecordMutableOwnership::isFilledByTeacher($lr), $sql, json_encode($r));
        }
        // A director comment on a blank record is not a teacher-written evaluation, in PHP and SQL alike.
        LearningRecord::where('ClassSessionID', $s->id)->update(['Content' => '', 'Progress' => null]);
        $lr = LearningRecord::where('ClassSessionID', $s->id)->first();
        DB::table('learning_record_teacher_comments')->insert([
            'learning_record_id' => $lr->id, 'student_id' => $lr->StudentID ?? 0, 'student_class_id' => $lr->StudentClassID, 'class_session_id' => $s->id,
            'teacher_id' => $this->old, 'campus_id' => 1, 'author_user_id' => 1, 'content' => 'note', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sql = (bool) DB::table('LearningRecord as lr')->where('lr.id', $lr->id)->selectRaw(\App\Support\LearningRecordMutableOwnership::filledByTeacherSql('lr') . ' as f')->value('f');
        $this->assertFalse($sql);
        $this->assertFalse(\App\Support\LearningRecordMutableOwnership::isFilledByTeacher($lr->fresh()));
        LearningRecord::where('ClassSessionID', $s->id)->update(['Content' => 'body', 'VoidedAt' => now()]);
        $this->assertSame($this->new, (int) $writer->assignTeacher($s, $this->new, null)->teacher_id);
    }

    public function test_calendar_row_shows_the_filling_author_over_a_stray_pin(): void
    {
        $row = (object) ['id' => 1, 'StudentClassID' => 1, 'StudentID' => 1, 'TeacherID' => 7, 'substitute_teacher_id' => 9, 'SessionDate' => '2026-10-25', 'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'attendance_sign_in_at' => null, 'attendance_memo' => null, 'Note' => null,
            'learning_record_id' => 5, 'learning_record_status' => 'draft', 'learning_record_progress' => null, 'learning_record_filled' => 1, 'learning_record_teacher_id' => 7, 'learning_record_teacher_name' => 'author', 'teacher_name' => 'pin'];
        $out = app(ClassSessionIndexReadService::class)->transformRow($row);
        $this->assertSame(7, $out->teacher_id);
        $this->assertSame('author', $out->teacher_name);
        $this->assertTrue($out->teacher_locked_to_author);
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

    private function effective(ClassSession $s): int
    {
        return SubstituteScheduleService::effectiveInstructorUserId((int) $this->course->ID, self::DATE, (int) $this->course->fresh()->TeacherID, '13:00:00');
    }
}
