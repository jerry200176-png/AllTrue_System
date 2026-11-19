<?php

namespace Tests\Feature\Billing;

use App\Http\Controllers\PaymentReportController;
use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Billing\PaidFlagInvoiceMismatch;
use App\Services\BillingPayableResolver;
use App\Services\MonthlyBillingService;
use App\Services\PaymentReportTokenService;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * in-app #432 (Founder 2026-10-28, option A): a monthly contract that spans calendar months is billed by calendar
 * month, oldest unbilled month first, in 登記收款, confirm, the slip and the drawer form. Shape of course 3458:
 * 2026-08-31 to 2026-09-30, one countable lesson in August, three in September, two leave days.
 */
class MonthlyCalendarMonthCollectionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 18:00:00');
        $user = User::create(['LoginName' => 'd432@test.com', 'Name' => 'd', 'PSW' => 'x', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** in-app #478 (course 1695 shape): an unpaid monthly plan member with stored Charge 0 owes its own lessons x rate. */
    public function test_monthly_plan_member_collects_its_own_lessons(): void
    {
        $sc = $this->course('2026-05-24', '2026-05-31', ['2026-05-24' => 'attended', '2026-05-31' => 'attended'], ['Charge' => 0, 'Rate' => 1000, 'Stop' => 1]);
        $pkg = \Illuminate\Support\Facades\DB::table('course_packages')->insertGetId(['student_id' => $sc->StudentID, 'campus_id' => 1, 'name' => 'p',
            'total_sessions' => 0, 'rate' => 1000, 'class_type' => 'one_on_three', 'paid' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $sc->update(['PackageID' => $pkg]);

        $this->api('getJson', "/api/v1/alerts/tuition-slip/{$sc->ID}")->assertOk()
            ->assertJsonPath('billing_period', '2026-05')->assertJsonPath('charge', 2000);
        $this->record($sc, 1000)->assertStatus(422)->assertJsonPath('expected_amount', 2000);
        $this->confirm($this->record($sc, 2000)->assertOk()->json('report_id'));
        $this->assertSame([['2026-05', 2000]], $this->bills($sc));
    }

    /** Codex P1 on #4867: paying one monthly member leaves its unpaid sibling collectible (no package-wide Paid flag). */
    public function test_paying_one_monthly_plan_member_keeps_its_sibling_collectible(): void
    {
        $a = $this->course('2026-05-24', '2026-05-31', ['2026-05-24' => 'attended', '2026-05-31' => 'attended'], ['Charge' => 0, 'Rate' => 1000, 'Stop' => 1]);
        $b = $this->course('2026-05-24', '2026-05-31', ['2026-05-24' => 'attended', '2026-05-31' => 'attended'], ['Charge' => 0, 'Rate' => 1000, 'Stop' => 1, 'StudentID' => $a->StudentID]);
        $pkg = \Illuminate\Support\Facades\DB::table('course_packages')->insertGetId(['student_id' => $a->StudentID, 'campus_id' => 1, 'name' => 'p',
            'total_sessions' => 0, 'rate' => 1000, 'class_type' => 'one_on_three', 'paid' => 0, 'created_at' => now(), 'updated_at' => now()]);
        StudentClass::whereIn('ID', [$a->ID, $b->ID])->update(['PackageID' => $pkg]);

        $this->confirm($this->record($a->fresh(), 2000)->assertOk()->json('report_id'));
        $this->assertSame(0, (int) \Illuminate\Support\Facades\DB::table('course_packages')->where('id', $pkg)->value('paid'));
        $this->assertSame(0, (int) $b->fresh()->Paid);
        $this->confirm($this->record($b->fresh(), 2000)->assertOk()->json('report_id'));
        $this->assertSame([['2026-05', 2000]], $this->bills($b));
    }

    public function test_confirming_a_positive_shared_bill_synchronizes_both_named_count_contracts(): void
    {
        [$header, $line, $bill] = $this->sharedCountBill();
        $reportId = $this->record($header, 4000)->assertOk()->json('report_id');
        $this->assertSame('pending', (string) PaymentReport::findOrFail($reportId)->status);
        $this->assertSame([0, 0], [(int) $header->fresh()->Paid, (int) $line->fresh()->Paid]);
        $this->assertSame(0, $bill->payments()->count());

        $this->confirm($reportId);
        $this->assertSame('confirmed', (string) PaymentReport::findOrFail($reportId)->status);
        $this->assertSame('paid', (string) $bill->fresh()->Status);
        $this->assertSame(1, $bill->payments()->count());
        $this->assertSame(4000, (int) $bill->payments()->sum('Amount'));
        $verdicts = app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$header->ID, $line->ID]);
        $mismatches = array_column((new PaidFlagInvoiceMismatch())->rows(), 'side', 'student_class_id');
        $this->assertSame(
            ['paid_flags' => [1, 1], 'bill_statuses' => ['paid', 'paid'], 'nightly_side' => [null, null]],
            [
                'paid_flags' => [(int) $header->fresh()->Paid, (int) $line->fresh()->Paid],
                'bill_statuses' => [$verdicts[$header->ID]['status'], $verdicts[$line->ID]['status']],
                'nightly_side' => [$mismatches[$header->ID] ?? null, $mismatches[$line->ID] ?? null],
            ],
        );
    }

    public function test_reversing_a_positive_shared_bill_clears_both_named_count_contracts_when_nothing_remains_paid(): void
    {
        [$header, $line, $bill] = $this->sharedCountBill();
        $reportId = $this->record($header, 4000)->assertOk()->json('report_id');
        $this->confirm($reportId);

        // Seed the correctly settled initial flag so this reversal regression is independent of the confirm regression above.
        $line->update(['Paid' => 1, 'PayDate' => '2026-10-23']);
        $this->assertSame([1, 1], [(int) $header->fresh()->Paid, (int) $line->fresh()->Paid]);

        $this->api('putJson', "/api/v1/payment-reports/{$reportId}/void", ['void_reason' => 'Synthetic reversal'])->assertOk();
        $this->assertSame('voided', (string) PaymentReport::findOrFail($reportId)->status);
        $this->assertSame('unpaid', (string) $bill->fresh()->Status);
        $this->assertSame(2, $bill->payments()->count());
        $this->assertSame(0, (int) $bill->payments()->sum('Amount'));
        $mismatches = array_column((new PaidFlagInvoiceMismatch())->rows(), 'side', 'student_class_id');
        $this->assertSame(
            ['paid_flags' => [0, 0], 'nightly_side' => [null, null]],
            [
                'paid_flags' => [(int) $header->fresh()->Paid, (int) $line->fresh()->Paid],
                'nightly_side' => [$mismatches[$header->ID] ?? null, $mismatches[$line->ID] ?? null],
            ],
        );
    }

    public function test_partial_shared_count_payment_does_not_allocate_to_the_line_or_start_a_second_bill(): void
    {
        [$header, $line, $bill] = $this->sharedCountBill();
        $this->confirm($this->record($header, 2000)->assertOk()->json('report_id'));

        $this->assertSame('partial', (string) $bill->fresh()->Status);
        $this->assertSame([0, 0], [(int) $header->fresh()->Paid, (int) $line->fresh()->Paid]);
        $lineStatus = app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$line->ID])[$line->ID];
        $this->assertSame('review_required', $lineStatus['status']);
        $this->assertSame(0, $lineStatus['applied']);
        $this->record($line, 2000)->assertStatus(422)
            ->assertJsonPath('code', 'course_on_shared_bill')->assertJsonPath('invoice_id', $bill->id);
        $this->assertSame([1, 1, 1], [Invoice::count(), $bill->payments()->count(), PaymentReport::count()]);
    }

    public function test_direct_shared_count_payment_and_exception_reversal_update_both_course_marks(): void
    {
        [$header, $line, $bill] = $this->sharedCountBill();
        $this->api('postJson', "/api/v1/invoices/{$bill->id}/payments", ['Amount' => 4000])->assertOk();
        $this->assertSame([1, 1], [(int) $header->fresh()->Paid, (int) $line->fresh()->Paid]);
        $evidence = app(BillingPayableResolver::class)->validPaymentEvidence([$line->ID], [], [1]);
        $this->assertSame([$line->ID => true], $evidence['classes']);

        $this->api('postJson', "/api/v1/invoices/{$bill->id}/exception-void", ['reason' => 'Synthetic reversal'])->assertOk();
        $this->assertSame([0, 0], [(int) $header->fresh()->Paid, (int) $line->fresh()->Paid]);
        $this->assertSame('void', (string) $bill->fresh()->Status);
        $this->assertSame(2, $bill->payments()->count());
        $this->assertSame(0, (int) $bill->payments()->sum('Amount'));
        $this->assertSame(0, PaymentReport::count());
    }

    public function test_legacy_invoice_item_link_to_another_student_cannot_supply_paid_evidence(): void
    {
        [$header, $line, $bill] = $this->sharedCountBill();
        $other = $this->course('2026-10-01', '2026-11-30', [], ['ScheduleMode' => 'count']);
        $bill->items()->where('StudentClassID', $line->ID)->update(['StudentClassID' => $other->ID]);
        $this->api('postJson', "/api/v1/invoices/{$bill->id}/payments", ['Amount' => 4000])->assertOk();

        $this->assertSame([1, 0, 0], [(int) $header->fresh()->Paid, (int) $line->fresh()->Paid, (int) $other->fresh()->Paid]);
        $resolver = app(BillingPayableResolver::class);
        $this->assertSame('unbilled', $resolver->courseStatusesByStudentClassIds([$other->ID])[$other->ID]['status']);
        $this->assertSame([], $resolver->validPaymentEvidence([$other->ID], [], [1])['classes']);

        $this->api('postJson', "/api/v1/invoices/{$bill->id}/exception-void", ['reason' => 'Synthetic reversal'])->assertOk();
        $this->assertSame([0, 0, 0], [(int) $header->fresh()->Paid, (int) $line->fresh()->Paid, (int) $other->fresh()->Paid]);
        $this->assertSame('void', (string) $bill->fresh()->Status);
        $this->assertSame([2, 0], [$bill->payments()->count(), (int) $bill->payments()->sum('Amount')]);
    }

    public function test_confirm_refuses_a_shared_bill_whose_student_changed_after_intake(): void
    {
        [$header, $line, $bill] = $this->sharedCountBill();
        $reportId = $this->record($header, 4000)->assertOk()->json('report_id');
        $other = $this->course('2026-10-01', '2026-11-30', []);
        $bill->update(['StudentID' => $other->StudentID]);

        $this->api('putJson', "/api/v1/payment-reports/{$reportId}/confirm")->assertStatus(409)
            ->assertJsonPath('code', 'invoice_owner_changed');
        $this->assertSame('pending', (string) PaymentReport::findOrFail($reportId)->status);
        $this->assertSame([0, 0], [(int) $header->fresh()->Paid, (int) $line->fresh()->Paid]);
        $this->assertSame(0, $bill->payments()->count());
    }

    public function test_a_refunded_line_is_not_marked_paid_when_an_old_shared_bill_is_settled(): void
    {
        [$header, $line, $bill] = $this->sharedCountBill();
        $line->update(['closed_reason' => 'refunded']);
        $this->confirm($this->record($header, 4000)->assertOk()->json('report_id'));

        $this->assertSame('refunded', (string) $line->fresh()->closed_reason);
        $this->assertSame([1, 0], [(int) $header->fresh()->Paid, (int) $line->fresh()->Paid]);
        $this->assertSame([], app(BillingPayableResolver::class)->validPaymentEvidence([$line->ID], [], [1])['classes']);
        $this->assertSame(1, $bill->payments()->count());
    }

    public function test_voiding_a_legacy_zero_bill_clears_a_paid_mark_when_the_shared_bill_is_unpaid(): void
    {
        [, $line, $bill] = $this->sharedCountBill();
        $line->update(['Paid' => 1]);
        $zero = Invoice::create([
            'StudentID' => $line->StudentID, 'StudentClassID' => $line->ID, 'IssueDate' => '2026-10-22',
            'TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'paid',
        ]);

        $this->api('postJson', "/api/v1/invoices/{$zero->id}/void", ['reason' => 'Synthetic cleanup'])->assertOk();
        $this->assertSame('void', (string) $zero->fresh()->Status);
        $this->assertSame('unpaid', (string) $bill->fresh()->Status);
        $this->assertSame(0, (int) $line->fresh()->Paid);
        $this->assertSame(0, $bill->payments()->count());
    }

    public function test_voiding_a_legacy_receipt_without_a_bill_keeps_separate_confirmed_shared_coverage(): void
    {
        [$header, $line, $bill] = $this->sharedCountBill();
        $this->confirm($this->record($header, 4000)->assertOk()->json('report_id'));
        $legacy = PaymentReport::create([
            'StudentID' => $line->StudentID, 'StudentClassID' => $line->ID, 'reported_by_name' => 'Synthetic historic report',
            'payment_date' => '2026-10-22', 'payment_method' => 'cash', 'reported_amount' => 100, 'status' => 'confirmed',
            'report_token_hash' => str_repeat('a', 64), 'token_expires_at' => now()->addDay(),
        ]);

        $this->api('putJson', "/api/v1/payment-reports/{$legacy->id}/void", ['void_reason' => 'Synthetic historic cleanup'])->assertOk();
        $this->assertSame('voided', (string) $legacy->fresh()->status);
        $this->assertSame('paid', (string) $bill->fresh()->Status);
        $this->assertSame(1, $bill->payments()->count());
        $this->assertSame(1, (int) $line->fresh()->Paid);
    }

    public function test_batch_confirmation_reports_a_stale_item_scope_after_an_earlier_success(): void
    {
        [$first, , $firstBill] = $this->sharedCountBill();
        [$second, , $secondBill] = $this->sharedCountBill();
        $extra = $this->course('2026-10-01', '2026-11-30', [], ['StudentID' => $second->StudentID, 'ScheduleMode' => 'count']);
        $firstReport = $this->record($first, 4000)->assertOk()->json('report_id');
        $secondReport = $this->record($second, 4000)->assertOk()->json('report_id');
        $routed = false;
        DB::listen(function (QueryExecuted $query) use ($second, $secondBill, $extra, &$routed) {
            $sql = strtolower($query->sql);
            if (!$routed && str_contains($sql, '`studentclass`') && str_contains($sql, 'for update')
                && in_array((int) $second->ID, array_map('intval', $query->bindings), true)) {
                $routed = true;
                // Introduce an item outside the scope that the API routed before this lock was acquired.
                $secondBill->items()->create(['StudentClassID' => $extra->ID, 'Description' => 'Synthetic scope drift', 'Amount' => 100]);
            }
        });

        $this->api('postJson', '/api/v1/payment-reports/confirm-batch', ['ids' => [$firstReport, $secondReport]])
            ->assertStatus(207)->assertJsonPath('accepted', 1)
            ->assertJsonPath('results.0.http_status', 200)
            ->assertJsonPath('results.1.http_status', 409)->assertJsonPath('results.1.code', 'invoice_owner_changed');
        $this->assertTrue($routed);
        $this->assertSame('confirmed', (string) PaymentReport::findOrFail($firstReport)->status);
        $this->assertSame('pending', (string) PaymentReport::findOrFail($secondReport)->status);
        $this->assertSame([1, 0], [$firstBill->payments()->count(), $secondBill->payments()->count()]);
    }

    /** Codex P1 #2 on #4867: a multi-course bill headed by a monthly plan member keeps its stored total on confirm. */
    public function test_multi_course_bill_through_a_monthly_plan_member_keeps_its_total(): void
    {
        $a = $this->course('2026-10-01', '2026-10-31', ['2026-10-05' => 'attended', '2026-10-12' => 'attended'], ['Charge' => 0, 'Rate' => 1000]);
        $b = $this->course('2026-10-01', '2026-10-31', ['2026-10-06' => 'attended', '2026-10-13' => 'attended'], ['Charge' => 0, 'Rate' => 1000, 'StudentID' => $a->StudentID]);
        $pkg = \Illuminate\Support\Facades\DB::table('course_packages')->insertGetId(['student_id' => $a->StudentID, 'campus_id' => 1, 'name' => 'p',
            'total_sessions' => 0, 'rate' => 1000, 'class_type' => 'one_on_three', 'paid' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $a->update(['PackageID' => $pkg]);
        $bill = Invoice::create(['StudentID' => $a->StudentID, 'StudentClassID' => $a->ID, 'IssueDate' => '2026-10-01', 'TotalAmount' => 4000,
            'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-10']);
        $bill->items()->create(['StudentClassID' => $a->ID, 'Description' => 'a', 'Amount' => 2000]);
        $bill->items()->create(['StudentClassID' => $b->ID, 'Description' => 'b', 'Amount' => 2000]);

        // Every surface asks for the stored 4000, not the header course's own 2000 (Codex round 3).
        $this->api('getJson', "/api/v1/alerts/tuition-slip/{$a->ID}")->assertOk()->assertJsonPath('charge', 4000);
        $resolved = app(\App\Services\BillingPayableResolver::class)->byStudentClassIds([$a->ID], [$a->fresh()])[$a->ID];
        $this->assertSame(4000, (int) ($resolved['payable_outstanding'] ?? -1), json_encode($resolved));
        $this->record($a->fresh(), 2000)->assertStatus(422)->assertJsonPath('expected_amount', 4000);
        $this->confirm($this->record($a->fresh(), 4000)->assertOk()->json('report_id'));
        $bill->refresh();
        $this->assertSame([4000, 4000, 'paid'], [(int) $bill->TotalAmount, (int) $bill->PaidAmount, (string) $bill->Status]);
        // Codex round 4: no header-only discrepancy, so the paid member settles instead of staying in review.
        $after = app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([$a->ID], [$a->fresh()])[$a->ID];
        $this->assertSame('paid', $after['status'], json_encode($after));
        $row = collect($this->api('getJson', '/api/v1/student-classes?branch_id=1&student_id='.$a->StudentID)->assertOk()->json('data'))->firstWhere('ID', $a->ID);
        $this->assertSame('paid', $row['payment_status']);
    }

    /** Codex round 6 on #4867: a month already on a shared bill is refused through the other member, not collected again. */
    public function test_shared_bill_month_is_refused_through_the_other_member(): void
    {
        $lessons = ['2026-10-05' => 'attended', '2026-10-12' => 'attended'];
        $a = $this->course('2026-10-01', '2026-10-31', $lessons, ['Charge' => 0, 'Rate' => 1000]);
        $b = $this->course('2026-10-01', '2026-10-31', $lessons, ['Charge' => 0, 'Rate' => 1000, 'StudentID' => $a->StudentID]);
        $c = $this->course('2026-10-01', '2026-10-31', $lessons, ['Charge' => 0, 'Rate' => 1000, 'StudentID' => $a->StudentID]);
        $pkg = \Illuminate\Support\Facades\DB::table('course_packages')->insertGetId(['student_id' => $a->StudentID, 'campus_id' => 1, 'name' => 'p',
            'total_sessions' => 0, 'rate' => 1000, 'class_type' => 'one_on_three', 'paid' => 0, 'created_at' => now(), 'updated_at' => now()]);
        StudentClass::whereIn('ID', [$a->ID, $b->ID, $c->ID])->update(['PackageID' => $pkg]);
        $bill = Invoice::create(['StudentID' => $a->StudentID, 'StudentClassID' => $a->ID, 'IssueDate' => '2026-10-01', 'TotalAmount' => 4000,
            'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-10']);
        $bill->items()->create(['StudentClassID' => $a->ID, 'Description' => 'a', 'Amount' => 2000]);
        $bill->items()->create(['StudentClassID' => $b->ID, 'Description' => 'b', 'Amount' => 2000]);
        $this->confirm($this->record($a->fresh(), 4000)->assertOk()->json('report_id'));

        $counts = fn () => [Invoice::count(), \App\Models\Payment::count(), PaymentReport::count()];
        $before = $counts();
        $this->record($b->fresh(), 2000)->assertStatus(422)->assertJsonPath('code', 'period_on_shared_bill')->assertJsonPath('invoice_id', $bill->id);
        $b->update(['Paid' => 1]); // the legacy-flag backfill path needs a flag-only course
        $this->api('postJson', '/api/v1/payment-reports/director-record', ['student_class_id' => $b->ID, 'payment_date' => '2026-10-23',
            'payment_method' => 'cash', 'amount' => 2000, 'legacy_flag_backfill' => true, 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(422)->assertJsonPath('code', 'period_on_shared_bill');
        $this->assertSame($before, $counts());
        // Codex P1: a parent's public report for B is refused at confirmation too, leaving it pending and the ledger unchanged.
        $b->update(['Paid' => 0]);
        $public = PaymentReport::create(['StudentID' => $b->StudentID, 'StudentClassID' => $b->ID, 'reported_by_name' => 'p', 'payment_date' => '2026-10-23',
            'payment_method' => 'transfer', 'reported_amount' => 2000, 'status' => 'pending', 'report_token_hash' => str_repeat('c', 64), 'token_expires_at' => now()->addDay()]);
        $before = $counts();
        $this->api('putJson', "/api/v1/payment-reports/{$public->id}/confirm")->assertStatus(422)->assertJsonPath('code', 'period_on_shared_bill');
        $this->assertSame($before, $counts());
        $this->assertSame('pending', (string) $public->fresh()->status);

        // The sibling with no item on that bill is still collectible.
        $this->confirm($this->record($c->fresh(), 2000)->assertOk()->json('report_id'));
        $this->assertSame([['2026-10', 2000]], $this->bills($c));
    }

    /** Codex round 3 on #4867: a single-month plan member with Charge 0 and no bill is not 'paid' in the course list. */
    public function test_single_month_plan_member_without_bill_is_not_paid_in_the_course_list(): void
    {
        $sc = $this->course('2026-10-01', '2026-10-31', ['2026-10-05' => 'attended', '2026-10-12' => 'attended'], ['Charge' => 0, 'Rate' => 1000]);
        $pkg = \Illuminate\Support\Facades\DB::table('course_packages')->insertGetId(['student_id' => $sc->StudentID, 'campus_id' => 1, 'name' => 'p',
            'total_sessions' => 0, 'rate' => 1000, 'class_type' => 'one_on_three', 'paid' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $sc->update(['PackageID' => $pkg]);
        $status = fn () => collect($this->api('getJson', '/api/v1/student-classes?branch_id=1&student_id='.$sc->StudentID)->assertOk()->json('data'))
            ->firstWhere('ID', $sc->ID);

        $this->assertNotSame('paid', $status()['payment_status']);
        $this->assertFalse(($status()['monthly_payment']['payment_status'] ?? null) === 'paid');
        $this->record($sc->fresh(), 2000)->assertOk();
        $this->assertNotSame('paid', $status()['payment_status']);
        $this->assertFalse(($status()['monthly_payment']['payment_status'] ?? null) === 'paid');
    }

    /** Codex P2 on #4867: a legacy plan member whose package alone is marked paid (Paid 0, no bill) still lists as paid. */
    public function test_plan_member_paid_through_its_package_flag_lists_as_paid(): void
    {
        $sc = $this->course('2026-10-01', '2026-10-31', ['2026-10-05' => 'attended', '2026-10-12' => 'attended'], ['Charge' => 0, 'Rate' => 1000]);
        $pkg = \Illuminate\Support\Facades\DB::table('course_packages')->insertGetId(['student_id' => $sc->StudentID, 'campus_id' => 1, 'name' => 'p',
            'total_sessions' => 0, 'rate' => 1000, 'class_type' => 'one_on_three', 'paid' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $sc->update(['PackageID' => $pkg, 'Paid' => 0]);
        $row = collect($this->api('getJson', '/api/v1/student-classes?branch_id=1&student_id='.$sc->StudentID)->assertOk()->json('data'))->firstWhere('ID', $sc->ID);
        $this->assertSame('paid', $row['monthly_payment']['payment_status'] ?? null);
        $this->assertSame('paid', $row['payment_status']);
    }

    /** Codex P2 on #4867: a monthly plan member (Charge 0) with lessons in two months stays collectible month by month. */
    public function test_monthly_plan_member_cross_month_collects_august_then_september(): void
    {
        $sc = $this->course('2026-08-31', '2026-09-30', ['2026-08-31' => 'attended', '2026-09-07' => 'attended', '2026-09-23' => 'attended'], ['Charge' => 0, 'Rate' => 1000]);
        $pkg = \Illuminate\Support\Facades\DB::table('course_packages')->insertGetId(['student_id' => $sc->StudentID, 'campus_id' => 1, 'name' => 'p',
            'total_sessions' => 0, 'rate' => 1000, 'class_type' => 'one_on_three', 'paid' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $sc->update(['PackageID' => $pkg]);

        $this->confirm($this->record($sc->fresh(), 1000)->assertOk()->json('report_id'));
        $this->assertSame([['2026-08', 1000]], $this->bills($sc));
        $this->assertSame(0, (int) $sc->fresh()->Paid);
        $this->confirm($this->record($sc->fresh(), 2000)->assertOk()->json('report_id'));
        $this->assertSame([['2026-08', 1000], ['2026-09', 2000]], $this->bills($sc));
        $this->record($sc->fresh(), 1000)->assertStatus(422)->assertJsonPath('code', 'course_already_paid');
    }

    public function test_cross_month_contract_collects_august_then_september(): void
    {
        $sc = $this->course('2026-08-31', '2026-09-30', [
            '2026-08-31' => 'attended', '2026-09-07' => 'attended', '2026-09-20' => 'leave',
            '2026-09-21' => 'leave', '2026-09-23' => 'attended', '2026-09-29' => 'attended',
        ]);

        // The slip and the drawer form name August and its amount: the same server answer as the refusal below.
        $this->api('getJson', "/api/v1/alerts/tuition-slip/{$sc->ID}")->assertOk()
            ->assertJsonPath('billing_period', '2026-08')->assertJsonPath('charge', 1650);
        $ledger = collect($this->api('getJson', "/api/v1/accounting/ledger?student_class_id={$sc->ID}")->assertOk()->json('courses'));
        $this->assertSame(['billing_period' => '2026-08', 'amount' => 1650], $ledger->firstWhere('id', $sc->ID)['collection_period']);

        // A September amount first is refused, naming August.
        $refused = $this->record($sc, 4950)->assertStatus(422)->assertJsonPath('code', 'monthly_amount_stale')
            ->assertJsonPath('billing_period', '2026-08')->assertJsonPath('expected_amount', 1650);
        $this->assertStringContainsString('2026 年 8 月', $refused->json('message'));

        $this->confirm($this->record($sc, 1650)->assertOk()->json('report_id'));
        $this->assertSame([['2026-08', 1650]], $this->bills($sc));

        // August is paid; the slip and the drawer form move on to September (#4573 review).
        $this->api('getJson', "/api/v1/alerts/tuition-slip/{$sc->ID}")->assertOk()
            ->assertJsonPath('billing_period', '2026-09')->assertJsonPath('charge', 4950);
        $ledger = collect($this->api('getJson', "/api/v1/accounting/ledger?student_class_id={$sc->ID}")->assertOk()->json('courses'));
        $this->assertSame(['billing_period' => '2026-09', 'amount' => 4950], $ledger->firstWhere('id', $sc->ID)['collection_period']);

        // September still has countable lessons, so "already paid" must not refuse.
        $this->confirm($this->record($sc, 4950)->assertOk()->json('report_id'));
        $this->assertSame([['2026-08', 1650], ['2026-09', 4950]], $this->bills($sc));

        // Every month billed and paid: the already-paid refusal still fires.
        $this->record($sc, 1650)->assertStatus(422)->assertJsonPath('code', 'course_already_paid');
    }

    public function test_single_month_contract_keeps_the_payment_date_month(): void
    {
        $sc = $this->course('2026-10-01', '2026-10-31', ['2026-10-05' => 'attended', '2026-10-12' => 'attended']);

        $this->record($sc, 1650)->assertStatus(422)->assertJsonPath('billing_period', '2026-10')->assertJsonPath('expected_amount', 3300);
        $this->confirm($this->record($sc, 3300)->assertOk()->json('report_id'));
        $this->assertSame([['2026-10', 3300]], $this->bills($sc));
        $this->record($sc, 3300)->assertStatus(422)->assertJsonPath('code', 'course_already_paid');
    }

    public function test_rule_leaves_count_mode_packages_and_partly_billed_months_to_the_old_rule(): void
    {
        $billing = app(MonthlyBillingService::class);
        $lessons = ['2026-08-31' => 'attended', '2026-09-29' => 'attended'];
        $this->assertNull($billing->unbilledPeriodFor($this->course('2026-08-31', '2026-09-30', $lessons, ['ScheduleMode' => 'count'])));

        // A renewal-style bill labelled 2026-08 covering 8/28–9/27 leaves 9/29 uncovered: September is partly billed,
        // so pricing the whole calendar month would bill 9/1–9/27 twice. Keep today's rule.
        $sc = $this->course('2026-08-28', '2026-09-30', ['2026-08-31' => 'attended', '2026-09-07' => 'attended', '2026-09-29' => 'attended']);
        $bill = Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-08-28', 'TotalAmount' => 3300,
            'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-08']);
        $bill->items()->create(['StudentClassID' => $sc->ID, 'Description' => 'cycle', 'Amount' => 3300, 'PeriodStart' => '2026-08-28', 'PeriodEnd' => '2026-09-27']);
        $this->assertNull($billing->unbilledPeriodFor($sc));
        $this->assertSame('2026-09', $billing->collectionPeriodFor($sc, '2026-10-23'));
    }

    public function test_confirm_refuses_when_the_collection_month_moved_after_record(): void
    {
        // #4574: record checks September (August has no countable lesson yet); an August lesson is then marked attended.
        // Equal monthly amounts pass every amount guard, so confirm must refuse instead of billing the payment to August.
        $sc = $this->course('2026-08-31', '2026-09-30', ['2026-08-31' => 'scheduled', '2026-09-07' => 'attended']);
        $reportId = $this->record($sc, 1650)->assertOk()->json('report_id');
        $this->assertSame('2026-09', PaymentReport::findOrFail($reportId)->billing_period);

        ClassSession::query()->where('StudentClassID', $sc->ID)->whereDate('SessionDate', '2026-08-31')->update(['Status' => 'attended']);
        $refused = $this->api('putJson', "/api/v1/payment-reports/{$reportId}/confirm")->assertStatus(422)
            ->assertJsonPath('code', 'monthly_period_changed')->assertJsonPath('billing_period', '2026-08');
        $this->assertStringContainsString('2026 年 9 月', $refused->json('message'));
        $this->assertSame([], $this->bills($sc));
        $this->assertSame('pending', PaymentReport::findOrFail($reportId)->status);
    }

    public function test_pay_link_names_the_owing_bill_before_an_unbilled_month(): void
    {
        // #4573 review: submit/confirm pay the owing October bill first, so the link must name October, not August.
        $sc = $this->course('2026-08-01', '2026-10-31', ['2026-08-31' => 'attended', '2026-10-05' => 'attended']);
        Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-10-01', 'TotalAmount' => 1650,
            'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-10']);
        $controller = app(PaymentReportController::class);
        $tokens = app(PaymentReportTokenService::class);

        $link = $controller->generateLink(Request::create('/x', 'POST', ['student_class_id' => $sc->ID]), $tokens)->getData(true);
        $form = $controller->formData(Request::create('/x', 'GET', ['token' => $link['token']]), $tokens)->getData(true);
        $this->assertSame(['2026-10', '2026-10'], [$link['billing_period'], $form['billing_period']]);
    }

    /** @return array{StudentClass, StudentClass, Invoice} */
    private function sharedCountBill(): array
    {
        $terms = ['ScheduleMode' => 'count', 'SessionCount' => 8, 'RemainingSessions' => 8, 'Charge' => 2000, 'Rate' => 250];
        $header = $this->course('2026-10-01', '2026-11-30', [], $terms);
        $line = $this->course('2026-10-01', '2026-11-30', [], $terms + ['StudentID' => $header->StudentID, 'SubjectID' => 2]);
        $bill = Invoice::create([
            'StudentID' => $header->StudentID,
            'StudentClassID' => $header->ID,
            'IssueDate' => '2026-10-23',
            'TotalAmount' => 4000,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
        ]);
        $bill->items()->create(['StudentClassID' => $header->ID, 'Description' => 'a', 'Amount' => 2000]);
        $bill->items()->create(['StudentClassID' => $line->ID, 'Description' => 'b', 'Amount' => 2000]);

        return [$header, $line, $bill];
    }

    private function course(string $start, string $end, array $lessons, array $overrides = []): StudentClass
    {
        $student = Student::create(['name' => 's'.uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0912345678', 'MDT' => now(), 'Notify_Token' => '']);
        $sc = StudentClass::create($overrides + [
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => $start, 'EndDate' => $end, 'TotalHours' => 0, 'Charge' => 8250, 'Paid' => 0, 'Rate' => 1650,
            'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'SessionCount' => 0,
            'SessionDuration' => 120, 'RemainingSessions' => 0, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        foreach ($lessons as $date => $status) {
            ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status]);
        }

        return $sc;
    }

    private function api(string $method, string $uri, array $body = [])
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])->{$method}($uri, $body);
    }

    private function record(StudentClass $sc, int $amount)
    {
        return $this->api('postJson', '/api/v1/payment-reports/director-record', [
            'student_class_id' => $sc->ID, 'payment_date' => '2026-10-23', 'payment_method' => 'cash', 'amount' => $amount,
        ]);
    }

    private function confirm(int $reportId): void
    {
        $this->api('putJson', "/api/v1/payment-reports/{$reportId}/confirm")->assertOk();
    }

    private function bills(StudentClass $sc): array
    {
        return Invoice::query()->where('StudentClassID', $sc->ID)->orderBy('billing_period')->get()
            ->map(fn (Invoice $i) => [(string) $i->billing_period, (int) $i->TotalAmount])->all();
    }
}
