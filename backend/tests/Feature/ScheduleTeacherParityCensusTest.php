<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Scheduling\ScheduleTeacherParityCensus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #4991 item 7: nightly `lesson_teacher_disagreement`. The evaluation author is the lesson's teacher (#4901); the
 * schedule resolver that pay and subject units read must name the same person.
 */
class ScheduleTeacherParityCensusTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_only_live_records_on_recent_taught_lessons_whose_author_differs(): void
    {
        $course = $this->course(contractTeacher: 1);
        $this->lesson($course, daysAgo: 3, recordTeacher: 1);                       // same teacher: fine
        $this->lesson($course, daysAgo: 4, recordTeacher: 2);                       // someone else wrote it: counted
        $this->lesson($course, daysAgo: 5, recordTeacher: 2, voided: true);         // voided record: ignored
        $this->lesson($course, daysAgo: 6, recordTeacher: 2, status: 'scheduled');  // not taught: ignored
        $this->lesson($course, daysAgo: ScheduleTeacherParityCensus::DAYS + 5, recordTeacher: 2); // outside the window

        $before = [DB::table('ClassSession')->count(), DB::table('LearningRecord')->count()];
        $result = app(ScheduleTeacherParityCensus::class)->run();

        $this->assertSame(['mismatch_count' => 1, 'unevaluated' => 0, 'truncated' => false, 'checked' => 2], $result);
        $this->assertSame($before, [DB::table('ClassSession')->count(), DB::table('LearningRecord')->count()], 'read-only');
    }

    public function test_a_lesson_with_no_teacher_anywhere_is_unevaluated_not_a_mismatch(): void
    {
        $this->lesson($this->course(contractTeacher: 0), daysAgo: 2, recordTeacher: 2);

        $this->assertSame(['mismatch_count' => 0, 'unevaluated' => 1, 'truncated' => false, 'checked' => 1], app(ScheduleTeacherParityCensus::class)->run());
    }

    public function test_nightly_command_reports_the_condition_without_failing(): void
    {
        $this->lesson($this->course(contractTeacher: 1), daysAgo: 1, recordTeacher: 2);

        Artisan::call('bugs:verify-reproductions', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);
        $row = collect($json['conditions'])->firstWhere('key', 'lesson_teacher_disagreement');

        $this->assertSame([1, 'OPEN', false, 0], [$row['count'], $row['state'], $row['enforced'], $row['census_error']]);
    }

    private function course(int $contractTeacher): int
    {
        $student = Student::create(['name' => '老師一致測試生-' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return (int) StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $contractTeacher, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-01-01', 'TotalHours' => 20, 'Charge' => 8800, 'Paid' => 1, 'Rate' => 1100, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ])->ID;
    }

    private function lesson(int $courseId, int $daysAgo, int $recordTeacher, bool $voided = false, string $status = 'attended'): void
    {
        $date = CarbonImmutable::now('Asia/Taipei')->subDays($daysAgo)->toDateString();
        $sessionId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId, 'SessionDate' => $date, 'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => $status,
        ]);
        DB::table('LearningRecord')->insert([
            'StudentClassID' => $courseId, 'ClassSessionID' => $sessionId, 'TeacherID' => $recordTeacher, 'Content' => '',
            'Status' => 'pending', 'VoidedAt' => $voided ? now() : null,
        ]);
    }
}
