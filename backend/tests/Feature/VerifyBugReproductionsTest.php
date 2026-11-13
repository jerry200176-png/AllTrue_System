<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
use App\Support\SchedulerEvidence;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #1080 — bug-termination gate. The command must EXIT NON-ZERO when a verified-FIXED bug
 * regresses in the data (here: a leave session that carries a live LearningRecord, the #170
 * reproduction condition), and EXIT ZERO when no enforced condition is violated.
 */
class VerifyBugReproductionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_when_no_enforced_condition_is_violated(): void
    {
        $this->artisan('bugs:verify-reproductions')->assertExitCode(0);
    }

    public function test_canonical_json_fits_failed_evidence_contract_without_changing_failure(): void
    {
        $this->assertSame(0, Artisan::call('bugs:verify-reproductions', ['--json' => true]));
        $raw = Artisan::output();
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $date = CarbonImmutable::parse('2042-06-04 12:00:00', SchedulerEvidence::TIMEZONE);
        $output = SchedulerEvidence::outputPath('bugs-verify-reproductions', $date);
        $ledger = SchedulerEvidence::ledgerPath($date);
        SchedulerEvidence::ensureDirectories();
        @unlink($ledger);
        try {
            file_put_contents($output, $raw);
            // Even a complete zero-regression result cannot override a failed
            // ledger record (e.g. a command/postlude failure).
            SchedulerEvidence::recordCompletion('bugs-verify-reproductions', 'failed', $date);
            $summary = SchedulerEvidence::summarize($date->toDateString(), $date);
            $job = $summary['jobs']['bugs-verify-reproductions'];
            $this->assertNotNull($job['observed_result']);
            $this->assertSame($decoded['regressed'], $job['observed_result']['regressed']);
            $this->assertSame(array_column($decoded['conditions'], 'key'), array_column($job['observed_result']['conditions'], 'key'));
            $this->assertSame('failed', $job['run_status']);
            $this->assertFalse($summary['execution_healthy']);
            $this->assertFalse($job['zero_work']);
            $this->assertSame($raw, file_get_contents($output));
        } finally {
            @unlink($output);
            @unlink($ledger);
        }
    }

    public function test_fails_when_a_fixed_bug_regresses(): void
    {
        $campus = Campus::factory()->create();
        $student = Student::create([
            'name' => '重現閘測試生', 'CampusID' => $campus->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $scId = DB::table('StudentClass')->insertGetId([
            'StudentID' => (int) $student->id, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => now(), 'TotalHours' => 8,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);

        // #170 reproduction: a LEAVE session that still carries a live, non-approved LearningRecord.
        $csId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId, 'SessionDate' => '2020-01-01', 'StartTime' => '10:00',
            'EndTime' => '12:00', 'Status' => 'leave',
        ]);
        DB::table('LearningRecord')->insert([
            'StudentClassID' => $scId, 'ClassSessionID' => $csId, 'TeacherID' => 1,
            'Content' => '', 'Subject' => '英文', 'SessionDate' => '2020-01-01',
            'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'pending',
        ]);

        $this->artisan('bugs:verify-reproductions')->assertExitCode(1);
    }

    // ---- #4659 root-cause-layer invariants: each flags a seeded bad state, passes a clean one, honours its baseline. ----

    /** @return array<string,mixed> condition row from the command's JSON output */
    private function condition(string $key): array
    {
        Artisan::call('bugs:verify-reproductions', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);

        return collect($json['conditions'])->firstWhere('key', $key);
    }

    private function course(array $over = []): int
    {
        static $n = 0;
        $student = Student::create([
            'name' => 'inv' . ++$n, 'CampusID' => Campus::factory()->create()->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);

        return DB::table('StudentClass')->insertGetId($over + [
            'StudentID' => (int) $student->id, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => now(), 'TotalHours' => 8,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);
    }

    private function classSession(int $scId, string $status, string $date = '2020-01-01'): int
    {
        return DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId, 'SessionDate' => $date, 'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => $status,
        ]);
    }

    private function package(): int
    {
        return DB::table('course_packages')->insertGetId([
            'student_id' => 1, 'campus_id' => 1, 'name' => 'p', 'total_sessions' => 10, 'class_type' => 'one_on_one',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function packageDebit(int $pkg, int $scId, ?int $sessionId, string $reason): void
    {
        DB::table('package_session_ledger')->insert([
            'package_id' => $pkg, 'student_class_id' => $scId, 'class_session_id' => $sessionId, 'delta' => -1,
            'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * "Today"/"now" in the conditions come from the app clock (Asia/Taipei), not MySQL CURDATE()/NOW() (DB session
     * timezone, UTC in CI). 00:30 Taipei is 16:30 UTC the day before: the window where the two calendars disagree.
     *
     * @return array<string,array{0:string}>
     */
    public static function taipeiInstants(): array
    {
        return [
            'just after Taipei midnight (UTC is still the day before)' => ['2026-11-14 00:30:00'],
            'Taipei noon (UTC same day)' => ['2026-11-14 12:00:00'],
        ];
    }

    /**
     * in-app #530 / #4991: a shared-pool member whose weekly lessons stopped while the pool still has lessons.
     *
     * @dataProvider taipeiInstants
     */
    public function test_shared_pool_member_generation_stopped_counts_only_starved_running_members(string $taipeiNow = '2026-11-14 00:30:00'): void
    {
        Carbon::setTestNow(Carbon::parse($taipeiNow, 'Asia/Taipei'));
        $key = 'shared_pool_member_generation_stopped';
        $pkg = $this->package(); // 10 bought, count mode
        $starved = $this->course(['PackageID' => $pkg]);
        $sibling = $this->course(['PackageID' => $pkg]);
        $yesterday = now()->subDay()->toDateString();
        $this->packageDebit($pkg, $starved, $this->classSession($starved, 'attended', $yesterday), 'attendance');
        $this->classSession($sibling, 'attended', $yesterday);
        $this->classSession($sibling, 'scheduled', now()->addDays(3)->toDateString());

        $row = $this->condition($key);
        $this->assertSame(1, $row['count'], 'only the member with no upcoming lesson is counted');
        $this->assertFalse($row['enforced']);
        $this->assertSame('OPEN', $row['state']);

        // An upcoming lesson clears it; a cancelled one does not count as upcoming.
        $upcoming = $this->classSession($starved, 'scheduled', now()->addDays(2)->toDateString());
        $this->assertSame(0, $this->condition($key)['count']);
        DB::table('ClassSession')->where('id', $upcoming)->update(['Status' => 'cancelled']);
        $this->assertSame(1, $this->condition($key)['count']);

        // Expected stops are not counted: a pool with nothing left (renewal), a hand-booked member, a lone member.
        DB::table('course_packages')->where('id', $pkg)->update(['total_sessions' => 1]);
        $this->assertSame(0, $this->condition($key)['count']);
        DB::table('course_packages')->where('id', $pkg)->update(['total_sessions' => 10]);
        DB::table('StudentClass')->where('ID', $starved)->update(['scheduling_policy' => 'manual_occurrence']);
        $this->assertSame(0, $this->condition($key)['count']);
        DB::table('StudentClass')->where('ID', $starved)->update(['scheduling_policy' => 'auto_recurrence']);
        DB::table('StudentClass')->where('ID', $sibling)->update(['Stop' => 1]);
        $this->assertSame(0, $this->condition($key)['count']);
    }

    /** @dataProvider taipeiInstants */
    public function test_stranded_prepaid_course_uses_the_app_calendar_day(string $taipeiNow): void
    {
        Carbon::setTestNow(Carbon::parse($taipeiNow, 'Asia/Taipei'));
        $key = 'stranded_prepaid_course';
        $stranded = $this->course(['RemainingSessions' => 3]);
        $this->classSession($stranded, 'attended', now()->subDay()->toDateString()); // yesterday (Taipei): not upcoming
        $running = $this->course(['RemainingSessions' => 3]);
        $this->classSession($running, 'scheduled', now()->toDateString()); // today (Taipei): still upcoming

        $this->assertSame(1, $this->condition($key)['count']);
    }

    /** @dataProvider taipeiInstants */
    public function test_attended_session_without_learning_record_uses_the_app_clock_for_started(string $taipeiNow): void
    {
        Carbon::setTestNow(Carbon::parse($taipeiNow, 'Asia/Taipei'));
        $key = 'attended_session_without_learning_record';
        $before = $this->condition($key)['count'];
        $today = now()->toDateString();
        $started = $this->course();
        $notYet = $this->course();
        // 00:10 has started at both instants; 23:00 has not started at either.
        DB::table('ClassSession')->insert([
            'StudentClassID' => $started, 'SessionDate' => $today, 'StartTime' => '00:10', 'EndTime' => '00:20', 'Status' => 'attended',
        ]);
        DB::table('ClassSession')->insert([
            'StudentClassID' => $notYet, 'SessionDate' => $today, 'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => 'attended',
        ]);

        $this->assertSame($before + 1, $this->condition($key)['count']);
    }

    public function test_package_over_debit_flags_used_beyond_taught_and_not_a_balanced_ledger(): void
    {
        $pkg = $this->package();
        $sc = $this->course(['PackageID' => $pkg]);
        $taught = $this->classSession($sc, 'attended');
        $this->packageDebit($pkg, $sc, $taught, 'attendance');
        $this->assertSame(0, $this->condition('package_over_debit')['count']);

        $this->packageDebit($pkg, $sc, null, 'conversion_seed_orphan'); // used 2 > taught 1
        $this->assertSame(1, $this->condition('package_over_debit')['count']);
    }

    public function test_session_debited_twice_flags_package_and_course_ledgers(): void
    {
        $pkg = $this->package();
        $sc = $this->course(['PackageID' => $pkg]);
        $s = $this->classSession($sc, 'attended');
        $this->packageDebit($pkg, $sc, $s, 'attendance');
        DB::table('session_deduction_ledger')->insert([
            'student_class_id' => $sc, 'class_session_id' => $s, 'event_type' => 'deduct', 'source' => 'attendance',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(0, $this->condition('session_debited_twice')['count']);

        $this->packageDebit($pkg, $sc, $s, 'retro_leave');
        DB::table('session_deduction_ledger')->insert([
            'student_class_id' => $sc, 'class_session_id' => $s, 'event_type' => 'deduct', 'source' => 'learning_record',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(2, $this->condition('session_debited_twice')['count']);
    }

    public function test_unpaid_charge_vs_rate_count_flags_only_a_large_factor(): void
    {
        $this->course(['Rate' => 500, 'SessionCount' => 10, 'Charge' => 5000, 'Paid' => 0]);
        $this->course(['Rate' => 500, 'SessionCount' => 10, 'Charge' => 4000, 'Paid' => 0]); // 20% off: tolerated
        $this->course(['Rate' => 500, 'SessionCount' => 10, 'Charge' => 500, 'Paid' => 1]); // paid: not this check
        $this->assertSame(0, $this->condition('unpaid_charge_vs_rate_count')['count']);

        $this->course(['Rate' => 500, 'SessionCount' => 10, 'Charge' => 500, 'Paid' => 0]);   // Charge x5 < 5000
        $this->course(['Rate' => 500, 'SessionCount' => 10, 'Charge' => 50000, 'Paid' => 0]); // the reverse
        $this->assertSame(2, $this->condition('unpaid_charge_vs_rate_count')['count']);
    }

    public function test_paid_flag_vs_invoice_flags_both_directions(): void
    {
        $inv = fn (int $sc, int $paid): int => DB::table('Invoice')->insertGetId([
            'StudentID' => 1, 'StudentClassID' => $sc, 'IssueDate' => '2026-10-01', 'TotalAmount' => 1000,
            'PaidAmount' => $paid, 'Status' => $paid >= 1000 ? 'paid' : 'unpaid', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pay = fn (int $invoiceId) => DB::table('Payment')->insert([
            'InvoiceID' => $invoiceId, 'Amount' => 1000, 'Method' => 'cash', 'PaidAt' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ok1 = $this->course(['Paid' => 1]);
        $pay($inv($ok1, 1000)); // paid flag + settled invoice
        $ok2 = $this->course(['Paid' => 0]);
        $inv($ok2, 0); // unpaid flag + open invoice
        $this->assertSame(0, $this->condition('paid_flag_vs_invoice')['count']);

        $inv($this->course(['Paid' => 1]), 0); // Paid=1, invoice still owes
        $pay($inv($this->course(['Paid' => 0]), 1000)); // Paid=0, invoice fully paid
        $this->assertSame(2, $this->condition('paid_flag_vs_invoice')['count']);

        // A bill that names the course only on a line (header course id null) counts the same.
        $itemOnly = $this->course(['Paid' => 1]);
        $bill = $inv(0, 0);
        DB::table('Invoice')->where('id', $bill)->update(['StudentClassID' => null]);
        DB::table('InvoiceItem')->insert(['InvoiceID' => $bill, 'StudentClassID' => $itemOnly, 'Amount' => 1000, 'Description' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(3, $this->condition('paid_flag_vs_invoice')['count']);
    }

    public function test_baseline_allows_known_drift_and_fails_only_above_it(): void
    {
        config(['reproduction_baselines.unpaid_charge_vs_rate_count' => null]); // production pins a number; test the unmeasured path
        $this->course(['Rate' => 500, 'SessionCount' => 10, 'Charge' => 500, 'Paid' => 0]);
        $this->assertSame(0, Artisan::call('bugs:verify-reproductions')); // null baseline = pending, reported not failing
        $this->assertSame('BASELINE-PENDING', $this->condition('unpaid_charge_vs_rate_count')['state']);

        config(['reproduction_baselines.unpaid_charge_vs_rate_count' => 1]);
        $this->assertSame(0, Artisan::call('bugs:verify-reproductions'));
        $this->assertSame('WITHIN-BASELINE', $this->condition('unpaid_charge_vs_rate_count')['state']);

        $this->course(['Rate' => 500, 'SessionCount' => 10, 'Charge' => 500, 'Paid' => 0]); // new drift
        $this->assertSame(1, Artisan::call('bugs:verify-reproductions'));
    }
}
