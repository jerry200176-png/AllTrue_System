<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * in-app #419 / #4275: scripts/probes/inapp-package-ledger-probe.php finds a campus's over-used shared packages and
 * tells a real overage from a lesson debited twice, issuing SELECTs only and returning no names or amounts.
 */
class InappPackageLedgerProbeTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE = __DIR__ . '/../../../scripts/probes/inapp-package-ledger-probe.php';

    /** Every output key the probe may print (public log): a new column must be added here on purpose. */
    private const ALLOWED_KEYS = [
        'packages' => ['id', 'campus_id', 'billing_mode', 'class_type', 'total_sessions', 'used_sessions', 'remaining_sessions', 'stop', 'ledger_net', 'ledger_rows', 'debits', 'credits'],
        'by_reason' => ['package_id', 'reason', 'delta', 'ledger_rows', 'sessions', 'rows_without_session'],
        'same_session_debited_twice' => ['package_id', 'class_session_id', 'net', 'reasons', 'session_date', 'start_time', 'status'],
        'same_slot_debited_twice' => ['package_id', 'session_date', 'start_time', 'net', 'sessions', 'session_ids'],
        'net_debit_by_session_status' => ['package_id', 'status', 'sessions', 'net'],
        'member_lessons' => ['package_id', 'member_courses', 'attended_slots', 'taught_slots', 'absent_slots', 'scheduled_slots'],
        'charged_slots_not_debited' => ['package_id', 'session_date', 'start_time', 'charged_session_ids', 'statuses', 'package_slot_net', 'charged_before_package'],
        'members' => ['package_id', 'student_class_id', 'student_id', 'subject_id', 'stop', 'schedule_mode'],
        'summary' => ['package_id', 'total_sessions', 'ledger_used', 'attended_slots', 'taught_slots', 'absent_slots', 'sessions_debited_twice', 'slots_debited_twice', 'debits_on_unchargeable_sessions', 'debits_without_session', 'charged_since_package_not_debited', 'lists_capped'],
    ];

    private int $teacherId;

    protected function setUp(): void
    {
        parent::setUp();
        require_once self::PROBE;
        $this->teacherId = (int) User::create([
            'LoginName' => 'probe-teacher-' . uniqid() . '@t.com', 'Name' => '合成老師', 'PSW' => 's', 'type' => 'T', 'phone' => 911000000,
        ])->id;
    }

    public function test_it_finds_the_over_used_pool_and_the_lesson_debited_twice_with_selects_only(): void
    {
        [$over, [$course]] = $this->pool(1, 2);
        $first = $this->lesson($course, '2026-10-01');
        $second = $this->lesson($course, '2026-10-08');
        $this->debit($over, $course, $first, 'conversion_seed');
        $this->debit($over, $course, $first, 'attendance');
        $this->debit($over, $course, $second, 'attendance');
        [$within, [$withinCourse]] = $this->pool(1, 5);
        $this->debit($within, $withinCourse, $this->lesson($withinCourse, '2026-10-02'), 'attendance');
        [$elsewhere, [$elsewhereCourse]] = $this->pool(2, 1);
        foreach (['2026-10-03', '2026-10-10'] as $date) {
            $this->debit($elsewhere, $elsewhereCourse, $this->lesson($elsewhereCourse, $date), 'attendance');
        }

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $out = inapp_package_ledger_probe(DB::connection(), 1, 0);

        $this->assertSame([(int) $over->id], $out['target_package_ids'], 'only campus 1 pools whose ledger used more than the total');
        $this->assertSame(-3, (int) $out['packages'][0]['ledger_net']);
        $this->assertSame(2, (int) $out['packages'][0]['total_sessions']);
        $this->assertCount(1, $out['same_session_debited_twice']);
        $twice = $out['same_session_debited_twice'][0];
        $this->assertSame((int) $first->id, (int) $twice['class_session_id']);
        $this->assertSame(-2, (int) $twice['net']);
        $this->assertSame('conversion_seed:-1,attendance:-1', $twice['reasons']);
        $this->assertSame(2, (int) $out['member_lessons'][0]['attended_slots'], 'two lessons taught, three debits');
        $this->assertSame([], $out['same_slot_debited_twice']);
        $this->assertSame([
            'package_id' => (int) $over->id, 'total_sessions' => 2, 'ledger_used' => 3, 'attended_slots' => 2, 'taught_slots' => 2, 'absent_slots' => 0,
            'sessions_debited_twice' => 1, 'slots_debited_twice' => 0, 'debits_on_unchargeable_sessions' => 0, 'debits_without_session' => 0,
            'charged_since_package_not_debited' => 0, 'lists_capped' => false,
        ], $out['summary'][0]);
        $this->assertOnlyAllowedKeys($out);
        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql);
            $this->assertDoesNotMatchRegularExpression('/\bfor\s+update\b|\binto\s+(out|dump)file\b|\block\s+in\s+share\s+mode\b/i', $sql);
        }
    }

    public function test_a_named_package_is_read_only_inside_its_campus_and_a_slot_debited_by_two_members_is_listed(): void
    {
        [$pool, [$math, $english]] = $this->pool(1, 10, 2);
        $this->debit($pool, $math, $this->lesson($math, '2026-10-05'), 'attendance');
        $this->debit($pool, $english, $this->lesson($english, '2026-10-05'), 'attendance');
        [$otherCampus] = $this->pool(2, 1);

        $out = inapp_package_ledger_probe(DB::connection(), 1, (int) $pool->id);
        $this->assertSame([(int) $pool->id], $out['target_package_ids'], 'a named package is read even when it is not over-used');
        $this->assertCount(1, $out['same_slot_debited_twice']);
        $this->assertSame(2, (int) $out['same_slot_debited_twice'][0]['sessions']);
        $this->assertSame(1, (int) $out['member_lessons'][0]['attended_slots']);
        $this->assertCount(2, $out['members']);
        $this->assertOnlyAllowedKeys($out);

        $this->assertSame([], inapp_package_ledger_probe(DB::connection(), 1, (int) $otherCampus->id)['target_package_ids'], 'another campus package is never read');
        $this->assertSame([], inapp_package_ledger_probe(DB::connection(), 3, 0)['target_package_ids']);
    }

    public function test_all_packages_lists_every_campus_pool_including_one_carrying_another_campus_id(): void
    {
        [$within, [$withinCourse]] = $this->pool(1, 16);
        $this->debit($within, $withinCourse, $this->lesson($withinCourse, '2026-10-02'), 'attendance');
        $cancelled = $this->lesson($withinCourse, '2026-10-09', 'cancelled');
        $this->debit($within, $withinCourse, $cancelled, 'attendance');
        // A no-show marked absent is charged on purpose (ClassSessionController), so it is not an unexplained debit.
        $this->debit($within, $withinCourse, $this->lesson($withinCourse, '2026-10-16', 'Absent'), 'attendance');
        [$mislabelled] = $this->pool(3, 16, 1, 1);
        [$elsewhere] = $this->pool(2, 16);

        $over = inapp_package_ledger_probe(DB::connection(), 1, 0);
        $this->assertSame([], $over['target_package_ids'], 'by default only over-used pools are listed');

        $out = inapp_package_ledger_probe(DB::connection(), 1, 0, true);
        $this->assertSame([(int) $within->id, (int) $mislabelled->id], $out['target_package_ids'], 'every pool of the campus, by campus id or member student, and no other campus');
        $this->assertNotContains((int) $elsewhere->id, $out['target_package_ids']);
        $this->assertSame(array_keys($out)[5], 'summary', 'the summary comes right after the target list');
        $this->assertSame(3, $out['summary'][0]['ledger_used']);
        $this->assertSame(1, $out['summary'][0]['attended_slots']);
        $this->assertSame(1, $out['summary'][0]['absent_slots']);
        $this->assertSame(1, $out['summary'][0]['debits_on_unchargeable_sessions'], 'a debit on a cancelled lesson is not explained by the lessons');
        $this->assertTrue($out['all_packages']);
        $this->assertOnlyAllowedKeys($out);
        $this->assertSame([(int) $mislabelled->id], inapp_package_ledger_probe(DB::connection(), 1, (int) $mislabelled->id)['target_package_ids'], 'a named package of a campus student is readable from that campus');
    }

    public function test_a_lesson_charged_on_its_course_but_not_debited_on_the_pool_is_listed_with_its_session(): void
    {
        // #4275: attended, reverted, attended again. The course ledger charges it once; the package ledger nets to zero.
        [$pool, [$course]] = $this->pool(1, 56);
        $reattended = $this->lesson($course, '2026-10-06');
        $this->charge($course, $reattended, ['deduct', 'reverse', 'deduct']);
        $this->debit($pool, $course, $reattended, 'attendance');
        $this->credit($pool, $course, $reattended, 'status_adjust');
        $settled = $this->lesson($course, '2026-10-13');
        $this->charge($course, $settled, ['deduct']);
        $this->debit($pool, $course, $settled, 'attendance');
        // Charged before the package existed (a conversion seeds it as a session-less orphan row): listed, flagged, not counted.
        $beforePool = $this->lesson($course, '2026-09-01');
        $this->charge($course, $beforePool, ['deduct'], '2026-09-01 18:00:00');
        $reverted = $this->lesson($course, '2026-10-20', 'scheduled');
        $this->charge($course, $reverted, ['deduct', 'reverse']);
        $this->debit($pool, $course, $reverted, 'attendance');
        $this->credit($pool, $course, $reverted, 'status_adjust');
        // A group slot debited once through another member's lesson is not a gap.
        [$group, [$math, $english]] = $this->pool(1, 10, 2);
        $mathLesson = $this->lesson($math, '2026-10-07');
        $englishLesson = $this->lesson($english, '2026-10-07');
        $this->charge($math, $mathLesson, ['deduct']);
        $this->charge($english, $englishLesson, ['deduct']);
        $this->debit($group, $math, $mathLesson, 'attendance');

        $out = inapp_package_ledger_probe(DB::connection(), 1, 0, true);

        $this->assertSame([[
            'package_id' => (int) $pool->id, 'session_date' => '2026-09-01', 'start_time' => '16:00',
            'charged_session_ids' => (string) $beforePool->id, 'statuses' => 'attended', 'package_slot_net' => 0, 'charged_before_package' => 1,
        ], [
            'package_id' => (int) $pool->id, 'session_date' => '2026-10-06', 'start_time' => '16:00',
            'charged_session_ids' => (string) $reattended->id, 'statuses' => 'attended', 'package_slot_net' => 0, 'charged_before_package' => 0,
        ]], array_map(fn (array $row): array => array_merge($row, [
            'package_id' => (int) $row['package_id'], 'package_slot_net' => (int) $row['package_slot_net'],
            'charged_before_package' => (int) $row['charged_before_package'],
        ]), $out['charged_slots_not_debited']));
        $this->assertSame(1, $out['summary'][0]['charged_since_package_not_debited']);
        $this->assertSame(0, $out['summary'][1]['charged_since_package_not_debited']);
        $this->assertOnlyAllowedKeys($out);
    }

    public function test_the_production_entry_point_runs_in_a_read_only_transaction_and_writes_nothing(): void
    {
        $source = (string) file_get_contents(self::PROBE);
        $this->assertStringContainsString("getenv('INAPP_PACKAGE_LEDGER_PROBE') === '1'", $source);
        $this->assertStringContainsString("statement('SET SESSION TRANSACTION READ ONLY')", $source);
        $this->assertStringContainsString('refusing to run: the session is not read-only', $source);
        $this->assertStringContainsString('->rollBack()', $source);
        $this->assertStringContainsString("fwrite(STDERR, 'probe_failed: ' . get_class(\$e)", $source, 'failures print the class only');
        // The only statements allowed are the session settings; everything else goes through select().
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

    /** @param array<string, mixed> $out */
    private function assertOnlyAllowedKeys(array $out): void
    {
        $this->assertSame([], array_diff(array_keys($out), ['read_only', 'campus_id', 'package_id', 'all_packages', 'target_package_ids', ...array_keys(self::ALLOWED_KEYS)]));
        foreach (self::ALLOWED_KEYS as $section => $keys) {
            foreach ($out[$section] ?? [] as $row) {
                $this->assertSame($keys, array_keys($row), $section);
            }
        }
    }

    /** @return array{0: CoursePackage, 1: list<StudentClass>} */
    private function pool(int $campusId, int $total, int $members = 1, ?int $studentCampusId = null): array
    {
        $student = Student::create([
            'name' => '合成學生' . uniqid(), 'CampusID' => $studentCampusId ?? $campusId, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $package = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => $campusId, 'name' => '合成方案', 'total_sessions' => $total,
            'remaining_sessions' => $total, 'used_sessions' => 0, 'rate' => 500, 'rate_unit' => 'session', 'class_type' => 'one_on_one',
            'paid' => false, 'stop' => false, 'enabled' => true,
        ]);
        $courses = [];
        for ($i = 1; $i <= $members; $i++) {
            $courses[] = StudentClass::create([
                'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => $i, 'TeacherID' => $this->teacherId, 'by1' => 1, 'Period' => 4,
                'StartDate' => Carbon::today()->toDateString(), 'TotalHours' => 0, 'ScheduleMode' => 'count', 'SessionCount' => $total,
                'RemainingSessions' => $total, 'UsedSessions' => 0, 'SessionDuration' => 120, 'ClassType' => 'one_on_one', 'Rate' => 500,
                'rate_unit' => 'session', 'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 0,
                'PackageID' => $package->id, 'PackageTotalSessions' => $total, 'PackageName' => $package->name,
            ]);
        }

        return [$package, $courses];
    }

    private function lesson(StudentClass $course, string $date, string $status = 'attended'): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => $status,
        ]);
    }

    private function debit(CoursePackage $package, StudentClass $course, ClassSession $lesson, string $reason, int $delta = -1): void
    {
        DB::table('package_session_ledger')->insert([
            'package_id' => $package->id, 'student_class_id' => $course->ID, 'class_session_id' => $lesson->id,
            'delta' => $delta, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function credit(CoursePackage $package, StudentClass $course, ClassSession $lesson, string $reason): void
    {
        $this->debit($package, $course, $lesson, $reason, 1);
    }

    /** @param list<string> $events course ledger events for the lesson, in order */
    private function charge(StudentClass $course, ClassSession $lesson, array $events, ?string $at = null): void
    {
        foreach ($events as $event) {
            DB::table('session_deduction_ledger')->insert([
                'student_class_id' => $course->ID, 'class_session_id' => $lesson->id, 'event_type' => $event,
                'source' => 'attendance', 'created_at' => $at ?? now(), 'updated_at' => $at ?? now(),
            ]);
        }
    }
}
