<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentReport;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\BillingPayableResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingRefundTest extends TestCase
{
    use RefreshDatabase;

    private function token(int $campusId = 1): string
    {
        $user = User::create(['LoginName' => 'refund-' . uniqid() . '@example.com', 'Name' => 'Director fixture', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0900000000', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    /** 8 lessons x 500 = 4000 paid, 2 deducted (the absence is not): refundable 3000. */
    private function course(string $mode = 'count', float $rate = 500, int $charge = 4000): StudentClass
    {
        $student = Student::create(['name' => 'Fixture', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create(['StudentID' => $student->id, 'TeacherID' => 1, 'GradeID' => 1, 'SubjectID' => 1, 'by1' => 2, 'Period' => 4,
            'StartDate' => '2026-10-01', 'EndDate' => '2026-12-31', 'TotalHours' => 16, 'Charge' => $charge, 'Rate' => $rate, 'SessionCount' => 8,
            'RemainingSessions' => 5, 'Paid' => 1, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => $mode]);
        foreach (['2026-10-05' => 'attended', '2026-10-12' => 'late', '2026-10-19' => 'absent', '2026-10-26' => 'cancelled'] as $d => $st) {
            ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $d, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $st]);
        }
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => now()->addDays(3)->toDateString(), 'StartTime' => '23:00', 'EndTime' => '23:59', 'Status' => 'scheduled']);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-10-01',
            'TotalAmount' => $charge, 'PaidAmount' => $charge, 'Status' => 'paid']);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $course->ID, 'Description' => 'tuition', 'Amount' => $charge]);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $charge, 'PaidAt' => '2026-10-01', 'Method' => 'cash']);

        return $course;
    }

    private function body(int $amount = 3000): array
    {
        return ['amount' => $amount, 'method' => 'cash', 'date' => now()->toDateString(), 'reason' => 'moved away'];
    }

    private function send(string $token, StudentClass $c, array $body)
    {
        return $this->withToken($token)->postJson("/api/v1/student-classes/{$c->ID}/refund", $body);
    }

    private function state(StudentClass $c): array
    {
        return app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$c->ID], [$c->fresh()])[$c->ID];
    }

    public function test_preview_is_computed_by_the_server(): void
    {
        $c = $this->course();
        $this->withToken($this->token())->getJson("/api/v1/student-classes/{$c->ID}/refund/preview")->assertOk()
            ->assertExactJson(['paid' => 4000, 'used_lessons' => 2, 'rate' => 500, 'refundable' => 3000]);
    }

    public function test_refund_balances_the_books_and_ends_the_contract(): void
    {
        $c = $this->course();
        $this->assertSame('paid', $this->state($c)['status']);
        $this->send($this->token(), $c, $this->body(2000))->assertOk();

        $state = $this->state($c);
        $this->assertSame('paid', $state['status']); // not owing: total was cut together with the money
        $this->assertSame(0, $state['outstanding']);
        $this->assertSame(2000, $state['applied']);
        $this->assertSame(-2000, (int) Payment::query()->where('Amount', '<', 0)->sum('Amount'));
        $this->assertSame(2000, (int) Invoice::query()->value('TotalAmount'));
        $this->assertSame('refund', Payment::where('Amount', '<', 0)->value('Method'));
        $this->assertSame(2000, (int) InvoiceItem::query()->sum('Amount')); // lines add up to the new total
        $invoiceId = Invoice::query()->value('id');
        $slip = $this->withToken($this->token())->getJson("/api/v1/invoices/{$invoiceId}/slip-data")->assertOk()->json('items');
        $this->assertContains(-2000, array_column($slip, 'amount'));
        $this->assertSame([2, 1000], [(int) $c->fresh()->SessionCount, (int) $c->fresh()->Charge]);
        $audit0 = json_decode((string) DB::table('security_audit_events')->where('event_type', 'student_class.refund')->value('metadata'), true);
        $this->assertSame([8, 2, 4000, 1000], [$audit0['old_session_count'], $audit0['new_session_count'], $audit0['old_charge'], $audit0['new_charge']]);
        $c = $c->fresh();
        $this->assertSame([1, 'refunded', 0], [(int) $c->Stop, $c->closed_reason, (int) $c->RemainingSessions]);
        $this->assertSame(now()->toDateString(), substr((string) $c->EndDate, 0, 10));
        $this->assertSame(0, ClassSession::where('StudentClassID', $c->ID)->where('Status', 'scheduled')->count());
        $audit = DB::table('security_audit_events')->where('event_type', 'student_class.refund')->first();
        $this->assertNotNull($audit);
        $this->assertSame(64, strlen((string) (json_decode((string) $audit->metadata, true)['reason_hash'] ?? '')));
        $rows = $this->withToken($this->token())->getJson('/api/v1/accounting/settled-courses?branch_id=1')->assertOk()->json('data');
        $row = collect($rows)->firstWhere('student_class_id', $c->ID);
        $this->assertNotNull($row);
        $this->assertSame('歷史 · 已退費', $row['reconciliation_label']);
    }

    public function test_over_refund_is_refused(): void
    {
        $c = $this->course();
        $this->send($this->token(), $c, $this->body(3001))->assertStatus(422);
        $this->assertSame(0, Payment::where('Amount', '<', 0)->count());
        $this->assertSame(0, (int) $c->fresh()->Stop);
    }

    public function test_double_refund_is_refused(): void
    {
        $c = $this->course();
        $token = $this->token();
        $this->send($token, $c, $this->body())->assertOk();
        $this->send($token, $c, $this->body())->assertStatus(409);
        $this->assertSame(1, Payment::where('Amount', '<', 0)->count());
    }

    public function test_other_campus_director_is_refused(): void
    {
        $c = $this->course();
        $this->send($this->token(2), $c, $this->body())->assertForbidden();
        $this->assertSame(0, Payment::where('Amount', '<', 0)->count());
    }

    public function test_monthly_contract_is_refused(): void
    {
        $c = $this->course('date');
        $this->send($this->token(), $c, $this->body())->assertStatus(422);
    }

    public function test_refund_rolls_back_when_the_audit_row_cannot_be_written(): void
    {
        $c = $this->course();
        $token = $this->token();
        $fail = true;
        DB::listen(function ($query) use (&$fail) {
            if ($fail && str_starts_with($query->sql, 'insert into `security_audit_events`')) {
                $fail = false;
                throw new \RuntimeException('synthetic audit failure');
            }
        });
        $this->send($token, $c, $this->body())->assertStatus(500);
        $this->assertSame(0, Payment::where('Amount', '<', 0)->count());
        $this->assertSame(4000, (int) Invoice::query()->value('TotalAmount'));
        $this->assertSame(0, (int) $c->fresh()->Stop);
    }

    public function test_absence_without_deduction_is_not_used(): void
    {
        $c = $this->course();
        // fixture: attended + late + absent(not deducted) => 2 used, refundable 3000
        $this->withToken($this->token())->getJson("/api/v1/student-classes/{$c->ID}/refund/preview")->assertOk()
            ->assertJson(['used_lessons' => 2, 'refundable' => 3000]);
    }

    public function test_hourly_priced_contract_is_refused(): void
    {
        $c = $this->course();
        $c->update(['rate_unit' => 'hour']);
        $this->send($this->token(), $c, $this->body(100))->assertStatus(422);
    }

    public function test_contract_with_money_still_owed_is_refused(): void
    {
        $c = $this->course();
        Invoice::query()->update(['TotalAmount' => 5000]);
        $this->send($this->token(), $c, $this->body(100))->assertStatus(422);
        $this->assertSame(0, Payment::where('Amount', '<', 0)->count());
    }

    public function test_invoice_shared_with_another_contract_is_refused(): void
    {
        $c = $this->course();
        $other = $this->course();
        InvoiceItem::create(['InvoiceID' => Invoice::query()->where('StudentClassID', $c->ID)->value('id'), 'StudentClassID' => $other->ID, 'Description' => 'x', 'Amount' => 100]);
        $this->send($this->token(), $c, $this->body(100))->assertStatus(422);
    }

    public function test_receipt_void_after_refund_is_refused_and_history_reason_is_set(): void
    {
        $c = $this->course();
        $token = $this->token();
        $this->send($token, $c, $this->body(1000))->assertOk();
        $report = PaymentReport::create(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'InvoiceID' => Invoice::query()->value('id'),
            'reported_by_name' => 'x', 'report_token_hash' => str_repeat('a', 64), 'token_expires_at' => now()->addDay(), 'reported_amount' => 4000, 'status' => 'confirmed', 'payment_date' => '2026-10-01']);
        $this->withToken($token)->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => 'x'])->assertStatus(422)->assertJson(['code' => 'void_course_refunded']);
        $list = $this->withToken($token)->getJson('/api/v1/student-classes?branch_id=1&student_id=' . $c->StudentID)->assertOk()->json();
        $row = collect($list['data'] ?? $list)->firstWhere('ID', $c->ID);
        $this->assertSame('refunded', $row['history_reason'] ?? null);
    }

    public function test_full_refund_of_an_unused_contract_is_settled_history(): void
    {
        $c = $this->course();
        ClassSession::where('StudentClassID', $c->ID)->delete();
        $token = $this->token();
        $this->send($token, $c, $this->body(4000))->assertOk();
        $this->assertNotSame('review_required', $this->state($c)['status']);
        $rows = $this->withToken($token)->getJson('/api/v1/accounting/settled-courses?branch_id=1')->assertOk()->json('data');
        $this->assertSame('歷史 · 已退費', collect($rows)->firstWhere('student_class_id', $c->ID)['reconciliation_label'] ?? null);
    }

    public function test_partial_refund_leaves_no_remaining_lessons_or_balance_review(): void
    {
        $c = $this->course();
        $token = $this->token();
        $this->send($token, $c, $this->body(3000))->assertOk();
        $list = $this->withToken($token)->getJson('/api/v1/student-classes?branch_id=1&student_id=' . $c->StudentID)->assertOk()->json();
        $row = collect($list['data'] ?? $list)->firstWhere('ID', $c->ID);
        $this->assertSame(0, (int) $row['remaining_sessions']);
        $this->assertNotSame('review_required', $row['usage_balance_status'] ?? null);
    }

    public function test_ledger_keeps_original_receipts_applied_after_a_refund(): void
    {
        $c = $this->course(rate: 500, charge: 4000);
        $inv = Invoice::query()->first();
        Payment::query()->update(['Amount' => 2000]);
        Payment::create(['InvoiceID' => $inv->id, 'Amount' => 2000, 'PaidAt' => '2026-10-02', 'Method' => 'cash']);
        $token = $this->token();
        $this->send($token, $c, $this->body(2500))->assertOk();
        $res = $this->withToken($token)->getJson("/api/v1/accounting/ledger?branch_id=1&student_class_id={$c->ID}")->assertOk();
        $this->assertSame([], collect($res->json('anomalies'))->where('code', 'overpayment_pending_review')->all());
        $statuses = collect($res->json('invoices.0.payments'))->pluck('application_status')->all();
        $this->assertSame(['applied', 'applied', 'refunded'], $statuses);
    }

    public function test_fractional_rate_rounds_only_at_the_end(): void
    {
        $c = $this->course(rate: 500.5, charge: 4004);
        $this->withToken($this->token())->getJson("/api/v1/student-classes/{$c->ID}/refund/preview")->assertOk()
            ->assertJson(['rate' => 500.5, 'refundable' => 3003]);
    }

    public function test_clients_cannot_write_a_refund_method_payment(): void
    {
        $c = $this->course();
        $inv = Invoice::query()->first();
        $this->withToken($this->token())->postJson("/api/v1/invoices/{$inv->id}/payments", ['Amount' => 100, 'PaidAt' => '2026-10-02', 'Method' => 'refund'])->assertStatus(422)->assertJsonValidationErrors(['Method']);
        $this->assertSame(0, Payment::where('Method', 'refund')->count());
    }

    public function test_positive_refund_method_row_is_not_treated_as_a_refund(): void
    {
        $c = $this->course();
        $inv = Invoice::query()->first();
        $row = Payment::create(['InvoiceID' => $inv->id, 'Amount' => 500, 'PaidAt' => '2026-10-02', 'Method' => 'refund']);
        $res = $this->withToken($this->token())->getJson("/api/v1/accounting/ledger?branch_id=1&student_class_id={$c->ID}")->assertOk();
        $this->assertSame('overpayment_pending_review', collect($res->json('invoices.0.payments'))->firstWhere('id', $row->id)['application_status']);
    }

    public function test_refunded_course_with_an_extra_payment_stays_in_review(): void
    {
        $c = $this->course();
        ClassSession::where('StudentClassID', $c->ID)->delete();
        $this->send($this->token(), $c, $this->body(4000))->assertOk();
        $this->assertNotSame('review_required', $this->state($c)['status']);
        Payment::create(['InvoiceID' => Invoice::query()->value('id'), 'Amount' => 500, 'PaidAt' => '2026-10-20', 'Method' => 'cash']);
        $this->assertSame('review_required', $this->state($c)['status']);
    }

    public function test_attendance_is_locked_after_a_refund(): void
    {
        $c = $this->course();
        $this->send($this->token(), $c, $this->body(1000))->assertOk();
        $held = ClassSession::where('StudentClassID', $c->ID)->where('Status', 'attended')->first();
        $this->expectException(\Illuminate\Validation\ValidationException::class); // same guard the waived / amended locks use
        $held->update(['Status' => 'cancelled']);
    }

    public function test_overpaid_contract_is_refused_in_preview_and_refund(): void
    {
        $c = $this->course();
        Payment::query()->update(['Amount' => 4500]);
        $token = $this->token();
        $msg = '這門課有多收的錢，請先在帳務處理多收，再退費。';
        $this->withToken($token)->getJson("/api/v1/student-classes/{$c->ID}/refund/preview")->assertStatus(422)->assertJsonPath('errors.course.0', $msg);
        $this->send($token, $c, $this->body(100))->assertStatus(422)->assertJsonPath('errors.course.0', $msg);
    }

    public function test_signed_in_scheduled_lesson_is_not_cancelled(): void
    {
        $c = $this->course();
        $s = ClassSession::where('StudentClassID', $c->ID)->where('Status', 'scheduled')->first();
        DB::table('StudentSingIn')->insert(['StudentClassID' => $c->ID, 'ClassSessionID' => $s->id, 'StudentID' => $c->StudentID, 'TeacherID' => 1, 'Status' => 'present', 'SignInDT' => now()]);
        $this->send($this->token(), $c, $this->body(1000))->assertOk();
        $this->assertSame('scheduled', $s->fresh()->Status);
        $list = $this->withToken($this->token())->getJson('/api/v1/student-classes?branch_id=1&student_id=' . $c->StudentID)->assertOk()->json();
        $this->assertNotSame('review_required', collect($list['data'] ?? $list)->firstWhere('ID', $c->ID)['usage_balance_status'] ?? null);
    }
}
