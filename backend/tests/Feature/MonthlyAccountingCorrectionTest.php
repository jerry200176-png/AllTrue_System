<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\SessionCorrection;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\MonthlyAccountingCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MonthlyAccountingCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $student = Student::create(['name' => 'Accounting fixture', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $source = StudentClass::create(['StudentID' => $student->id, 'TeacherID' => 1, 'GradeID' => 1, 'SubjectID' => 1,
            'by1' => 2, 'Period' => 4, 'StartDate' => '2026-07-27', 'EndDate' => '2026-09-10', 'TotalHours' => 16,
            'Charge' => 7500, 'Rate' => 1500, 'rate_unit' => 'session', 'Paid' => 1, 'PayDate' => '2026-09-04', 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'date', 'SessionCount' => 5, 'UsedSessions' => 8, 'RemainingSessions' => 0, 'SessionDuration' => 120, 'settlement_day' => 31]);
        $ids = [];
        foreach (['2026-08-15', '2026-08-20', '2026-08-25', '2026-08-27', '2026-09-02', '2026-09-10', '2026-09-16', '2026-09-23'] as $date) {
            $ids[] = ClassSession::create(['StudentClassID' => $source->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended'])->id;
        }
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $source->ID, 'IssueDate' => '2026-08-19',
            'DueDate' => '2026-07-31', 'billing_period' => '2026-07', 'TotalAmount' => 7500, 'PaidAmount' => 7500, 'Status' => 'paid']);
        $payment = Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 7500, 'Method' => 'transfer', 'PaidAt' => '2026-09-04']);
        $report = PaymentReport::create(['StudentID' => $student->id, 'StudentClassID' => $source->ID, 'InvoiceID' => $invoice->id,
            'reported_by_name' => 'Fixture payer', 'reported_amount' => 7500, 'payment_method' => 'transfer', 'payment_date' => '2026-09-04',
            'status' => 'confirmed', 'confirmed_at' => now(), 'payment_id' => $payment->id, 'report_token_hash' => hash('sha256', 'fixture'), 'token_expires_at' => now()]);
        $input = ['campus_id' => 1, 'invoice_id' => $invoice->id, 'report_id' => $report->id, 'payment_id' => $payment->id,
            'expected_start' => '2026-07-27', 'expected_end' => '2026-09-10', 'expected_billing_period' => '2026-07',
            'expected_registered_amount' => 7500, 'actual_received_amount' => 6000, 'expected_target_session_ids' => array_slice($ids, 4),
            'split' => ['source_start' => '2026-08-01', 'source_end' => '2026-08-31', 'target_start' => '2026-09-01', 'target_end' => '2026-09-30',
                'source_charge' => 6000, 'target_charge' => 6000, 'target_course_id' => null, 'payment_evidence_reference' => 'founder:fixture:6000']];
        return [$source, $ids, $input, $invoice, $report, $payment];
    }

    public function test_read_only_preview_never_posts_cash_or_changes_contract_dates(): void
    {
        [$source, , $input] = $this->fixture();
        $service = app(MonthlyAccountingCorrectionService::class);
        $before = app(\App\Services\MonthlyContractCorrectionService::class)->snapshotGraph($source);
        $plan = $service->preview($source, $input);
        $this->assertSame(6000, $plan['received_after']);
        $this->assertSame(6000, $plan['target_charge']);
        $this->assertSame('unpaid', $plan['target_payment_status']);
        $this->assertSame($before, app(\App\Services\MonthlyContractCorrectionService::class)->snapshotGraph($source->fresh()));
        $this->assertSame(0, SessionCorrection::count());
    }

    public function test_correction_preserves_evidence_and_receipt_history_and_creates_unpaid_september_bill(): void
    {
        [$source, $ids, $input, $invoice, $report, $payment] = $this->fixture();
        DB::table('LearningRecord')->insert(['StudentClassID' => $source->ID, 'ClassSessionID' => $ids[6], 'TeacherID' => 1, 'Content' => 'Keep evaluation', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('StudentSingIn')->insert(['StudentClassID' => $source->ID, 'ClassSessionID' => $ids[6], 'StudentID' => $source->StudentID, 'TeacherID' => 1, 'Status' => 'present', 'SignInDT' => now()]);
        $service = app(MonthlyAccountingCorrectionService::class); $plan = $service->preview($source, $input);
        $result = $service->execute($source, $input, $plan['confirmation_token'], 'accounting-fixture-1', 'pop:test');
        $this->assertTrue($service->verify($result)['ok']);
        $this->assertSame(7500, (int) $payment->fresh()->Amount);
        $this->assertSame([7500, -7500, 6000], Payment::orderBy('id')->pluck('Amount')->map(fn ($n) => (int) $n)->all());
        $this->assertSame('voided', $report->fresh()->status);
        $replacement = PaymentReport::find($result['replacement_report_id']);
        $this->assertSame('confirmed', $replacement->status);
        $this->assertSame(6000, (int) $replacement->reported_amount);
        $this->assertStringContainsString('founder:fixture:6000', $replacement->note);
        $this->assertNull($replacement->confirmed_by);
        $this->assertSame('2026-08', $invoice->fresh()->billing_period);
        $this->assertSame(6000, (int) $invoice->fresh()->TotalAmount);
        $this->assertSame(6000, (int) $invoice->fresh()->PaidAmount);
        $this->assertSame('2026-08-31', substr((string) $source->fresh()->EndDate, 0, 10));
        $this->assertSame('2026-08-01', substr((string) $source->fresh()->StartDate, 0, 10));
        $this->assertSame(4, (int) $source->fresh()->UsedSessions);
        $this->assertSame(1, (int) $source->fresh()->Stop);
        $target = StudentClass::find($result['target_course_id']);
        $this->assertSame(0, (int) $target->Paid); $this->assertNull($target->PayDate);
        $this->assertSame(0, (int) $target->Stop);
        $this->assertSame(4, (int) $target->UsedSessions);
        $bill = Invoice::find($result['target_invoice_id']);
        $this->assertSame('2026-09', $bill->billing_period); $this->assertSame('unpaid', $bill->Status);
        $this->assertSame(6000, (int) $bill->TotalAmount); $this->assertSame(0, (int) $bill->PaidAmount);
        $this->assertCount(4, $bill->billing_snapshot['sessions']);
        $this->assertSame('2026-09-30', $bill->DueDate);
        $this->assertSame((int) $target->ID, (int) DB::table('LearningRecord')->value('StudentClassID'));
        $this->assertSame('Keep evaluation', DB::table('LearningRecord')->value('Content'));
        $this->assertSame((int) $target->ID, (int) DB::table('StudentSingIn')->value('StudentClassID'));
        $this->assertSame(8, ClassSession::count());
        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->attributes->set('auth_role', 'director'); $request->attributes->set('auth_campus_ids', [1]);
        $slip = app(\App\Http\Controllers\AlertController::class)->tuitionSlipData($request, (int) $target->ID);
        $this->assertSame(200, $slip->status());
        $this->assertSame(6000, $slip->getData(true)['charge']);
        $this->assertSame(6000, $slip->getData(true)['payable_amount']);
        $this->assertSame($result, $service->execute($source, $input, $plan['confirmation_token'], 'accounting-fixture-1', 'pop:test'));
        $this->assertSame(3, Payment::count());
    }

    public function test_snapshot_change_prevents_any_payment_write(): void
    {
        [$source, $ids, $input] = $this->fixture();
        $service = app(MonthlyAccountingCorrectionService::class); $plan = $service->preview($source, $input);
        ClassSession::find($ids[0])->update(['Note' => 'Changed after approval']);
        try { $service->execute($source, $input, $plan['confirmation_token'], 'accounting-stale', 'pop:test'); $this->fail('Expected stale snapshot rejection'); }
        catch (ValidationException) { $this->assertSame(1, Payment::count()); $this->assertSame(1, StudentClass::count()); $this->assertSame(1, PaymentReport::count()); }
    }

    public function test_atomic_failure_restores_original_receipt_payment_and_dates(): void
    {
        [$source, , $input, $invoice, $report] = $this->fixture();
        $service = app(MonthlyAccountingCorrectionService::class); $plan = $service->preview($source, $input);
        SessionCorrection::creating(function () { throw new \RuntimeException('audit failed'); });
        try { $service->execute($source, $input, $plan['confirmation_token'], 'accounting-atomic', 'pop:test'); $this->fail('Expected transaction abort'); }
        catch (\RuntimeException $error) {
            $this->assertSame('audit failed', $error->getMessage());
            $this->assertSame(1, Payment::count()); $this->assertSame(1, Invoice::count()); $this->assertSame(1, PaymentReport::count()); $this->assertSame(1, StudentClass::count());
            $this->assertSame('confirmed', $report->fresh()->status); $this->assertSame(7500, (int) $invoice->fresh()->PaidAmount);
            $this->assertSame('2026-09-10', substr((string) $source->fresh()->EndDate, 0, 10));
        } finally { SessionCorrection::flushEventListeners(); }
    }

    public function test_contract_rollback_preserves_verified_cash_and_all_receipt_history(): void
    {
        [$source, $ids, $input] = $this->fixture();
        $service = app(MonthlyAccountingCorrectionService::class); $plan = $service->preview($source, $input);
        $result = $service->execute($source, $input, $plan['confirmation_token'], 'accounting-rollback', 'pop:test');
        $rollback = $service->rollback($result);
        $this->assertTrue($rollback['verified_cash_correction_preserved']);
        $this->assertSame(6000, (int) Payment::sum('Amount'));
        $this->assertSame(3, Payment::count()); $this->assertSame(2, PaymentReport::count());
        $this->assertSame('void', Invoice::find($result['target_invoice_id'])->Status);
        $this->assertSame(1, (int) StudentClass::find($result['target_course_id'])->Stop);
        $this->assertSame((int) $source->ID, (int) ClassSession::find($ids[6])->StudentClassID);
        $this->assertSame('2026-09-10', substr((string) $source->fresh()->EndDate, 0, 10));
    }
    /** @dataProvider invalidInputs */
    public function test_invalid_financial_scope_is_rejected_without_writes(string $scenario): void
    {
        [$source, $ids, $input, $invoice] = $this->fixture();
        if ($scenario === 'campus') $input['campus_id'] = 2;
        if ($scenario === 'missing_evidence') $input['split']['payment_evidence_reference'] = null;
        if ($scenario === 'wrong_fee') $input['split']['target_charge'] = 7500;
        if ($scenario === 'missing_session') $input['expected_target_session_ids'] = array_slice($ids, 5);
        if ($scenario === 'non_calendar') $input['split']['target_end'] = '2026-09-25';
        if ($scenario === 'additional_payment') Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 100, 'PaidAt' => '2026-09-05', 'Method' => 'cash']);
        if ($scenario === 'tutoring') $source->update(['ClassType' => 'tutoring']);
        if ($scenario === 'missing_rate') $source->update(['Rate' => 0]);
        $before = [Payment::count(), Invoice::count(), StudentClass::count(), $source->fresh()->getAttributes()];
        try { app(MonthlyAccountingCorrectionService::class)->preview($source, $input); $this->fail('Expected scoped precondition failure'); }
        catch (ValidationException) { $this->assertSame($before, [Payment::count(), Invoice::count(), StudentClass::count(), $source->fresh()->getAttributes()]); }
    }

    public static function invalidInputs(): array
    {
        return array_map(fn ($scenario) => [$scenario], ['campus', 'missing_evidence', 'wrong_fee', 'missing_session', 'non_calendar', 'additional_payment', 'tutoring', 'missing_rate']);
    }

    public function test_changed_post_repair_data_prevents_contract_rollback(): void
    {
        [$source, $ids, $input] = $this->fixture();
        $service = app(MonthlyAccountingCorrectionService::class); $plan = $service->preview($source, $input);
        $result = $service->execute($source, $input, $plan['confirmation_token'], 'accounting-rollback-stale', 'pop:test');
        ClassSession::find($ids[4])->update(['Note' => 'Changed after repair']);
        try { $service->rollback($result); $this->fail('Expected rollback drift rejection'); }
        catch (ValidationException) { $this->assertSame('unpaid', Invoice::find($result['target_invoice_id'])->Status); $this->assertSame(6000, (int) Payment::sum('Amount')); }
    }

    public function test_catalog_stays_planned_and_financial_rollback_is_not_claimed(): void
    {
        $entry = app(\App\Operations\PopOperationCatalog::class)->operation('monthly-accounting-correction');
        $this->assertSame('planned', $entry['lifecycle']);
        $this->assertTrue($entry['founder_approval_required']);
        $this->assertFalse($entry['reversible']);
        $this->assertTrue($entry['rollback_supported']);
    }

    public function test_operation_plan_rejects_wrong_campus_stale_token_and_changed_idempotent_input(): void
    {
        [$source, , $input] = $this->fixture();
        $service = app(MonthlyAccountingCorrectionService::class);
        $preview = $service->preview($source, $input);
        $strategy = app(\App\Operations\Strategies\MonthlyAccountingCorrectionStrategy::class);
        $parameters = ['campus_id' => 1, 'source_course_id' => (int) $source->ID, 'input' => $input,
            'confirmation_token' => $preview['confirmation_token'], 'decision_reference' => 'accounting-strategy'];
        $this->assertTrue($strategy->plan($parameters)['ok']);
        $this->assertFalse($strategy->plan(array_replace($parameters, ['campus_id' => 2]))['ok']);
        $this->assertFalse($strategy->plan(array_replace($parameters, ['confirmation_token' => str_repeat('0', 64)]))['ok']);
        $service->execute($source, $input, $preview['confirmation_token'], 'accounting-strategy', 'pop:test');
        $this->assertTrue($strategy->plan($parameters)['applied']);
        $parameters['input']['actual_received_amount'] = 7500;
        $this->assertFalse($strategy->plan($parameters)['ok']);
        $this->assertSame(6000, (int) Payment::sum('Amount'));
    }

    public function test_planned_operation_cannot_be_approved_for_production_execution(): void
    {
        [$source, , $input] = $this->fixture();
        $preview = app(MonthlyAccountingCorrectionService::class)->preview($source, $input);
        $engine = app(\App\Operations\PopOperationService::class);
        $request = $engine->createDraft('monthly-accounting-correction', ['campus_id' => 1,
            'source_course_id' => (int) $source->ID, 'input' => $input, 'confirmation_token' => $preview['confirmation_token'],
            'decision_reference' => 'accounting-gate'], 'accounting-gate', 'pop:test', 'director', [1]);
        try {
            $engine->approve($request['id'], 'founder-go-accounting-gate', 'pop:test', 'super_admin', str_repeat('a', 40));
            $this->fail('Planned operation must not be approvable');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('not active', $error->getMessage());
            $this->assertSame(0, DB::table('pop_approval_events')->count());
            $this->assertSame(1, Payment::count());
        }
    }

}
