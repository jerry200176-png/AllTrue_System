<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * #3873 item 4: POST /invoices/{id}/payments follows the same R28 rule as payment-report confirm (#3864 hotfix P1-b):
 * money already paid = the invoice's Payment rows, never the stored PaidAmount; the amount is capped at what the bill
 * still owes by those rows; a bill the rows already cover is refused even when its stored Status is stale.
 */
class InvoiceRecordPaymentProjectionTest extends TestCase
{
    use BuildsMoneyFixtures;
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-10 10:00:00');
        $user = User::create(['LoginName' => 'record3873@example.com', 'Name' => 'd', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $this->headers = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Stored PaidAmount says 800, Payment rows say 200: the new total is 200 + 300, not 800 + 300. */
    public function test_paid_amount_and_status_come_from_payment_rows_not_the_stored_paid_amount(): void
    {
        $invoice = $this->countInvoice(1000, 'partial', 800, [[200, 'cash']]);

        $this->pay($invoice, 300)->assertOk();

        $invoice->refresh();
        $this->assertSame(500, (int) $invoice->PaidAmount);
        $this->assertSame('partial', (string) $invoice->Status);
        $this->assertSame(0, (int) StudentClass::findOrFail($invoice->StudentClassID)->Paid);
    }

    public function test_amount_above_what_the_payment_rows_leave_owing_is_refused(): void
    {
        $invoice = $this->countInvoice(1000, 'unpaid', 0, [[600, 'cash']]);

        $this->pay($invoice, 500)->assertStatus(422)
            ->assertJsonPath('code', 'amount_exceeds_outstanding')
            ->assertJsonPath('expected_amount', 400)
            ->assertJsonPath('invoice_id', (int) $invoice->id);

        $this->assertSame(1, Payment::where('InvoiceID', $invoice->id)->count());
        $this->assertSame('unpaid', (string) $invoice->fresh()->Status);
    }

    public function test_bill_already_covered_by_payment_rows_is_refused_even_with_a_stale_unpaid_status(): void
    {
        $invoice = $this->countInvoice(1000, 'unpaid', 0, [[1000, 'cash']]);

        $this->pay($invoice, 1)->assertStatus(422)->assertJsonPath('code', 'course_already_paid');

        $this->assertSame(1, Payment::where('InvoiceID', $invoice->id)->count());
    }

    public function test_paying_exactly_the_remaining_balance_settles_the_bill_and_the_course(): void
    {
        $invoice = $this->countInvoice(1000, 'unpaid', 0, [[600, 'cash']]);

        $this->pay($invoice, 400)->assertOk();

        $invoice->refresh();
        $this->assertSame(1000, (int) $invoice->PaidAmount);
        $this->assertSame('paid', (string) $invoice->Status);
        $this->assertSame(1, (int) StudentClass::findOrFail($invoice->StudentClassID)->Paid);
    }

    /** A reversed payment (Method=void) frees the balance again; stored PaidAmount does not double-count it. */
    public function test_reversed_payment_rows_are_netted_out(): void
    {
        $invoice = $this->countInvoice(1000, 'unpaid', 1000, [[1000, 'cash'], [1000, 'void']]);

        $this->pay($invoice, 1000)->assertOk();

        $invoice->refresh();
        $this->assertSame(1000, (int) $invoice->PaidAmount);
        $this->assertSame('paid', (string) $invoice->Status);
    }

    /**
     * Unpaid monthly bill priced from its held lessons (3 x 1,500 = 4,500, stored 6,000): the cap uses the held-lesson
     * amount, and that amount is written to the bill before the payment, as payment-report confirm already does.
     */
    public function test_unpaid_monthly_bill_is_capped_and_settled_at_its_held_lesson_amount(): void
    {
        $course = $this->course(
            [['2026-07', 6000, 'unpaid', 0, []]],
            ['ScheduleMode' => 'date', 'Rate' => 1500, 'Charge' => 6000],
            ['2026-07-06', '2026-07-13', '2026-07-20'],
        );
        $invoice = Invoice::where('StudentClassID', $course->ID)->firstOrFail();

        $this->pay($invoice, 6000)->assertStatus(422)
            ->assertJsonPath('code', 'amount_exceeds_outstanding')
            ->assertJsonPath('expected_amount', 4500);
        $this->assertSame(0, Payment::where('InvoiceID', $invoice->id)->count());
        $this->assertSame(6000, (int) $invoice->fresh()->TotalAmount, 'a refused payment changes nothing');

        $this->pay($invoice, 4500)->assertOk();

        $invoice->refresh();
        $this->assertSame(4500, (int) $invoice->TotalAmount);
        $this->assertSame(4500, (int) $invoice->PaidAmount);
        $this->assertSame('paid', (string) $invoice->Status);
    }

    /** The repriced amount is backed by the same billing snapshot confirm stores, and it survives later session edits. */
    public function test_repriced_monthly_bill_keeps_a_billing_snapshot_after_session_edits(): void
    {
        $course = $this->course(
            [['2026-07', 6000, 'unpaid', 0, []]],
            ['ScheduleMode' => 'date', 'Rate' => 1500, 'Charge' => 6000],
            ['2026-07-06', '2026-07-13', '2026-07-20'],
        );
        $invoice = Invoice::where('StudentClassID', $course->ID)->firstOrFail();

        $this->pay($invoice, 4500)->assertOk();
        ClassSession::where('StudentClassID', $course->ID)->where('SessionDate', '2026-07-20')->delete();

        $invoice->refresh();
        $snapshot = $invoice->billing_snapshot;
        $this->assertSame('2026-07', $snapshot['billing_period']);
        $this->assertSame(3, $snapshot['period_sessions']);
        $this->assertSame(4500, $snapshot['charge']);
        $this->assertCount(3, $snapshot['sessions']);
        $this->assertSame(4500, (int) $invoice->TotalAmount);
    }

    /**
     * A date-mode anchor course plus another course on one bill: the anchor's held lessons (4,500) do not price the
     * whole bill, so the stored 8,000 total stays the cap and is never rewritten.
     */
    public function test_multi_course_bill_keeps_its_stored_total(): void
    {
        $course = $this->course(
            [['2026-07', 8000, 'unpaid', 0, []]],
            ['ScheduleMode' => 'date', 'Rate' => 1500, 'Charge' => 6000],
            ['2026-07-06', '2026-07-13', '2026-07-20'],
        );
        $other = $this->course([], ['Charge' => 2000], [], Student::findOrFail($course->StudentID));
        $invoice = Invoice::where('StudentClassID', $course->ID)->firstOrFail();
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID, 'Description' => 'a', 'Amount' => 6000]);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $other->ID, 'Description' => 'b', 'Amount' => 2000]);

        $this->pay($invoice, 8001)->assertStatus(422)->assertJsonPath('expected_amount', 8000);
        $this->pay($invoice, 4500)->assertOk();

        $invoice->refresh();
        $this->assertSame(8000, (int) $invoice->TotalAmount);
        $this->assertSame(4500, (int) $invoice->PaidAmount);
        $this->assertSame('partial', (string) $invoice->Status);
        $this->assertNull($invoice->billing_snapshot);
    }

    /** Method=void is a reversal in the projection: this endpoint must not let it erase applied money. */
    public function test_void_method_is_refused(): void
    {
        $invoice = $this->countInvoice(1000, 'partial', 600, [[600, 'cash']]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", ['Amount' => 400, 'Method' => 'void'], $this->headers)
            ->assertStatus(422)->assertJsonValidationErrors('Method');

        $this->assertSame(1, Payment::where('InvoiceID', $invoice->id)->count());
        $this->assertSame(600, (int) $invoice->fresh()->PaidAmount);
    }

    private function countInvoice(int $total, string $status, int $storedPaid, array $payments): Invoice
    {
        $course = $this->course([['2026-08', $total, $status, $storedPaid, $payments]], ['Charge' => $total]);

        return Invoice::where('StudentClassID', $course->ID)->firstOrFail();
    }

    private function pay(Invoice $invoice, int $amount): TestResponse
    {
        return $this->postJson("/api/v1/invoices/{$invoice->id}/payments", ['Amount' => $amount, 'PaidAt' => '2026-09-10'], $this->headers);
    }
}
