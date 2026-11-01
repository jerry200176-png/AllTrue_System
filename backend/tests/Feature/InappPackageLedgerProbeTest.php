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
        'deleted_lesson_debits' => ['package_id', 'sessions', 'pool_debits', 'course_charged', 'pool_only', 'delete_logged'],
        'member_lessons' => ['package_id', 'member_courses', 'attended_slots', 'taught_slots', 'absent_slots', 'scheduled_slots', 'future_planned'],
        'charged_slots_not_debited' => ['package_id', 'scope', 'session_date', 'start_time', 'charged_session_ids', 'statuses', 'package_net', 'charged_before_package'],
        'members' => ['package_id', 'student_class_id', 'student_id', 'subject_id', 'stop', 'schedule_mode'],
        'summary' => ['package_id', 'total_sessions', 'ledger_used', 'attended_slots', 'taught_slots', 'absent_slots', 'future_planned', 'overplanned', 'sessions_debited_twice', 'slots_debited_twice', 'debits_on_unchargeable_sessions', 'debits_without_session', 'charged_since_package_not_debited', 'lists_capped'],
        'unchargeable_session_rows' => ['package_id', 'ledger_id', 'class_session_id', 'student_class_id', 'reason', 'delta', 'created_at', 'status', 'last_status', 'deleted_at', 'session_date', 'start_time', 'slot_source', 'session_net', 'course_net', 'twin_session_id', 'twin_student_class_id', 'twin_start_time', 'twin_status', 'twin_package_net', 'twin_course_net'],
        'shared_slot_rows' => ['package_id', 'ledger_id', 'class_session_id', 'student_class_id', 'reason', 'delta', 'created_at', 'status', 'session_date', 'start_time', 'course_net', 'slot_net'],
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
            'future_planned' => 0, 'overplanned' => 0,
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

    public function test_it_counts_lessons_pre_planned_past_what_the_pool_has_left(): void
    {
        // Before #4730 each subject pre-planned the whole total: 2 subjects x 3 future lessons on a 4-lesson pool, 1 used.
        [$pool, [$math, $english]] = $this->pool(1, 4, 2);
        $this->debit($pool, $math, $this->lesson($math, Carbon::today()->subWeek()->toDateString()), 'attendance');
        foreach ([1, 2, 3] as $week) {
            foreach ([$math, $english] as $course) {
                $this->lesson($course, Carbon::today()->addWeeks($week)->toDateString(), 'scheduled', $course === $math ? '16:00' : '18:00');
            }
        }
        $this->lesson($english, Carbon::today()->addWeeks(4)->toDateString(), 'cancelled');
        [$group, [$a, $b]] = $this->pool(1, 2, 2, null, 'one_on_two');
        foreach ([$a, $b] as $course) {
            $this->lesson($course, Carbon::today()->addWeek()->toDateString(), 'scheduled'); // one group lesson, two member rows
        }

        [$monthly, [$monthlyCourse]] = $this->pool(1, 0);
        $monthly->update(['billing_mode' => 'date']);
        $this->lesson($monthlyCourse, Carbon::today()->addWeek()->toDateString(), 'scheduled');

        $out = inapp_package_ledger_probe(DB::connection(), 1, 0, true);
        $summary = array_column($out['summary'], null, 'package_id');
        $this->assertSame(0, $summary[(int) $monthly->id]['overplanned'], 'a monthly package has no bought-lesson limit');
        $this->assertSame(['future_planned' => 6, 'overplanned' => 3], array_intersect_key($summary[(int) $pool->id], ['future_planned' => 0, 'overplanned' => 0]));
        $this->assertSame(['future_planned' => 1, 'overplanned' => 0], array_intersect_key($summary[(int) $group->id], ['future_planned' => 0, 'overplanned' => 0]));
        $this->assertOnlyAllowedKeys($out);
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

    /** #4740: a deleted lesson's debit is split into the part its course still charges and the pool-only part. */
    public function test_debits_on_deleted_lessons_are_split_by_whether_the_course_still_charges_them(): void
    {
        [$package, [$course]] = $this->pool(1, 16);
        $charged = $this->lesson($course, '2026-10-02');
        $this->debit($package, $course, $charged, 'attendance');
        $this->charge($course, $charged, ['deduct']);
        $orphan = $this->lesson($course, '2026-10-09');
        $this->debit($package, $course, $orphan, 'attendance');
        $this->charge($course, $orphan, ['deduct', 'reverse']);
        $evened = $this->lesson($course, '2026-10-16');
        $this->debit($package, $course, $evened, 'attendance');
        $this->credit($package, $course, $evened, 'status_adjust');
        $this->debit($package, $course, $this->lesson($course, '2026-10-23'), 'attendance'); // still exists: not counted
        DB::table('schedule_audit_logs')->insert(['session_id' => $orphan->id, 'action_type' => 'delete', 'created_at' => now()]);
        DB::table('ClassSession')->whereIn('id', [$charged->id, $orphan->id, $evened->id])->delete();

        $out = inapp_package_ledger_probe(DB::connection(), 1, 0, true);
        $this->assertSame([['package_id' => (int) $package->id, 'sessions' => 3, 'pool_debits' => 2, 'course_charged' => 1, 'pool_only' => 1, 'delete_logged' => 1]],
            array_map(fn (array $row): array => array_map('intval', $row), $out['deleted_lesson_debits']));
        $this->assertOnlyAllowedKeys($out);
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
        [$group, [$math, $english]] = $this->pool(1, 10, 2, null, 'one_on_two');
        $mathLesson = $this->lesson($math, '2026-10-07');
        $englishLesson = $this->lesson($english, '2026-10-07');
        $this->charge($math, $mathLesson, ['deduct']);
        $this->charge($english, $englishLesson, ['deduct']);
        $this->debit($group, $math, $mathLesson, 'attendance');
        // A one-on-one package debits each lesson: two member lessons in one slot need two debits.
        [$solo, [$first, $second]] = $this->pool(1, 10, 2);
        $firstLesson = $this->lesson($first, '2026-10-08');
        $secondLesson = $this->lesson($second, '2026-10-08');
        $this->charge($first, $firstLesson, ['deduct']);
        $this->charge($second, $secondLesson, ['deduct']);
        $this->debit($solo, $first, $firstLesson, 'attendance');

        $out = inapp_package_ledger_probe(DB::connection(), 1, 0, true);

        $this->assertSame([[
            'package_id' => (int) $pool->id, 'scope' => 'lesson', 'session_date' => '2026-09-01', 'start_time' => '16:00',
            'charged_session_ids' => (string) $beforePool->id, 'statuses' => 'attended', 'package_net' => 0, 'charged_before_package' => 1,
        ], [
            'package_id' => (int) $pool->id, 'scope' => 'lesson', 'session_date' => '2026-10-06', 'start_time' => '16:00',
            'charged_session_ids' => (string) $reattended->id, 'statuses' => 'attended', 'package_net' => 0, 'charged_before_package' => 0,
        ], [
            'package_id' => (int) $solo->id, 'scope' => 'lesson', 'session_date' => '2026-10-08', 'start_time' => '16:00',
            'charged_session_ids' => (string) $secondLesson->id, 'statuses' => 'attended', 'package_net' => 0, 'charged_before_package' => 0,
        ]], $out['charged_slots_not_debited']);
        $this->assertSame([1, 0, 1], array_column($out['summary'], 'charged_since_package_not_debited'));
        $this->assertOnlyAllowedKeys($out);
    }

    public function test_a_named_package_lists_each_debit_on_a_lesson_not_taught_with_the_live_lesson_in_its_slot(): void
    {
        [$pool, [$math, $english]] = $this->pool(1, 30, 2, null, 'one_on_two');
        // Debited, then deleted through the model (audited) and re-created with a new id, completed and not charged.
        $old = $this->lesson($math, '2026-10-05');
        $this->debit($pool, $math, $old, 'attended');
        $old->delete();
        $recreated = $this->lesson($math, '2026-10-05', 'completed');
        // Made before the schedule audit existed and removed by a bulk query: only its sign-in date is left.
        $signedIn = $this->lesson($math, '2026-10-12');
        $this->debit($pool, $math, $signedIn, 'attendance');
        DB::table('StudentSingIn')->insert(['StudentID' => $math->StudentID, 'StudentClassID' => $math->ID, 'ClassSessionID' => $signedIn->id,
            'SignInDT' => '2026-10-12 16:05:00', 'Status' => 'present']);
        DB::table('schedule_audit_logs')->where('session_id', $signedIn->id)->delete();
        DB::table('ClassSession')->where('id', $signedIn->id)->delete();
        $sameDay = $this->lesson($english, '2026-10-12', 'attended', '18:00');
        $this->charge($english, $sameDay, ['deduct']);
        $this->debit($pool, $english, $sameDay, 'attendance');
        // A cancelled lesson still debited, no lesson left that day.
        $cancelled = $this->lesson($math, '2026-10-19', 'cancelled');
        $this->debit($pool, $math, $cancelled, 'attendance');
        // A creation seed at the default start, deleted; its lesson lives on at the real start, debited too.
        $seed = $this->lesson($english, '2026-10-22');
        $this->debit($pool, $english, $seed, 'attended');
        $seed->delete();
        $real = $this->lesson($english, '2026-10-22', 'attended', '18:00');
        $this->debit($pool, $english, $real, 'attendance');
        // One slot debited through both members.
        $first = $this->lesson($math, '2026-10-26');
        $second = $this->lesson($english, '2026-10-26');
        $this->charge($math, $first, ['deduct']);
        $this->debit($pool, $math, $first, 'attendance');
        $this->debit($pool, $english, $second, 'attended');

        $out = inapp_package_ledger_probe(DB::connection(), 1, (int) $pool->id);
        $this->assertOnlyAllowedKeys($out);
        $rows = array_map(fn (array $r): array => array_diff_key($r, array_flip(['package_id', 'ledger_id', 'created_at', 'deleted_at'])), $out['unchargeable_session_rows']);
        $this->assertSame([[
            'class_session_id' => (int) $old->id, 'student_class_id' => (int) $math->ID, 'reason' => 'attended', 'delta' => -1, 'status' => 'deleted',
            'last_status' => 'attended', 'session_date' => '2026-10-05', 'start_time' => '16:00', 'slot_source' => 'audit', 'session_net' => -1, 'course_net' => 0,
            'twin_session_id' => (int) $recreated->id, 'twin_student_class_id' => (int) $math->ID, 'twin_start_time' => '16:00', 'twin_status' => 'completed', 'twin_package_net' => 0, 'twin_course_net' => 0,
        ], [
            'class_session_id' => (int) $signedIn->id, 'student_class_id' => (int) $math->ID, 'reason' => 'attendance', 'delta' => -1, 'status' => 'deleted',
            'last_status' => null, 'session_date' => '2026-10-12', 'start_time' => null, 'slot_source' => 'sign_in', 'session_net' => -1, 'course_net' => 0,
            'twin_session_id' => (int) $sameDay->id, 'twin_student_class_id' => (int) $english->ID, 'twin_start_time' => '18:00', 'twin_status' => 'attended', 'twin_package_net' => -1, 'twin_course_net' => 1,
        ], [
            'class_session_id' => (int) $cancelled->id, 'student_class_id' => (int) $math->ID, 'reason' => 'attendance', 'delta' => -1, 'status' => 'cancelled',
            'last_status' => null, 'session_date' => '2026-10-19', 'start_time' => '16:00', 'slot_source' => 'session', 'session_net' => -1, 'course_net' => 0,
            'twin_session_id' => null, 'twin_student_class_id' => null, 'twin_start_time' => null, 'twin_status' => null, 'twin_package_net' => null, 'twin_course_net' => null,
        ], [
            'class_session_id' => (int) $seed->id, 'student_class_id' => (int) $english->ID, 'reason' => 'attended', 'delta' => -1, 'status' => 'deleted',
            'last_status' => 'attended', 'session_date' => '2026-10-22', 'start_time' => '16:00', 'slot_source' => 'audit', 'session_net' => -1, 'course_net' => 0,
            'twin_session_id' => (int) $real->id, 'twin_student_class_id' => (int) $english->ID, 'twin_start_time' => '18:00', 'twin_status' => 'attended', 'twin_package_net' => -1, 'twin_course_net' => 0,
        ]], $rows);
        $this->assertNotNull($out['unchargeable_session_rows'][0]['deleted_at'], 'the audit row dates the deletion');
        $this->assertSame(
            [[(int) $first->id, 'attendance', 1, -2], [(int) $second->id, 'attended', 0, -2]],
            array_map(fn (array $r): array => [$r['class_session_id'], $r['reason'], $r['course_net'], $r['slot_net']], $out['shared_slot_rows'])
        );
        $this->assertArrayNotHasKey('unchargeable_session_rows', inapp_package_ledger_probe(DB::connection(), 1, 0, true), 'rows only for a named package');
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
    private function pool(int $campusId, int $total, int $members = 1, ?int $studentCampusId = null, string $classType = 'one_on_one'): array
    {
        $student = Student::create([
            'name' => '合成學生' . uniqid(), 'CampusID' => $studentCampusId ?? $campusId, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $package = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => $campusId, 'name' => '合成方案', 'total_sessions' => $total,
            'remaining_sessions' => $total, 'used_sessions' => 0, 'rate' => 500, 'rate_unit' => 'session', 'class_type' => $classType,
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

    private function lesson(StudentClass $course, string $date, string $status = 'attended', string $start = '16:00'): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => $start, 'EndTime' => date('H:i', strtotime($start) + 7200), 'Status' => $status,
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
