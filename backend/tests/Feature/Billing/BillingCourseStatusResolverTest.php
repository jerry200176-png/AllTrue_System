<?php

namespace Tests\Feature\Billing;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\BillingPayableResolver;
use App\Services\InvoiceAmountReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F7 S1: course-level contract of BillingPayableResolver::courseStatusesByStudentClassIds (additive). */
class BillingCourseStatusResolverTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<array{0:string,1:int,2:string,3:list<array{0:int,1:string}>}> $invoices [period, total, status, [[amount, method]]] */
    private function course(array $invoices, array $attrs = [], array $sessions = []): StudentClass
    {
        $student = Student::create(['name' => 'S1 resolver', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $date = ($attrs['ScheduleMode'] ?? 'count') === 'date';
        $course = StudentClass::create($attrs + [
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => $date ? '2026-07-01' : '2026-08-01', 'EndDate' => $date ? '2026-08-31' : null, 'TotalHours' => 20,
            'Charge' => 10000, 'Paid' => 0, 'Rate' => 1500, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionCount' => $date ? 0 : 10, 'SessionDuration' => 120, 'RemainingSessions' => 2, 'UsedSessions' => 0,
            'ClassType' => 'one_on_one', 'LearnTimeID' => null,
        ]);
        foreach ($sessions as $d) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $d, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        }
        foreach ($invoices as [$period, $total, $status, $payments]) {
            $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => "$period-01", 'DueDate' => "$period-15",
                'TotalAmount' => $total, 'PaidAmount' => 0, 'Status' => $status, 'billing_period' => $period]);
            foreach ($payments as [$amount, $method]) {
                Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $amount, 'PaidAt' => "$period-05", 'Method' => $method]);
            }
        }

        return $course;
    }

    private function resolve(StudentClass $course): array
    {
        return app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([(int) $course->ID], [$course])[(int) $course->ID];
    }

    public function test_kernel_does_not_net_mixed_reversal_encodings(): void
    {
        $course = $this->course([['2026-08', 10000, 'unpaid', [[10000, 'cash'], [-6000, 'cash'], [4000, 'void']]]]);
        $invoice = Invoice::query()->where('StudentClassID', $course->ID)->firstOrFail();

        $this->assertSame(0, app(InvoiceAmountReconciliationService::class)->resolve($invoice, $course)['net_applied']);
    }

    public function test_overpaid_is_capped_in_applied_and_reported(): void
    {
        $r = $this->resolve($this->course([['2026-08', 10000, 'paid', [[6000, 'cash'], [6000, 'cash']]]]));

        $this->assertSame(['paid', 10000, 10000, 0, 2000, 'invoice'], [$r['status'], $r['payable_total'], $r['applied'], $r['outstanding'], $r['overpaid'], $r['source']]);
    }

    public function test_monthly_oldest_unsettled_period_wins_and_july_receipt_does_not_pay_august(): void
    {
        $course = $this->course([
            ['2026-07', 6000, 'paid', [[6000, 'cash']]],
            ['2026-08', 6000, 'unpaid', []],
        ], ['ScheduleMode' => 'date', 'Charge' => 6000], ['2026-08-03', '2026-08-06', '2026-08-10', '2026-08-13', '2026-08-17']);
        $r = $this->resolve($course);

        // #3333: unpaid invoice with no net payment => confirmed sessions x rate (5 x 1500), July receipt stays on July.
        // The period engine's amount_discrepancy review verdict is preserved at course level; period rows keep the facts.
        $this->assertSame(['review_required', 13500, 6000, 7500], [$r['status'], $r['payable_total'], $r['applied'], $r['outstanding']]);
        $this->assertSame(['paid', 'unpaid'], array_column($r['periods'], 'status'));
        $this->assertSame($r['periods'][1]['invoice_ids'][0], $r['current_invoice_id']);
    }

    public function test_legacy_flag_counts_only_without_a_non_void_invoice(): void
    {
        $legacy = $this->resolve($this->course([], ['Paid' => 1]));
        $this->assertSame(['paid', 'legacy_flag', null, 10000], [$legacy['status'], $legacy['source'], $legacy['current_invoice_id'], $legacy['applied']]);

        $voidOnly = $this->resolve($this->course([['2026-08', 10000, 'void', []]], ['Paid' => 1]));
        $this->assertSame('legacy_flag', $voidOnly['source']);

        $flagged = $this->resolve($this->course([['2026-08', 10000, 'unpaid', []]], ['Paid' => 1]));
        $this->assertSame(['unpaid', 'invoice'], [$flagged['status'], $flagged['source']]);

        $none = $this->resolve($this->course([]));
        $this->assertSame(['unbilled', 'none', 10000], [$none['status'], $none['source'], $none['outstanding']]);
    }

    public function test_tutoring_and_zero_fee_are_free_before_any_invoice_or_flag(): void
    {
        $tutoring = $this->resolve($this->course([['2026-08', 5000, 'paid', [[5000, 'cash']]]], ['ClassType' => 'tutoring']));
        $zeroFee = $this->resolve($this->course([], ['Charge' => 0, 'Rate' => 0, 'Paid' => 1]));
        $this->assertSame(['free', 0, 0], [$tutoring['status'], $tutoring['payable_total'], $tutoring['outstanding']]);
        $this->assertSame('free', $zeroFee['status']);

        // A zero Charge/Rate course that still carries a real invoice is billed, not free.
        $billed = $this->resolve($this->course([['2026-08', 5000, 'unpaid', []]], ['Charge' => 0, 'Rate' => 0]));
        $this->assertSame(['unpaid', 5000], [$billed['status'], $billed['outstanding']]);
    }

    public function test_multi_month_date_course_without_invoice_is_review_required_even_with_legacy_flag(): void
    {
        $r = $this->resolve($this->course([], ['ScheduleMode' => 'date', 'Paid' => 1], ['2026-07-03', '2026-08-04']));

        $this->assertSame(['review_required', 'none'], [$r['status'], $r['source']]);
    }

    public function test_period_engine_review_required_is_preserved(): void
    {
        // July-August contract with only a paid July invoice: B15 coverage gap (ambiguous), no unattributed row.
        $r = $this->resolve($this->course([['2026-07', 6000, 'paid', [[6000, 'cash']]]], ['ScheduleMode' => 'date', 'Charge' => 6000]));

        $this->assertSame('review_required', $r['status']);
    }

    public function test_current_invoice_is_an_unsettled_one_when_a_period_has_several(): void
    {
        $course = $this->course([['2026-08', 4000, 'unpaid', []], ['2026-08', 6000, 'paid', [[6000, 'cash']]]]);
        $r = $this->resolve($course);

        $this->assertSame('partial', $r['status']);
        $this->assertSame((int) Invoice::query()->where('StudentClassID', $course->ID)->where('TotalAmount', 4000)->value('id'), $r['current_invoice_id']);
    }

    public function test_default_path_loads_package_state_without_per_course_queries(): void
    {
        $ids = [];
        foreach (range(1, 6) as $i) {
            $course = $this->course([]);
            $pkg = \App\Models\CoursePackage::create(['student_id' => $course->StudentID, 'campus_id' => 1, 'name' => "p$i", 'billing_mode' => 'count',
                'total_sessions' => 10, 'remaining_sessions' => 10, 'used_sessions' => 0, 'rate' => 1000, 'rate_unit' => 'session',
                'class_type' => 'one_on_one', 'paid' => true, 'stop' => false, 'enabled' => true]);
            $course->update(['PackageID' => $pkg->id]);
            $ids[] = (int) $course->ID;
        }
        $count = function (array $subset): int {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            app(BillingPayableResolver::class)->courseStatusesByStudentClassIds($subset);

            return count(\Illuminate\Support\Facades\DB::getQueryLog());
        };

        $this->assertSame($count(array_slice($ids, 0, 1)), $count($ids));
        $this->assertSame('paid', app(BillingPayableResolver::class)->courseStatusesByStudentClassIds($ids)[$ids[0]]['status']);
    }

    public function test_positive_charge_with_only_a_zero_value_invoice_is_not_settled(): void
    {
        $r = $this->resolve($this->course([['2026-08', 0, 'paid', []]], ['Charge' => 10000]));

        $this->assertSame('review_required', $r['status']);
    }

    public function test_unknown_course_ids_are_omitted_not_free(): void
    {
        $this->assertSame([], app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([987654]));
    }

    public function test_package_member_with_zero_charge_is_not_free(): void
    {
        $member = fn (bool $paid) => (function () use ($paid) {
            $course = $this->course([], ['Charge' => 0, 'Rate' => 0]);
            $pkg = \App\Models\CoursePackage::create(['student_id' => $course->StudentID, 'campus_id' => 1, 'name' => 'pz', 'billing_mode' => 'count',
                'total_sessions' => 10, 'remaining_sessions' => 10, 'used_sessions' => 0, 'rate' => 1000, 'rate_unit' => 'session',
                'class_type' => 'one_on_one', 'paid' => $paid, 'stop' => false, 'enabled' => true]);
            $course->update(['PackageID' => $pkg->id]);

            return $this->resolve($course->fresh());
        })();

        $this->assertSame('review_required', $member(false)['status']);
        $this->assertSame('paid', $member(true)['status']);
    }
}
