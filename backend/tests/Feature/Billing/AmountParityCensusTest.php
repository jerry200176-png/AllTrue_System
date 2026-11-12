<?php

namespace Tests\Feature\Billing;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\AmountParityCensus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #4991 prevention programme item 2 (#3521): nightly `screen_amount_vs_resolver` condition. A bill's header, its
 * lines and the amount the screens show (resolver) must agree; legitimate differences are counted under a named
 * exclusion. The census only reads.
 */
class AmountParityCensusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function census(): array
    {
        return app(AmountParityCensus::class)->run();
    }

    /** @return array<string,mixed> the condition row of the nightly command's JSON */
    private function condition(): array
    {
        $this->assertSame(0, Artisan::call('bugs:verify-reproductions', ['--json' => true]));
        $json = json_decode(Artisan::output(), true);

        return collect($json['conditions'])->firstWhere('key', 'screen_amount_vs_resolver');
    }

    private function student(?int $campusId = null): int
    {
        static $n = 0;

        return (int) Student::create([
            'name' => 'parity' . ++$n, 'CampusID' => $campusId ?? Campus::factory()->create()->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ])->id;
    }

    /** A count-mode contract: the resolver has no second computation for its bills. */
    private function countCourse(?int $studentId = null, array $over = []): int
    {
        return (int) DB::table('StudentClass')->insertGetId($over + [
            'StudentID' => $studentId ?? $this->student(), 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-03-01', 'TotalHours' => 8,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);
    }

    /** A monthly contract with five held July lessons at 1500: the resolver prices July at 7500. */
    private function monthlyCourse(?int $studentId = null): int
    {
        $course = StudentClass::create([
            'StudentID' => $studentId ?? $this->student(), 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-07-01', 'EndDate' => '2026-07-31', 'TotalHours' => 20,
            'Charge' => 6000, 'Paid' => 0, 'Rate' => 1500, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date',
            'SessionCount' => 0, 'SessionDuration' => 120, 'RemainingSessions' => 0, 'ClassType' => 'one_on_one',
            'UsedSessions' => 0, 'settlement_day' => 15, 'monthly_sessions' => 8,
        ]);
        foreach (range(1, 5) as $day) {
            ClassSession::create([
                'StudentClassID' => $course->ID, 'SessionDate' => sprintf('2026-07-%02d', $day),
                'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended', 'Note' => '',
            ]);
        }

        return (int) $course->ID;
    }

    /** @param list<array{0:int|null,1:int}> $lines [course id, amount] */
    private function bill(?int $courseId, int $total, array $lines, array $over = []): int
    {
        $studentId = $courseId ? (int) DB::table('StudentClass')->where('ID', $courseId)->value('StudentID') : $this->student();
        $id = (int) DB::table('Invoice')->insertGetId($over + [
            'StudentID' => $studentId, 'StudentClassID' => $courseId, 'IssueDate' => '2026-07-17', 'DueDate' => '2026-07-17',
            'TotalAmount' => $total, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as [$lineCourse, $amount]) {
            DB::table('InvoiceItem')->insert([
                'InvoiceID' => $id, 'StudentClassID' => $lineCourse, 'Description' => 'x', 'Amount' => $amount,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function pay(int $invoiceId, int $amount): void
    {
        DB::table('Payment')->insert([
            'InvoiceID' => $invoiceId, 'Amount' => $amount, 'Method' => 'cash', 'PaidAt' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_consistent_bill_has_no_finding_and_the_condition_reads_cleared(): void
    {
        $course = $this->countCourse();
        $this->bill($course, 1000, [[$course, 1000]]);

        $row = $this->condition();
        $this->assertSame(0, $row['count']);
        $this->assertSame('OPEN-CLEARED', $row['state']);
        $this->assertFalse($row['enforced']);
        $this->assertSame(1, $row['detail']['scanned']);
        $this->assertSame([], $row['detail']['findings']);
        $this->assertFalse($row['detail']['truncated']);
    }

    public function test_lines_that_do_not_add_up_to_the_header_are_one_header_vs_items_finding(): void
    {
        $course = $this->countCourse();
        $bill = $this->bill($course, 1000, [[$course, 600], [$course, 300]]);

        $out = $this->census();
        $this->assertSame(1, $out['mismatch_count']);
        $this->assertSame(['header_vs_items' => 1, 'header_vs_resolver' => 0], $out['by_type']);
        $this->assertSame($bill, $out['findings'][0]['invoice_id']);
        $this->assertSame('header_vs_items', $out['findings'][0]['type']);
    }

    public function test_a_screen_amount_that_differs_from_the_header_is_one_header_vs_resolver_finding(): void
    {
        $course = $this->monthlyCourse();
        $bill = $this->bill($course, 6000, [[$course, 6000]], ['billing_period' => '2026-07']);

        $out = $this->census();
        $this->assertSame(1, $out['mismatch_count']);
        $this->assertSame(['header_vs_items' => 0, 'header_vs_resolver' => 1], $out['by_type']);
        $this->assertSame($bill, $out['findings'][0]['invoice_id']);
        // Reported, not enforced: a mismatch never fails the nightly job.
        $this->assertSame('OPEN', $this->condition()['state']);
    }

    public function test_each_legitimate_case_is_counted_under_its_named_exclusion_and_is_not_a_finding(): void
    {
        $count = $this->countCourse();
        // voided: lines no longer add up, nobody is shown it as owed.
        $this->bill($count, 1000, [[$count, 1]], ['Status' => 'void']);
        // header-only bill.
        $this->bill($count, 1000, []);
        // money applied: the stored amount stays although the lessons price differently.
        $monthly = $this->monthlyCourse();
        $this->pay($this->bill($monthly, 6000, [[$monthly, 6000]], ['billing_period' => '2026-07', 'Status' => 'paid', 'PaidAmount' => 6000]), 6000);
        // NT$0 with a recorded reason.
        $free = $this->countCourse();
        $this->bill($free, 0, [[$free, 0]], ['Status' => 'paid', 'zero_amount_reason' => 'free_gift']);
        // package / sibling bill: no course on the header.
        $this->bill(null, 800, [[$count, 800]]);
        // one bill for two courses.
        $other = $this->countCourse();
        $this->bill($count, 900, [[$count, 500], [$other, 400]]);

        $out = $this->census();
        $this->assertSame(0, $out['mismatch_count'], json_encode($out['findings']));
        $this->assertSame([
            'voided' => 1,
            'no_item_lines' => 1,
            'settled_amount_frozen' => 1,
            'zero_fee_with_reason' => 1,
            'no_course_on_header' => 1,
            'multi_course_bill' => 1,
            'not_lesson_priced' => 1, // the header-only count-mode bill
            'resolver_error' => 0,
        ], $out['exclusions']);
        $this->assertSame(5, $out['scanned']); // the void bill is not read
    }

    public function test_findings_are_reported_under_the_campus_of_the_bills_student(): void
    {
        $a = (int) Campus::factory()->create()->id;
        $b = (int) Campus::factory()->create()->id;
        $courseA = $this->countCourse($this->student($a));
        $courseB = $this->countCourse($this->student($b));
        $billA = $this->bill($courseA, 1000, [[$courseA, 900]]);
        $billB1 = $this->bill($courseB, 1000, [[$courseB, 900]]);
        $billB2 = $this->bill($courseB, 500, [[$courseB, 100]]);

        $out = $this->census();
        $this->assertSame([$a => ['header_vs_items' => 1], $b => ['header_vs_items' => 2]], $out['by_campus']);
        $campusOf = collect($out['findings'])->pluck('campus_id', 'invoice_id')->all();
        $this->assertSame([$billA => $a, $billB1 => $b, $billB2 => $b], [$billA => $campusOf[$billA], $billB1 => $campusOf[$billB1], $billB2 => $campusOf[$billB2]]);
    }

    public function test_bounds_come_from_config_and_a_cut_short_run_is_marked_truncated(): void
    {
        $course = $this->countCourse();
        $this->bill($course, 1000, [[$course, 1000]]);
        $newest = $this->bill($course, 1000, [[$course, 900]]);
        $this->assertFalse($this->census()['truncated']);

        config(['amount_parity.row_cap' => 1]);
        $out = $this->census();
        $this->assertTrue($out['truncated']);
        $this->assertSame('row_cap', $out['truncated_reason']);
        $this->assertSame(1, $out['scanned']);
        $this->assertSame($newest, $out['findings'][0]['invoice_id']); // newest first

        config(['amount_parity.row_cap' => 100, 'amount_parity.time_limit_seconds' => 0]);
        $out = $this->census();
        $this->assertSame('time_limit', $out['truncated_reason']);
        $this->assertSame(0, $out['scanned']);
        // 0 mismatches from a run that read nothing is not "cleared".
        $this->assertSame('INCOMPLETE', $this->condition()['state']);

        config(['amount_parity.time_limit_seconds' => 60, 'amount_parity.chunk' => 1, 'amount_parity.finding_cap' => 1]);
        $this->bill($course, 1000, [[$course, 800]]);
        $out = $this->census();
        $this->assertSame(2, $out['mismatch_count']); // counts are never capped
        $this->assertCount(1, $out['findings']);
        $this->assertTrue($out['findings_truncated']);
        $this->assertSame(3, $out['scanned']);
    }

    public function test_the_nightly_run_issues_only_selects_and_its_output_carries_ids_and_counts_only(): void
    {
        $course = $this->countCourse();
        $this->bill($course, 1000, [[$course, 900]]);
        $monthly = $this->monthlyCourse();
        $this->bill($monthly, 6000, [[$monthly, 6000]], ['billing_period' => '2026-07']);
        $this->pay($this->bill($monthly, 6000, [], ['billing_period' => '2026-07', 'Status' => 'paid']), 6000);
        $before = [DB::table('Invoice')->get()->toJson(), DB::table('InvoiceItem')->get()->toJson(), DB::table('StudentClass')->get()->toJson()];

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $row = $this->condition();
        $census = $statements;

        $this->assertNotEmpty($census);
        foreach ($census as $sql) {
            $this->assertMatchesRegularExpression('/^\s*\(?\s*select\b/i', $sql, 'the nightly check may only read: ' . $sql);
        }
        $this->assertSame($before, [DB::table('Invoice')->get()->toJson(), DB::table('InvoiceItem')->get()->toJson(), DB::table('StudentClass')->get()->toJson()]);

        $this->assertSame(2, $row['count']);
        foreach ($row['detail']['findings'] as $finding) {
            $this->assertSame(['campus_id', 'invoice_id', 'type'], array_keys($finding));
        }
    }
}
