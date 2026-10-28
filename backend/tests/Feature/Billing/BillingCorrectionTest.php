<?php

namespace Tests\Feature\Billing;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\BillingCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Direct tests of the billing-correction module interface (ARCH2-C3 slice 8a). */
class BillingCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function course(): StudentClass
    {
        $student = Student::create(['name' => '更正模組', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2032-03-01', 'TotalHours' => 20, 'Charge' => 4000, 'Paid' => 0, 'Rate' => 500, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
    }

    public function test_zero_count_correction_never_offers_or_applies_confirmation(): void
    {
        $svc = app(BillingCorrection::class);
        foreach ([0, 0.01] as $rate) {
            $c = $this->course();
            $c->update(['Rate' => $rate]);
            $invoice = Invoice::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'IssueDate' => '2032-03-01', 'TotalAmount' => 4000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '']);
            $item = InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $c->ID, 'Description' => 'Synthetic', 'Amount' => 4000]);
            $token = $svc->confirmationToken($c->fresh(), 6, 0, 0, [], 7);
            $payload = ['new_session_count' => 6, 'new_charge' => 0, 'reason' => 'Synthetic', 'confirmation_token' => $token];
            $before = [$c->fresh()->toArray(), $invoice->fresh()->toArray(), $item->fresh()->toArray()];
            $audit = [];
            foreach ([true, false] as $preview) {
                $result = $svc->correctCountMode($c->fresh(), $payload, $preview, 7, function ($code, $status) use (&$audit) { $audit[] = [$code, $status]; });
                $this->assertSame(422, $result['status']);
                $this->assertSame('billing_correction_zero_forbidden', $result['body']['code']);
                $this->assertArrayNotHasKey('confirmation_token', $result['body']);
                $this->assertSame($before, [$c->fresh()->toArray(), $invoice->fresh()->toArray(), $item->fresh()->toArray()]);
            }
            $this->assertSame([['billing_correction_zero_forbidden', 422], ['billing_correction_zero_forbidden', 422]], $audit);
        }
    }

    private function financialCounts(): array
    {
        return array_map(fn ($table) => DB::table($table)->count(), ['Payment', 'payment_reports', 'session_deduction_ledger', 'security_audit_events']);
    }

    public function test_direct_apply_rejects_zero_with_a_valid_token_without_writes(): void
    {
        $svc = app(BillingCorrection::class);
        $c = $this->course();
        $c->update(['Rate' => 0]);
        $invoice = Invoice::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'IssueDate' => '2032-03-01', 'TotalAmount' => 4000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '']);
        $item = InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $c->ID, 'Description' => 'Synthetic', 'Amount' => 4000]);
        $session = ClassSession::create(['StudentClassID' => $c->ID, 'SessionDate' => '2032-03-07', 'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'scheduled']);
        $before = [$c->fresh()->toArray(), $invoice->fresh()->toArray(), $item->fresh()->toArray(), $session->fresh()->toArray(), $this->financialCounts()];
        $token = $svc->confirmationToken($c->fresh(), 6, 0, 0, [], 7);
        try {
            $svc->applyCountCorrection($c->fresh(), 6, 0, 'Synthetic', 8, 4000, $token, 7);
            $this->fail('direct apply accepted a zero target');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $this->assertSame('billing_correction_zero_forbidden', json_decode($e->getResponse()->getContent(), true)['code']);
        }
        $this->assertSame($before, [$c->fresh()->toArray(), $invoice->fresh()->toArray(), $item->fresh()->toArray(), $session->fresh()->toArray(), $this->financialCounts()]);
    }

    public function test_live_free_gift_refuses_count_preview_and_direct_apply(): void
    {
        $svc = app(BillingCorrection::class);
        $c = $this->course();
        $invoice = Invoice::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'IssueDate' => '2032-03-01', 'TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '', 'zero_amount_reason' => Invoice::ZERO_REASON_FREE_GIFT]);
        $before = [$c->fresh()->toArray(), $invoice->fresh()->toArray()];
        $audit = [];
        $result = $svc->correctCountMode($c->fresh(), ['new_session_count' => 6, 'new_charge' => 3000, 'reason' => 'Synthetic'], true, 7, function ($code, $status) use (&$audit) { $audit[] = [$code, $status]; });
        $this->assertSame(409, $result['status']);
        $this->assertSame('billing_correction_free_gift_locked', $result['body']['code']);
        $this->assertSame([['billing_correction_free_gift_locked', 409]], $audit);
        $token = $svc->confirmationToken($c->fresh(), 6, 3000, 0, [], 7);
        try {
            $svc->applyCountCorrection($c->fresh(), 6, 3000, 'Synthetic', 8, 4000, $token, 7);
            $this->fail('direct apply accepted a live free/gift invoice');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame('billing_correction_free_gift_locked', json_decode($e->getResponse()->getContent(), true)['code']);
        }
        $this->assertSame($before, [$c->fresh()->toArray(), $invoice->fresh()->toArray()]);
    }

    public function test_voided_free_gift_does_not_block_a_valid_positive_correction(): void
    {
        $svc = app(BillingCorrection::class);
        $c = $this->course();
        Invoice::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'IssueDate' => '2032-03-01', 'TotalAmount' => 0, 'PaidAmount' => 0, 'Status' => 'void', 'Note' => '', 'zero_amount_reason' => Invoice::ZERO_REASON_FREE_GIFT]);
        $invoice = Invoice::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'IssueDate' => '2032-03-02', 'TotalAmount' => 4000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '']);
        $payload = ['new_session_count' => 6, 'new_charge' => 3000, 'reason' => 'Synthetic'];
        $preview = $svc->correctCountMode($c->fresh(), $payload, true, 7, fn () => null);
        $this->assertSame(200, $preview['status']);
        $done = $svc->correctCountMode($c->fresh(), $payload + ['confirmation_token' => $preview['body']['confirmation_token']], false, 7, fn () => null);
        $this->assertSame(200, $done['status']);
        $this->assertSame(3000, (int) $invoice->fresh()->TotalAmount);
    }

    public function test_marker_added_after_confirmation_is_rechecked_before_stamping(): void
    {
        $svc = app(BillingCorrection::class);
        $c = $this->course();
        $invoice = Invoice::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'IssueDate' => '2032-03-01', 'TotalAmount' => 4000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '']);
        $token = $svc->confirmationToken($c->fresh(), 6, 3000, 0, [], 7);
        $hookReached = false;
        $svc->setAfterConfirmationTokenForTests(function () use ($invoice, &$hookReached): void {
            $invoice->update(['zero_amount_reason' => Invoice::ZERO_REASON_FREE_GIFT]);
            $hookReached = true;
        });
        try {
            $svc->applyCountCorrection($c->fresh(), 6, 3000, 'Synthetic', 8, 4000, $token, 7);
            $this->fail('direct apply ignored a newly marked free/gift invoice');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame('billing_correction_free_gift_locked', json_decode($e->getResponse()->getContent(), true)['code']);
        } finally {
            $svc->setAfterConfirmationTokenForTests(null);
        }
        $this->assertTrue($hookReached);
        $this->assertSame(4000, (int) $invoice->fresh()->TotalAmount);
        $this->assertNull($invoice->fresh()->zero_amount_reason);
        $this->assertSame(8, (int) $c->fresh()->SessionCount);
    }

    public function test_token_is_bound_to_actor_and_target(): void
    {
        $svc = app(BillingCorrection::class);
        $c = $this->course();

        $a = $svc->confirmationToken($c, 6, 3000, 0, [], 1);

        $this->assertSame($a, $svc->confirmationToken($c, 6, 3000, 0, [], 1));
        $this->assertNotSame($a, $svc->confirmationToken($c, 6, 3000, 0, [], 2));
        $this->assertNotSame($a, $svc->confirmationToken($c, 5, 2500, 0, [], 1));
    }

    public function test_token_binds_payment_and_report_ledger_history(): void
    {
        $svc = app(BillingCorrection::class);
        $c = $this->course();
        $before = $svc->confirmationToken($c, 6, 3000, 0, [], 1);
        $invoice = Invoice::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'IssueDate' => '2032-03-01', 'TotalAmount' => 4000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '']);
        $report = PaymentReport::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'InvoiceID' => $invoice->id,
            'reported_by_name' => '更正模組', 'payment_date' => '2032-03-02', 'reported_amount' => 4000, 'status' => 'voided',
            'report_token_hash' => hash('sha256', 'ledger-bind-' . uniqid()), 'token_expires_at' => now()->addDay()]);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 4000, 'Method' => 'cash', 'PaidAt' => '2032-03-02', 'payment_report_id' => $report->id]);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => -4000, 'Method' => 'void', 'PaidAt' => '2032-03-03', 'payment_report_id' => $report->id]);
        $after = $svc->confirmationToken($c->fresh(), 6, 3000, 0, [], 1);
        $this->assertNotSame($before, $after);
        $this->assertSame($after, $svc->confirmationToken($c->fresh(), 6, 3000, 0, [], 1));
    }

    public function test_apply_updates_course_and_open_invoice_with_a_valid_token(): void
    {
        $svc = app(BillingCorrection::class);
        $c = $this->course();
        $invoice = Invoice::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'IssueDate' => '2032-03-01', 'TotalAmount' => 4000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '']);
        $token = $svc->confirmationToken($c, 6, 3000, 0, [], 7);

        $r = $svc->applyCountCorrection($c, 6, 3000, '少報堂數', 8, 4000, $token, 7);

        $this->assertSame(1, $r['adjusted_invoice_count']);
        $this->assertSame(6, (int) $c->fresh()->SessionCount);
        $this->assertSame(3000, (int) $c->fresh()->Charge);
        $this->assertSame(3000, (int) $invoice->fresh()->TotalAmount);
    }

    public function test_apply_with_stale_token_aborts_without_writing(): void
    {
        $c = $this->course();

        try {
            app(BillingCorrection::class)->applyCountCorrection($c, 6, 3000, 'x', 8, 4000, str_repeat('0', 64), 7);
            $this->fail('expected abort');
        } catch (HttpResponseException|HttpException $e) {
            $this->assertTrue(true);
        }

        $this->assertSame(8, (int) $c->fresh()->SessionCount);
    }

    public function test_correct_count_mode_guards_report_audit_code_and_status(): void
    {
        $svc = app(BillingCorrection::class);
        $seen = [];
        $audit = function (string $code, int $status) use (&$seen) { $seen[] = [$code, $status]; };
        $base = ['new_session_count' => 6, 'new_charge' => 3000, 'reason' => 'x'];

        $paid = $this->course();
        $paid->update(['Paid' => 1]);
        $this->assertSame(409, $svc->correctCountMode($paid, $base, false, 1, $audit)['status']);

        $this->assertSame(422, $svc->correctCountMode($this->course(), ['new_charge' => 3500] + $base, false, 1, $audit)['status']);
        $this->assertSame(422, $svc->correctCountMode($this->course(), ['new_session_count' => 9, 'new_charge' => 4500] + $base, false, 1, $audit)['status']);

        $this->assertSame([['billing_correction_paid_locked', 409], ['billing_correction_charge_mismatch', 422], ['billing_correction_reduction_only', 422]], $seen);
    }

    public function test_correct_count_mode_preview_then_confirm_applies(): void
    {
        $svc = app(BillingCorrection::class);
        $c = $this->course();
        $p = ['new_session_count' => 6, 'new_charge' => 3000, 'reason' => '少報'];

        $preview = $svc->correctCountMode($c, $p, true, 7, fn () => null);
        $this->assertSame(200, $preview['status']);
        $this->assertTrue($preview['body']['requires_confirmation']);

        $bad = $svc->correctCountMode($c, $p + ['confirmation_token' => str_repeat('a', 64)], false, 7, fn () => null);
        $this->assertSame(409, $bad['status']);

        $done = $svc->correctCountMode($c, $p + ['confirmation_token' => $preview['body']['confirmation_token']], false, 7, fn () => null);
        $this->assertSame(200, $done['status']);
        $this->assertSame(6, (int) $c->fresh()->SessionCount);
    }

    /**
     * Targets the pre-stamp current-read: first open-invoice guard sees one bill; a test seam inserts a
     * second open bill only after the confirmation token check; apply must refuse before stamp.
     * Same-connection seam: the phantom rides A's txn and rolls back with the refuse — expect 1 open
     * invoice afterward. Multi-connection persistence (count=2 when B commits) is claim9's job.
     */
    public function test_pre_stamp_current_read_refuses_invoice_inserted_after_first_guard(): void
    {
        $svc = app(BillingCorrection::class);
        $c = $this->course();
        $first = Invoice::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'IssueDate' => '2032-03-01',
            'TotalAmount' => 4000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '']);
        $token = $svc->confirmationToken($c->fresh(), 6, 3000, 0, [], 7);
        $insertedAfterFirstGuard = false;
        $svc->setAfterConfirmationTokenForTests(function (int $classId) use ($c, &$insertedAfterFirstGuard): void {
            Invoice::create(['StudentID' => $c->StudentID, 'StudentClassID' => $classId, 'IssueDate' => '2032-03-02',
                'TotalAmount' => 4000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => 'post-guard phantom']);
            // Still inside apply's txn — prove the post-guard insert was visible before refuse.
            $this->assertSame(2, Invoice::query()->where('StudentClassID', $classId)->where('Status', '!=', 'void')->count());
            $insertedAfterFirstGuard = true;
        });
        try {
            $svc->applyCountCorrection($c->fresh(), 6, 3000, 'pre-stamp seam', 8, 4000, $token, 7);
            $this->fail('expected pre-stamp multi-open refuse');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame('billing_correction_multiple_open_invoices', json_decode($e->getResponse()->getContent(), true)['code']);
        } finally {
            $svc->setAfterConfirmationTokenForTests(null);
        }
        $this->assertTrue($insertedAfterFirstGuard, 'seam must insert after the first open-invoice guard');
        // Refuse aborts the apply txn; same-conn phantom is rolled back with it.
        $this->assertSame(1, Invoice::query()->where('StudentClassID', $c->ID)->where('Status', '!=', 'void')->count());
        $this->assertSame([4000, 8, 4000], [(int) $first->fresh()->TotalAmount, (int) $c->fresh()->SessionCount, (int) $c->fresh()->Charge]);
    }

    /** Real writer: payments:backfill-legacy must not create an invoice on an unpaid count-mode correction course. */
    public function test_legacy_backfill_command_skips_unpaid_count_correction_course(): void
    {
        $unpaid = $this->course();
        $this->assertSame(0, (int) $unpaid->Paid);
        $this->artisan('payments:backfill-legacy')->assertExitCode(0);
        $this->assertSame(0, Invoice::query()->where('StudentClassID', $unpaid->ID)->count());
    }
}
