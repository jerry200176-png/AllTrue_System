<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
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
