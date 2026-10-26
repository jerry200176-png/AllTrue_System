<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F7 S3a: 待對帳 is "stopped and the resolver says it still owes", not a closed_reason allowlist.
 * #4104: response labels match courseMoneyState.js without changing the resolver verdict.
 */
class AccountingPendingQueueTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create(['LoginName' => 'pq-' . uniqid() . '@example.com', 'Name' => '帳務', 'PSW' => 'secret', 'type' => 'D', 'phone' => '0900000000']);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addHour()]);
        $this->headers = ['Authorization' => "Bearer {$token}"];
    }

    private function course(array $o = []): StudentClass
    {
        $student = Student::create(['name' => '待對帳測試生', 'CampusID' => 1, 'ClassID' => 1, 'SchoolName' => 'T', 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-04-01', 'TotalHours' => 20, 'Charge' => 8800, 'Paid' => 0, 'Rate' => 0, 'MDate' => now(),
            'Stop' => 1, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 60, 'RemainingSessions' => 4,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $o));
    }

    private function invoice(StudentClass $c, int $total, string $status = 'unpaid', int $paid = 0): Invoice
    {
        $invoice = Invoice::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'IssueDate' => '2026-09-01',
            'DueDate' => '2026-09-10', 'TotalAmount' => $total, 'PaidAmount' => $paid, 'Status' => $status]);
        if ($paid > 0) {
            Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $paid, 'PaidAt' => '2026-09-05', 'Method' => 'cash']);
        }

        return $invoice;
    }

    private function row(StudentClass $c): ?array
    {
        return collect($this->getJson("/api/v1/accounting/settled-courses?branch_id=1&course_id={$c->ID}", $this->headers)
            ->assertOk()->json('data'))->first();
    }

    private function tuitionRow(StudentClass $c): ?array
    {
        return collect($this->getJson('/api/v1/alerts/tuition?branch_id=1', $this->headers)->assertOk()->json())
            ->first(fn ($r) => (int) ($r['id'] ?? 0) === (int) $c->ID);
    }

    public function test_paused_contract_with_outstanding_is_listed_as_paused_pending(): void
    {
        $c = $this->course(['closed_reason' => null]);
        $this->invoice($c, 8800, 'unpaid', 3000);

        $row = $this->row($c);
        $this->assertTrue($row['pending_reconciliation']);
        $this->assertSame(5800, $row['outstanding_amount']);
        $this->assertSame('暫停中，尚未收款', $row['reconciliation_label']);

        $alert = $this->tuitionRow($c);
        $this->assertSame('pending_reconciliation', $alert['payment_status']);
        $this->assertSame(5800, $alert['outstanding']);
    }

    public function test_settled_reason_with_unpaid_invoice_is_pending(): void
    {
        $c = $this->course(['closed_reason' => 'settled']);
        $this->invoice($c, 8800);

        $row = $this->row($c);
        $this->assertTrue($row['pending_reconciliation']);
        $this->assertSame(8800, $row['outstanding_amount']);
        $this->assertSame('課已結束，尚未收款', $row['reconciliation_label']);
        $this->assertNotNull($this->tuitionRow($c));
    }

    public function test_fully_paid_stopped_contract_is_not_pending(): void
    {
        $c = $this->course(['closed_reason' => 'settled', 'Paid' => 1]);
        $this->invoice($c, 8800, 'paid', 8800);

        $row = $this->row($c);
        $this->assertFalse($row['pending_reconciliation']);
        $this->assertSame(0, $row['outstanding_amount']);
        $this->assertNull($this->tuitionRow($c));
    }

    public function test_waived_is_history_with_zero_outstanding(): void
    {
        $c = $this->course(['closed_reason' => null]);
        $this->invoice($c, 8800);
        $c->forceFill(['closed_reason' => 'waived'])->saveQuietly(); // model guard blocks invoices on waived courses

        $row = $this->row($c);
        $this->assertFalse($row['pending_reconciliation']);
        $this->assertFalse($row['payment_review_required']);
        $this->assertSame(0, $row['outstanding_amount']);
        $this->assertSame('歷史 · 確認不收', $row['reconciliation_label']);
        $this->assertNull($this->tuitionRow($c));
    }

    public function test_review_required_is_its_own_visible_state(): void
    {
        $c = $this->course(['closed_reason' => null]);
        $this->invoice($c, 0, 'unpaid'); // positive charge, only a zero-value invoice => resolver review_required

        $row = $this->row($c);
        $this->assertNotNull($row);
        $this->assertTrue($row['payment_review_required']);
        $this->assertFalse($row['pending_reconciliation']);
        $this->assertSame('月結帳單與上課堂數不符，請核對', $row['reconciliation_label']);
    }

    public function test_4104_waiver_rejection_uses_the_same_ended_pending_label(): void
    {
        $c = $this->course(['Stop' => 0, 'closed_reason' => null]);
        $invoice = $this->invoice($c, 8800);

        $this->postJson("/api/v1/accounting/courses/{$c->ID}/waive", ['reason' => '測試不收原因'], $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('message', '只有「課已結束，尚未收款」且還沒繳清的合約，才能改成「不收了」');

        $this->assertNull($c->fresh()->closed_reason);
        $this->assertSame('unpaid', $invoice->fresh()->Status);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_review_required_stopped_monthly_with_open_invoice_stays_in_tuition_queue(): void
    {
        // In-app #394: a monthly contract starting 8/30 billed by a calendar-month 2026-09 invoice leaves 8/30–8/31
        // uncovered, so the period engine says review_required. The issued NT$4,400 is still open and the student
        // drawer shows it as owed, so the queue (and the student list built from it) must too, not "已收".
        $c = $this->course([
            'closed_reason' => 'settled', 'ScheduleMode' => 'date', 'settlement_day' => 30,
            'StartDate' => '2026-08-30', 'EndDate' => '2026-09-30', 'Charge' => 4400, 'Rate' => 1100,
            'SessionCount' => 4, 'RemainingSessions' => 0,
        ]);
        $invoice = $this->invoice($c, 4400);
        $invoice->forceFill(['billing_period' => '2026-09'])->save();

        $verdict = app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([(int) $c->ID])[(int) $c->ID];
        $this->assertSame('review_required', $verdict['status'], 'fixture must reproduce the review verdict');

        $alert = $this->tuitionRow($c);
        $this->assertNotNull($alert, 'an issued, open invoice must not vanish from 帳務中心');
        // Founder D2: findable, but marked 金額待確認 and never the confirmed-unpaid status.
        $this->assertSame('amount_review', $alert['payment_status']);
        $this->assertTrue($alert['amount_pending_confirmation']);
        $this->assertSame(4400, $alert['pending_confirmation_amount']);
        $this->assertSame(4400, $alert['outstanding']);
    }

    public function test_review_required_pending_amount_uses_the_settled_page_basis(): void
    {
        // Founder D2: 帳務中心 and 已結清 → 待核對 show one amount for the same contract, both from the
        // resolver's course-level `outstanding`. A partial payment makes the basis differ from the bill total.
        $c = $this->reviewMonthlyCourse();
        $invoice = $this->invoice($c, 4400, 'partial', 1000);
        $invoice->forceFill(['billing_period' => '2026-09'])->save();

        $verdict = app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([(int) $c->ID])[(int) $c->ID];
        $this->assertSame('review_required', $verdict['status'], 'fixture must reproduce the review verdict');

        $settled = $this->row($c);
        $this->assertTrue($settled['payment_review_required']);
        // #4124: issued open bill under review also counts as pending (same bucket as tuition's findable row).
        $this->assertTrue($settled['pending_reconciliation']);
        $alert = $this->tuitionRow($c);
        $this->assertSame('amount_review', $alert['payment_status']);
        $this->assertSame((int) $verdict['outstanding'], $alert['pending_confirmation_amount']);
        $this->assertSame($settled['outstanding_amount'], $alert['pending_confirmation_amount']);
        $this->assertSame(3400, $alert['pending_confirmation_amount']);
    }

    /** #4124: review_required + issued open bill must share pending+amount on tuition and settled-courses. */
    public function test_4124_review_with_open_invoice_aligns_tuition_and_settled_pending(): void
    {
        $c = $this->reviewMonthlyCourse();
        $invoice = $this->invoice($c, 4400);
        $invoice->forceFill(['billing_period' => '2026-09'])->save();

        $verdict = app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([(int) $c->ID])[(int) $c->ID];
        $this->assertSame('review_required', $verdict['status']);
        $this->assertSame('invoice', $verdict['source']);
        $this->assertGreaterThan(0, (int) $verdict['outstanding']);

        $alert = $this->tuitionRow($c);
        $this->assertNotNull($alert);
        $this->assertTrue($alert['amount_pending_confirmation']);
        $settled = $this->row($c);
        $this->assertNotNull($settled);
        $this->assertTrue($settled['pending_reconciliation'], 'settled page must count the open bill as pending (#4124)');
        $this->assertTrue($settled['payment_review_required'], 'keep the review hint');
        $this->assertSame((int) $alert['pending_confirmation_amount'], (int) $settled['outstanding_amount']);
        $this->assertSame((int) $verdict['outstanding'], (int) $settled['outstanding_amount']);

        $summary = $this->getJson('/api/v1/accounting/settled-courses?branch_id=1', $this->headers)->assertOk()->json('summary');
        $this->assertGreaterThanOrEqual(1, (int) $summary['pending_reconciliation_count']);
        $this->assertGreaterThanOrEqual((int) $settled['outstanding_amount'], (int) $summary['pending_reconciliation_total']);
    }

    public function test_review_required_with_pending_parent_report_stays_confirmable_and_marked_pending(): void
    {
        // A parent already reported paying this 金額待確認 bill: the row must stay confirmable (pending_report →
        // 確認入帳 / 待核對入帳) while still carrying the pending-confirmation marker and the settled-page amount.
        $c = $this->reviewMonthlyCourse();
        $invoice = $this->invoice($c, 4400);
        $invoice->forceFill(['billing_period' => '2026-09'])->save();
        $report = \App\Models\PaymentReport::create([
            'StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'reported_by_name' => '家長',
            'payment_date' => now()->toDateString(), 'payment_method' => 'transfer', 'reported_amount' => 4400,
            'status' => 'pending', 'report_token_hash' => hash('sha256', 'd2-' . uniqid()), 'token_expires_at' => now()->addDay(),
        ]);

        $verdict = app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([(int) $c->ID])[(int) $c->ID];
        $this->assertSame('review_required', $verdict['status'], 'fixture must reproduce the review verdict');

        $alert = $this->tuitionRow($c);
        $this->assertNotNull($alert);
        $this->assertSame('pending_report', $alert['payment_status']);
        $this->assertSame((int) $report->id, $alert['latest_payment_report_id']);
        $this->assertTrue($alert['amount_pending_confirmation']);
        $this->assertSame((int) $verdict['outstanding'], $alert['pending_confirmation_amount']);
        $this->assertSame($this->row($c)['outstanding_amount'], $alert['pending_confirmation_amount']);
    }

    public function test_tuition_slip_is_refused_while_the_amount_is_pending_confirmation(): void
    {
        $c = $this->reviewMonthlyCourse();
        $invoice = $this->invoice($c, 4400);
        $invoice->forceFill(['billing_period' => '2026-09'])->save();
        $this->assertTrue($this->tuitionRow($c)['amount_pending_confirmation']);

        $this->getJson("/api/v1/alerts/tuition-slip/{$c->ID}", $this->headers)
            ->assertStatus(422)
            ->assertJsonPath('message', '金額待確認，請先確認應收金額');
    }

    public function test_invoice_slip_data_keeps_class_dates_but_withholds_a_pending_confirmation_amount(): void
    {
        // The 繳費明細 ledger reads invoices/{id}/slip-data for 涵蓋上課日: it must still load, without amounts.
        $c = $this->reviewMonthlyCourse();
        $invoice = $this->invoice($c, 4400);
        $invoice->forceFill(['billing_period' => '2026-09'])->save();
        $this->assertTrue($this->tuitionRow($c)['amount_pending_confirmation'], 'fixture: the course must be 金額待確認');

        $json = $this->getJson("/api/v1/invoices/{$invoice->id}/slip-data", $this->headers)->assertOk()->json();
        $this->assertTrue($json['amount_pending_confirmation']);
        foreach (['total_amount', 'stored_total_amount', 'computed_total_amount', 'paid_amount', 'remaining'] as $field) {
            $this->assertNull($json[$field], $field);
        }
        foreach ($json['items'] as $item) {
            $this->assertNull($item['amount']);
        }
        $this->assertArrayHasKey('sessions', $json); // 涵蓋上課日 still comes from this payload
        $this->assertIsArray($json['sessions']);
    }

    public function test_paid_invoice_on_a_pending_confirmation_course_keeps_its_slip_amounts(): void
    {
        $c = $this->reviewMonthlyCourse();
        $open = $this->invoice($c, 4400);
        $open->forceFill(['billing_period' => '2026-09'])->save();
        $paid = $this->invoice($c, 1200, 'paid', 1200);
        $paid->forceFill(['billing_period' => '2026-09'])->save();
        $this->assertTrue($this->tuitionRow($c)['amount_pending_confirmation'], 'fixture: the course must still be 金額待確認');

        $json = $this->getJson("/api/v1/invoices/{$paid->id}/slip-data", $this->headers)->assertOk()->json();
        $this->assertFalse($json['amount_pending_confirmation']);
        $this->assertSame(1200, $json['total_amount']);
        $this->assertSame(0, $json['remaining']);
    }

    public function test_ledger_course_carries_the_same_pending_confirmation_verdict_as_the_tuition_list(): void
    {
        // In-app #425: the ledger card's 繳費單 stays disabled exactly when the tuition list says 金額待確認.
        $pending = $this->reviewMonthlyCourse();
        $this->invoice($pending, 4400)->forceFill(['billing_period' => '2026-09'])->save();
        $this->assertTrue($this->tuitionRow($pending)['amount_pending_confirmation'], 'fixture: the course must be 金額待確認');
        $course = collect($this->getJson("/api/v1/accounting/ledger?student_class_id={$pending->ID}", $this->headers)
            ->assertOk()->json('courses'))->firstWhere('id', (int) $pending->ID);
        $this->assertTrue($course['amount_pending_confirmation']);

        $settled = $this->course(['closed_reason' => 'settled']);
        $this->invoice($settled, 8800);
        $course = collect($this->getJson("/api/v1/accounting/ledger?student_class_id={$settled->ID}", $this->headers)
            ->assertOk()->json('courses'))->firstWhere('id', (int) $settled->ID);
        $this->assertFalse($course['amount_pending_confirmation']);
    }

    public function test_tuition_slip_still_works_for_a_confirmed_unpaid_stopped_contract(): void
    {
        $c = $this->course(['closed_reason' => 'settled']);
        $invoice = $this->invoice($c, 8800);

        $this->getJson("/api/v1/alerts/tuition-slip/{$c->ID}", $this->headers)->assertOk();
        $this->getJson("/api/v1/invoices/{$invoice->id}/slip-data", $this->headers)->assertOk()
            ->assertJsonPath('amount_pending_confirmation', false)
            ->assertJsonPath('total_amount', 8800);
    }

    public function test_confirmed_unpaid_rows_are_not_marked_pending_confirmation(): void
    {
        $c = $this->course(['closed_reason' => 'settled']);
        $this->invoice($c, 8800);

        $alert = $this->tuitionRow($c);
        $this->assertSame('pending_reconciliation', $alert['payment_status']);
        $this->assertFalse($alert['amount_pending_confirmation']);
        $this->assertSame(0, $alert['pending_confirmation_amount']);
    }

    private function reviewMonthlyCourse(): StudentClass
    {
        return $this->course([
            'closed_reason' => 'settled', 'ScheduleMode' => 'date', 'settlement_day' => 30,
            'StartDate' => '2026-08-30', 'EndDate' => '2026-09-30', 'Charge' => 4400, 'Rate' => 1100,
            'SessionCount' => 4, 'RemainingSessions' => 0,
        ]);
    }

    public function test_review_required_stopped_with_fully_paid_bill_stays_out_of_tuition_queue(): void
    {
        $c = $this->reviewMonthlyCourse();
        $invoice = $this->invoice($c, 4400, 'paid', 4400);
        $invoice->forceFill(['billing_period' => '2026-09'])->save();

        $verdict = app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([(int) $c->ID])[(int) $c->ID];
        $this->assertSame('review_required', $verdict['status'], 'fixture must reproduce the review verdict');
        $this->assertSame(0, (int) $verdict['outstanding']);
        $this->assertNull($this->tuitionRow($c));
    }

    public function test_review_required_stopped_with_only_voided_bill_stays_out_of_tuition_queue(): void
    {
        $c = $this->reviewMonthlyCourse();
        $invoice = $this->invoice($c, 4400);
        $invoice->forceFill(['billing_period' => '2026-09', 'Status' => 'void'])->save();

        $verdict = app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([(int) $c->ID])[(int) $c->ID];
        $this->assertSame('review_required', $verdict['status'], 'fixture must reproduce the review verdict');
        $this->assertNotSame('invoice', $verdict['source']);
        $this->assertNull($this->tuitionRow($c));
    }

    public function test_review_required_stopped_without_invoice_stays_out_of_tuition_queue(): void
    {
        // Review with no issued bill is an estimate only: it stays in 已結清 → 待核對, never a queue amount.
        $c = $this->course([
            'closed_reason' => 'settled', 'ScheduleMode' => 'date', 'settlement_day' => 30,
            'StartDate' => '2026-08-30', 'EndDate' => '2026-09-30', 'Charge' => 4400, 'Rate' => 1100,
            'SessionCount' => 4, 'RemainingSessions' => 0,
        ]);
        \App\Models\ClassSession::create(['StudentClassID' => $c->ID, 'SessionDate' => '2026-08-30', 'StartTime' => '10:00',
            'EndTime' => '11:00', 'Status' => 'attended']);
        \App\Models\ClassSession::create(['StudentClassID' => $c->ID, 'SessionDate' => '2026-09-05', 'StartTime' => '10:00',
            'EndTime' => '11:00', 'Status' => 'attended']);

        $verdict = app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([(int) $c->ID])[(int) $c->ID];
        $this->assertSame('review_required', $verdict['status']);
        $this->assertNull($this->tuitionRow($c));
    }

    public function test_unbilled_stopped_alert_carries_resolver_amounts_not_null(): void
    {
        $c = $this->course(['closed_reason' => null]); // no invoice, Charge 8800 => resolver unbilled

        $alert = $this->tuitionRow($c);
        $this->assertSame(8800, $alert['payable_amount']);
        $this->assertSame(8800, $alert['payable_outstanding']);
        $this->assertSame(8800, $alert['outstanding']);
        $this->assertNull($alert['closed_reason']);
    }

    public function test_zero_charge_contract_with_positive_unpaid_invoice_is_kept(): void
    {
        $c = $this->course(['closed_reason' => 'settled', 'Charge' => 0]);
        $this->invoice($c, 5000);

        $alert = $this->tuitionRow($c);
        $this->assertNotNull($alert);
        $this->assertSame(5000, $alert['charge']);
        $this->assertSame(5000, $alert['payable_outstanding']);
    }

    public function test_multi_invoice_amount_fields_are_cumulative(): void
    {
        $c = $this->course(['closed_reason' => 'settled']);
        $first = $this->invoice($c, 3000);
        $second = $this->invoice($c, 4000);

        $alert = $this->tuitionRow($c);
        foreach (['payable_amount', 'payable_outstanding', 'outstanding', 'charge'] as $field) {
            $this->assertSame(7000, $alert[$field], $field);
        }
        $this->assertContains((int) $alert['payable_invoice_id'], [$first->id, $second->id]);
    }

    public function test_settled_pending_covered_by_payments_stays_in_history(): void
    {
        $c = $this->course(['closed_reason' => 'settled_pending']);
        $this->invoice($c, 8800, 'unpaid', 8800); // payment rows cover it, invoice status still unpaid

        $row = $this->row($c);
        $this->assertNotNull($row);
        $this->assertFalse($row['pending_reconciliation']);
        $this->assertSame(0, $row['outstanding_amount']);
        $this->assertNull($this->tuitionRow($c));
    }
}
