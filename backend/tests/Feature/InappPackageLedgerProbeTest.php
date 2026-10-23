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
        foreach (['packages', 'members'] as $section) {
            $this->assertEmpty(array_intersect(['name', 'rate', 'note', 'request_id'], array_keys($out[$section][0])), $section);
        }
        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql);
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

        $this->assertSame([], inapp_package_ledger_probe(DB::connection(), 1, (int) $otherCampus->id)['target_package_ids'], 'another campus package is never read');
        $this->assertSame([], inapp_package_ledger_probe(DB::connection(), 3, 0)['target_package_ids']);
    }

    public function test_the_production_entry_point_runs_in_a_read_only_transaction_and_writes_nothing(): void
    {
        $source = (string) file_get_contents(self::PROBE);
        $this->assertStringContainsString("getenv('INAPP_PACKAGE_LEDGER_PROBE') === '1'", $source);
        $this->assertStringContainsString("statement('SET SESSION TRANSACTION READ ONLY')", $source);
        $this->assertStringContainsString('refusing to run: the session is not read-only', $source);
        $this->assertStringContainsString('->rollBack()', $source);
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|GRANT)\b/', $source);
        $this->assertDoesNotMatchRegularExpression('/->(insert|update|delete|save|create|commit)\s*\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\.env\b/', $source);
    }

    /** @return array{0: CoursePackage, 1: list<StudentClass>} */
    private function pool(int $campusId, int $total, int $members = 1): array
    {
        $student = Student::create([
            'name' => '合成學生' . uniqid(), 'CampusID' => $campusId, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
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

    private function lesson(StudentClass $course, string $date): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
        ]);
    }

    private function debit(CoursePackage $package, StudentClass $course, ClassSession $lesson, string $reason): void
    {
        DB::table('package_session_ledger')->insert([
            'package_id' => $package->id, 'student_class_id' => $course->ID, 'class_session_id' => $lesson->id,
            'delta' => -1, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
