<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #4554: scripts/probes/forward-overdeduct-probe.php lists courses the forward generator over-filled (#4515) whose
 * ledger now holds more attendance deducts than bought, issuing SELECTs only and returning no names or amounts.
 */
class ForwardOverdeductProbeTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE = __DIR__ . '/../../../scripts/probes/forward-overdeduct-probe.php';

    private const COURSE_KEYS = ['class_id', 'campus_id', 'session_count', 'used_sessions', 'ledger_used', 'generated_session_ids', 'generated_statuses', 'later_course_ids'];

    private int $teacherId;

    protected function setUp(): void
    {
        parent::setUp();
        require_once self::PROBE;
        $this->teacherId = (int) User::create([
            'LoginName' => 'probe-teacher-' . uniqid() . '@t.com', 'Name' => '合成老師', 'PSW' => 's', 'type' => 'T', 'phone' => 911000000,
        ])->id;
    }

    public function test_it_lists_the_over_filled_course_and_its_renewal_with_selects_only(): void
    {
        // Course 2739 shape: 2 bought, the second lesson passed unmarked, the generator added a third, all three deducted.
        $student = $this->student(17);
        $over = $this->course($student, 2);
        $this->deduct($over, $this->lesson($over, '2026-09-19'));
        $this->deduct($over, $this->lesson($over, '2026-09-25'));
        $extra = $this->lesson($over, '2026-09-26', '系統向前生成 #1062');
        $this->deduct($over, $extra);
        $renewal = $this->course($student, 8);
        // Generated lesson but within quota; a reversed extra deduct; over quota without a generated lesson.
        $within = $this->course($this->student(17), 2);
        $this->deduct($within, $this->lesson($within, '2026-09-26', '系統向前生成 #1062'));
        $reversed = $this->course($this->student(17), 1);
        $this->deduct($reversed, $this->lesson($reversed, '2026-09-19'));
        $gen = $this->lesson($reversed, '2026-09-26', '系統向前生成 #1062');
        $this->deduct($reversed, $gen);
        $this->deduct($reversed, $gen, 'reverse');
        $manual = $this->course($this->student(17), 1);
        $this->deduct($manual, $this->lesson($manual, '2026-09-19'));
        $this->deduct($manual, $this->lesson($manual, '2026-09-26'));
        $elsewhere = $this->course($this->student(9), 1);
        $this->deduct($elsewhere, $this->lesson($elsewhere, '2026-09-19'));
        $this->deduct($elsewhere, $this->lesson($elsewhere, '2026-09-26', '系統向前生成 #1062'));

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $out = forward_overdeduct_probe(DB::connection(), 17);

        $this->assertEquals([
            'class_id' => $over->ID, 'campus_id' => 17, 'session_count' => 2, 'used_sessions' => 0, 'ledger_used' => 3,
            'generated_session_ids' => (string) $extra->id, 'generated_statuses' => 'attended', 'later_course_ids' => (string) $renewal->ID,
        ], $out['courses'][0]);
        $this->assertCount(1, $out['courses']);
        $this->assertSame(['courses' => 1, 'extra_deducts' => 1, 'by_campus' => [17 => 1], 'list_capped' => false], $out['summary']);
        $this->assertSame([9 => 1, 17 => 1], forward_overdeduct_probe(DB::connection(), 0)['summary']['by_campus'], '0 scans every campus');
        foreach ($out['courses'] as $row) {
            $this->assertSame(self::COURSE_KEYS, array_keys($row));
        }
        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql);
            $this->assertDoesNotMatchRegularExpression('/\bfor\s+update\b|\binto\s+(out|dump)file\b|\block\s+in\s+share\s+mode\b/i', $sql);
        }
    }

    public function test_the_production_entry_point_runs_in_a_read_only_transaction_and_writes_nothing(): void
    {
        $source = (string) file_get_contents(self::PROBE);
        $this->assertStringContainsString("getenv('FORWARD_OVERDEDUCT_PROBE') === '1'", $source);
        $this->assertStringContainsString("statement('SET SESSION TRANSACTION READ ONLY')", $source);
        $this->assertStringContainsString('refusing to run: the session is not read-only', $source);
        $this->assertStringContainsString('->rollBack()', $source);
        $this->assertStringContainsString("fwrite(STDERR, 'probe_failed: ' . get_class(\$e)", $source, 'failures print the class only');
        preg_match_all('/->statement\(\'([^\']*)\'\)/', $source, $statements);
        $this->assertSame(
            ['SET SESSION TRANSACTION READ ONLY', 'SET SESSION MAX_EXECUTION_TIME = 30000', 'SET SESSION max_statement_time = 30'],
            $statements[1]
        );
        $this->assertSame(3, substr_count($source, '->statement('));
        $this->assertDoesNotMatchRegularExpression(
            '/\b(insert|update|delete|replace|alter|drop|create|truncate|grant|commit|outfile|dumpfile)\b|read\s+write|for\s+update|lock\s+in\s+share/i',
            $source
        );
        $this->assertDoesNotMatchRegularExpression('/->(unprepared|getPdo|exec|insert|update|delete|save|create|commit)\s*\(/i', $source);
        $this->assertDoesNotMatchRegularExpression('/\.env\b/', $source);
    }

    private function student(int $campusId): Student
    {
        return Student::create([
            'name' => '合成學生' . uniqid(), 'CampusID' => $campusId, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
    }

    private function course(Student $student, int $total): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => $this->teacherId, 'by1' => 1, 'Period' => 4,
            'StartDate' => Carbon::today()->toDateString(), 'TotalHours' => 0, 'ScheduleMode' => 'count', 'SessionCount' => $total,
            'RemainingSessions' => $total, 'UsedSessions' => 0, 'SessionDuration' => 120, 'ClassType' => 'one_on_one', 'Rate' => 500,
            'rate_unit' => 'session', 'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 0,
        ]);
    }

    private function lesson(StudentClass $course, string $date, string $note = ''): ClassSession
    {
        $id = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '10:00:00', 'EndTime' => '12:00:00',
            'Status' => 'attended', 'Note' => $note, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ClassSession::query()->findOrFail($id);
    }

    private function deduct(StudentClass $course, ClassSession $lesson, string $event = 'deduct'): void
    {
        DB::table('session_deduction_ledger')->insert([
            'student_class_id' => $course->ID, 'class_session_id' => $lesson->id, 'event_type' => $event, 'source' => 'attendance',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
