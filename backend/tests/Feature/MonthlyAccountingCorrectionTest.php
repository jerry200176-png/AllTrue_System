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
        $this->simulateBinaryJsonOrder('accounting-fixture-1');
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
        $previousClock = \Carbon\Carbon::getTestNow();
        \Carbon\Carbon::setTestNow('2026-09-29 12:00:00');
        try {
            $alerts = app(\App\Http\Controllers\AlertController::class)->tuition($request)->getData(true);
            $row = collect($alerts)->firstWhere('id', (int) $target->ID);
            $this->assertNotNull($row);
            $this->assertSame(6000, $row['payable_amount']);
            $this->assertSame('unpaid', $row['payment_status']);
            $this->assertSame('2026-09', $row['billing_period']);
            $this->assertSame((int) $bill->id, $row['payable_invoice_id']);
            $this->assertNull(collect($alerts)->firstWhere('id', (int) $source->ID));
        } finally { \Carbon\Carbon::setTestNow($previousClock); }
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
        $this->simulateBinaryJsonOrder('accounting-strategy');
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

    public function test_accounting_preview_enforces_role_campus_and_hides_raw_snapshot(): void
    {
        [$source, , $input] = $this->fixture();
        $controller = app(\App\Http\Controllers\MonthlyContractCorrectionController::class);
        $service = app(MonthlyAccountingCorrectionService::class);
        $request = \Illuminate\Http\Request::create('/', 'POST', $input);
        $request->attributes->set('auth_role', 'director');
        $request->attributes->set('auth_campus_ids', [2]);
        $this->assertSame(403, $controller->accountingPreview($request, $source, $service)->status());
        $request->attributes->set('auth_campus_ids', [1]);
        $request->attributes->set('auth_role', 'teacher');
        $this->assertSame(403, $controller->accountingPreview($request, $source, $service)->status());
        $request->attributes->set('auth_role', 'director');
        $response = $controller->accountingPreview($request, $source, $service);
        $this->assertSame(200, $response->status());
        $this->assertSame(6000, $response->getData(true)['received_after']);
        $this->assertArrayNotHasKey('snapshot', $response->getData(true));
        $this->assertStringNotContainsString('report_token_hash', $response->getContent());
        $this->assertSame(1, Payment::count()); $this->assertSame(1, StudentClass::count());
        $request->merge(['actual_received_amount' => 7500]);
        $this->assertSame(422, $controller->accountingPreview($request, $source, $service)->status());
    }

    private function existingTargetFixture(): array
    {
        [$source, $ids, $input, $invoice, $report] = $this->fixture();
        $source->update(['Paid' => 0, 'PayDate' => null]);
        $invoice->update(['PaidAmount' => 0, 'Status' => 'unpaid']);
        $report->update(['status' => 'voided', 'voided_at' => now(), 'void_reason' => 'Director correction']);
        $void = Payment::create(['InvoiceID' => $invoice->id, 'Amount' => -7500, 'Method' => 'void', 'PaidAt' => '2026-09-29', 'payment_report_id' => $report->id]);
        $target = $source->replicate();
        $target->forceFill(['StartDate' => '2026-09-11', 'EndDate' => '2026-09-30', 'Charge' => 4500, 'SessionCount' => 3, 'UsedSessions' => 0, 'RemainingSessions' => 1])->save();
        $bill = Invoice::create(['StudentID' => $source->StudentID, 'StudentClassID' => $target->ID, 'IssueDate' => '2026-09-29', 'DueDate' => '2026-10-01', 'billing_period' => '2026-09', 'TotalAmount' => 4500, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $item = \App\Models\InvoiceItem::create(['InvoiceID' => $bill->id, 'Amount' => 4500, 'PeriodStart' => '2026-09-11', 'PeriodEnd' => '2026-09-30', 'Description' => 'Forecast']);
        $pending = [];
        foreach (['2026-09-16' => 'cancelled', '2026-09-23' => 'cancelled', '2026-09-30' => 'scheduled'] as $date => $status) {
            $pending[] = ClassSession::create(['StudentClassID' => $target->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status])->id;
        }
        $input['expected_receipt_status'] = 'voided'; $input['expected_void_payment_id'] = $void->id;
        $input['split']['target_course_id'] = $target->ID;
        $input['expected_target'] = ['start' => '2026-09-11', 'end' => '2026-09-30', 'charge' => 4500, 'invoice_id' => $bill->id, 'item_id' => $item->id];
        return [$source, $ids, $input, $target, $bill, $item, $pending];
    }

    public function test_existing_source_and_target_items_keep_their_ids_and_separate_ownership(): void
    {
        [$source, , $input, $target, , $targetItem] = $this->existingTargetFixture();
        $item = \App\Models\InvoiceItem::create(['InvoiceID' => $input['invoice_id'], 'Amount' => 7500,
            'PeriodStart' => '2026-07-27', 'PeriodEnd' => '2026-08-31', 'Description' => 'Original month']);
        $service = app(MonthlyAccountingCorrectionService::class);
        $plan = $service->preview($source, $input);
        $this->assertSame((int) $item->id, (int) $plan['input']['expected_source_item_id']);
        $this->assertNull($item->fresh()->StudentClassID);
        $this->assertSame(2, \App\Models\InvoiceItem::count());
        $result = $service->execute($source, $plan['input'], $plan['confirmation_token'], 'existing-source-item', 'pop:test');
        $this->assertTrue($service->verify($result)['ok']);
        $this->assertSame((int) $item->id, $result['source_item_id']);
        $this->assertSame(2, \App\Models\InvoiceItem::count());
        $this->assertSame((int) $source->ID, (int) $item->fresh()->StudentClassID);
        $this->assertSame((int) $target->ID, (int) $targetItem->fresh()->StudentClassID);
        $this->assertSame(6000, (int) $item->fresh()->Amount);
        $this->assertSame('2026-08-01', (string) $item->fresh()->PeriodStart);
        $this->assertSame('2026-08-31', (string) $item->fresh()->PeriodEnd);
        $this->assertSame(6000, (int) $targetItem->fresh()->Amount);
        $this->assertSame([7500, -7500, 6000], Payment::orderBy('id')->pluck('Amount')->map(fn ($n) => (int) $n)->all());
    }

    public function test_source_item_with_new_target_is_reused_without_target_owner_projection(): void
    {
        [$source, , $input] = $this->fixture();
        $item = \App\Models\InvoiceItem::create(['InvoiceID' => $input['invoice_id'], 'StudentClassID' => $source->ID,
            'Amount' => 7500, 'PeriodStart' => '2026-07-27', 'PeriodEnd' => '2026-08-31', 'Description' => 'Original month']);
        $service = app(MonthlyAccountingCorrectionService::class);
        $plan = $service->preview($source, $input);
        $result = $service->execute($source, $plan['input'], $plan['confirmation_token'], 'source-item-new-target', 'pop:test');
        $this->assertTrue($service->verify($result)['ok']);
        $this->assertSame((int) $item->id, $result['source_item_id']);
        $this->assertSame(2, \App\Models\InvoiceItem::count());
        $this->assertSame((int) $source->ID, (int) $item->fresh()->StudentClassID);
    }

    public function test_source_item_drift_or_ambiguous_ownership_cannot_write_cash(): void
    {
        [$source, , $input, $target] = $this->existingTargetFixture();
        $item = \App\Models\InvoiceItem::create(['InvoiceID' => $input['invoice_id'], 'Amount' => 7500,
            'PeriodStart' => '2026-07-27', 'PeriodEnd' => '2026-08-31', 'Description' => 'Original month']);
        $service = app(MonthlyAccountingCorrectionService::class);
        $plan = $service->preview($source, $input);
        foreach ([['StudentClassID' => $target->ID], ['Amount' => 4500], ['PeriodStart' => '2026-07-01']] as $change) {
            $item->forceFill(['StudentClassID' => null, 'Amount' => 7500, 'PeriodStart' => '2026-07-27']);
            $item->forceFill($change)->save();
            try {
                $service->execute($source, $plan['input'], $plan['confirmation_token'], 'source-item-drift', 'pop:test');
                $this->fail('Unreviewed source item must be rejected');
            } catch (ValidationException) {
                $this->assertSame(2, Payment::count());
                $this->assertSame(0, SessionCorrection::count());
                $this->assertSame(0, (int) $source->fresh()->Paid);
            }
        }
        $item->forceFill(['StudentClassID' => null, 'Amount' => 7500, 'PeriodStart' => '2026-07-27'])->save();
        $extra = $item->replicate(); $extra->save();
        $this->expectException(ValidationException::class);
        $service->preview($source, $input);
    }

    public function test_existing_target_and_already_voided_receipt_are_corrected_without_duplicates(): void
    {
        [$source, $ids, $input, $target, $bill, $item, $pending] = $this->existingTargetFixture();
        $service = app(MonthlyAccountingCorrectionService::class);
        $before = app(\App\Services\MonthlyContractCorrectionService::class)->snapshotGraph($source, $target);
        $plan = $service->preview($source, $input);
        $this->assertSame($before, app(\App\Services\MonthlyContractCorrectionService::class)->snapshotGraph($source->fresh(), $target->fresh()));
        $result = $service->execute($source, $input, $plan['confirmation_token'], 'accounting-existing', 'pop:test');
        $this->assertTrue($service->verify($result)['ok']);
        $this->assertSame((int) $target->ID, $result['target_course_id']);
        $this->assertSame((int) $bill->id, $result['target_invoice_id']);
        $this->assertSame(2, StudentClass::count()); $this->assertSame(2, Invoice::count());
        $this->assertSame([7500, -7500, 6000], Payment::orderBy('id')->pluck('Amount')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(2, PaymentReport::count()); $this->assertSame(11, ClassSession::count());
        $this->assertSame('2026-09-01', substr((string) $target->fresh()->StartDate, 0, 10));
        $this->assertSame(6000, (int) $bill->fresh()->TotalAmount); $this->assertSame('unpaid', $bill->fresh()->Status);
        $this->assertSame('2026-09-30', $bill->fresh()->DueDate);
        $this->assertSame((int) $target->ID, (int) $item->fresh()->StudentClassID);
        $this->assertSame(6000, (int) $item->fresh()->Amount);
        $this->assertSame('cancelled', ClassSession::find($pending[0])->Status);
        $this->assertSame('scheduled', ClassSession::find($pending[2])->Status);
        $this->assertSame(4, (int) $target->fresh()->UsedSessions); $this->assertSame(5, (int) $target->fresh()->SessionCount);
        $this->assertSame(0, (int) $target->fresh()->RemainingSessions); // Monthly counters do not represent future session coverage.
        $this->assertSame(1, (int) $source->fresh()->Paid);
        $this->assertSame('2026-09-04', substr((string) $source->fresh()->PayDate, 0, 10));
        $this->simulateBinaryJsonOrder('accounting-existing');
        $this->assertSame($result, $service->execute($source, $input, $plan['confirmation_token'], 'accounting-existing', 'pop:test'));
        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->attributes->set('auth_role', 'director'); $request->attributes->set('auth_campus_ids', [1]);
        $this->assertSame(6000, app(\App\Http\Controllers\AlertController::class)->tuitionSlipData($request, (int) $target->ID)->getData(true)['payable_amount']);
        $rollback = $service->rollback($result);
        $this->assertTrue($rollback['verified_cash_correction_preserved']);
        $this->assertSame(6000, (int) Payment::sum('Amount'));
        $this->assertSame(0, (int) $target->fresh()->Stop);
        $this->assertSame('2026-09-11', substr((string) $target->fresh()->StartDate, 0, 10));
        $this->assertSame('scheduled', ClassSession::find($pending[2])->Status);
        $this->assertSame((int) $source->ID, (int) ClassSession::find($ids[6])->StudentClassID);
        $this->assertSame('void', $bill->fresh()->Status);
    }

    public function test_readonly_preview_resolves_unique_parent_item_for_the_signed_manifest(): void
    {
        [$source, , $input, , , $item] = $this->existingTargetFixture();
        unset($input['expected_target']['item_id']);
        $plan = app(MonthlyAccountingCorrectionService::class)->preview($source, $input);
        $this->assertSame((int) $item->id, (int) $plan['input']['expected_target']['item_id']);
        $this->assertNull($item->fresh()->StudentClassID);
        $this->assertSame(2, Payment::count()); $this->assertSame(0, (int) Payment::sum('Amount'));
    }

    public function test_existing_target_drift_rejects_before_correct_receipt_is_registered(): void
    {
        [$source, , $input, , $bill] = $this->existingTargetFixture();
        $service = app(MonthlyAccountingCorrectionService::class); $plan = $service->preview($source, $input);
        $bill->update(['Note' => 'Changed after approval']);
        $this->expectException(ValidationException::class);
        try { $service->execute($source, $input, $plan['confirmation_token'], 'accounting-existing-stale', 'pop:test'); }
        finally { $this->assertSame(2, Payment::count()); $this->assertSame(0, (int) Payment::sum('Amount')); }
    }

    /** @dataProvider unsafeExistingTargets */
    public function test_unreviewed_existing_target_and_voided_cash_states_are_rejected(string $scenario): void
    {
        [$source, , $input, $target, $bill, $item, $pending] = $this->existingTargetFixture();
        if ($scenario === 'active_overlap') ClassSession::find($pending[0])->update(['Status' => 'scheduled']);
        if ($scenario === 'target_attended') ClassSession::find($pending[2])->update(['Status' => 'attended']);
        if ($scenario === 'target_paid') $bill->update(['PaidAmount' => 100, 'Status' => 'partial']);
        if ($scenario === 'extra_payment') Payment::create(['InvoiceID' => $bill->id, 'Amount' => 100, 'Method' => 'cash', 'PaidAt' => '2026-09-29']);
        if ($scenario === 'wrong_item_owner') $item->update(['StudentClassID' => $source->ID]);
        if ($scenario === 'wrong_void_owner') Payment::find($input['expected_void_payment_id'])->update(['payment_report_id' => null]);
        if ($scenario === 'wrong_void_amount') Payment::find($input['expected_void_payment_id'])->update(['Amount' => -6000]);
        if ($scenario === 'extra_item') \App\Models\InvoiceItem::create(['InvoiceID' => $bill->id, 'Amount' => 100, 'Description' => 'Other fee']);
        $before = app(\App\Services\MonthlyContractCorrectionService::class)->snapshotGraph($source->fresh(), $target->fresh());
        try { app(MonthlyAccountingCorrectionService::class)->preview($source, $input); $this->fail('Expected bounded review rejection'); }
        catch (ValidationException) { $this->assertSame($before, app(\App\Services\MonthlyContractCorrectionService::class)->snapshotGraph($source->fresh(), $target->fresh())); }
    }

    public static function unsafeExistingTargets(): array
    {
        return array_map(fn ($scenario) => [$scenario], ['active_overlap', 'target_attended', 'target_paid', 'extra_payment', 'wrong_item_owner', 'wrong_void_owner', 'wrong_void_amount', 'extra_item']);
    }

    public function test_existing_target_transaction_failure_restores_both_original_contracts(): void
    {
        [$source, , $input, $target] = $this->existingTargetFixture();
        $service = app(MonthlyAccountingCorrectionService::class); $plan = $service->preview($source, $input);
        $before = app(\App\Services\MonthlyContractCorrectionService::class)->snapshotGraph($source, $target);
        SessionCorrection::creating(function () { throw new \RuntimeException('audit failed'); });
        try { $service->execute($source, $input, $plan['confirmation_token'], 'accounting-existing-atomic', 'pop:test'); $this->fail('Expected transaction abort'); }
        catch (\RuntimeException $error) {
            $this->assertSame('audit failed', $error->getMessage());
            $this->assertSame($before, app(\App\Services\MonthlyContractCorrectionService::class)->snapshotGraph($source->fresh(), $target->fresh()));
        } finally { SessionCorrection::flushEventListeners(); }
    }

    public function test_retained_future_session_only_changes_monthly_fee_after_attendance(): void
    {
        [$source, , $input, $target, $bill, , $pending] = $this->existingTargetFixture();
        $service = app(MonthlyAccountingCorrectionService::class); $plan = $service->preview($source, $input);
        $service->execute($source, $input, $plan['confirmation_token'], 'accounting-existing-next', 'pop:test');
        $reconciliation = app(\App\Services\InvoiceAmountReconciliationService::class);
        $this->assertSame(6000, $reconciliation->resolve($bill->fresh(), $target->fresh())['total_amount']);
        ClassSession::find($pending[2])->update(['Status' => 'attended']);
        $this->assertSame(7500, $reconciliation->resolve($bill->fresh(), $target->fresh())['total_amount']);
        $this->assertSame(6000, (int) $bill->fresh()->TotalAmount); // Read-only projection preserves invoice audit value.
    }

    private function chainSchedule(StudentClass $course, string $date, string $status, ?int $parent = null): int
    {
        return DB::table('schedules')->insertGetId(['student_id' => $course->StudentID, 'branch_id' => 1,
            'student_course_id' => $course->ID, 'day_of_week' => 3, 'schedule_date' => $date,
            'start_time' => '18:00', 'end_time' => '20:00', 'status' => $status, 'type' => 'normal',
            'original_schedule_id' => $parent, 'deduction' => $status === 'scheduled' ? 1 : 0]);
    }

    public function test_complete_reschedule_chains_stay_in_their_period_and_rollback_preserves_history(): void
    {
        [$source, $ids, $input, $target, $bill] = $this->existingTargetFixture();
        $invoice = Invoice::findOrFail($input['invoice_id']);
        foreach ([['2026-08-13', '2026-08-15'], ['2026-08-25', '2026-08-25'], ['2026-09-09', '2026-09-10']] as [$from, $to]) {
            $parent = $this->chainSchedule($source, $from, 'rescheduled');
            $this->chainSchedule($source, $to, 'scheduled', $parent);
        }
        $this->chainSchedule($source, '2026-09-09', 'leave');
        $before = DB::table('schedules')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $service = app(MonthlyAccountingCorrectionService::class);
        $plan = $service->preview($source, $input);
        $this->assertSame($before, DB::table('schedules')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(0, (int) Payment::sum('Amount'));
        $result = $service->execute($source, $input, $plan['confirmation_token'], 'accounting-contained-chains', 'pop:test');
        $expected = array_map(function ($row) use ($target) {
            if ($row['schedule_date'] >= '2026-09-01') $row['student_course_id'] = $target->ID;
            return $row;
        }, $before);
        $this->assertEquals($expected, DB::table('schedules')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame((int) $target->ID, (int) ClassSession::find($ids[5])->StudentClassID);
        $this->assertSame(6000, (int) $bill->fresh()->TotalAmount);
        $this->assertSame('unpaid', $bill->fresh()->Status);
        $this->assertSame([7500, -7500, 6000], Payment::orderBy('id')->pluck('Amount')->map(fn ($n) => (int) $n)->all());
        $this->assertTrue($service->verify($result)['ok']);
        $service->rollback($result);
        $this->assertSame($before, DB::table('schedules')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(6000, (int) Payment::sum('Amount'));
        $this->assertSame(6000, (int) $invoice->fresh()->PaidAmount);
    }

    /** @dataProvider unsafeChainScenarios */
    public function test_unreviewable_reschedule_chains_do_not_change_cash_or_contracts(string $scenario): void
    {
        [$source, , $input, $target] = $this->existingTargetFixture();
        $parent = $this->chainSchedule($source, '2026-09-09', 'rescheduled');
        $child = $this->chainSchedule($source, '2026-09-10', 'scheduled', $parent);
        if ($scenario === 'missing_parent') DB::table('schedules')->where('id', $child)->update(['original_schedule_id' => 999999]);
        if ($scenario === 'cross_period') DB::table('schedules')->where('id', $parent)->update(['schedule_date' => '2026-08-27']);
        if ($scenario === 'cycle') {
            DB::table('schedules')->where('id', $parent)->update(['original_schedule_id' => $child]);
            DB::table('schedules')->where('id', $child)->update(['status' => 'rescheduled']);
        }
        if ($scenario === 'self_link') DB::table('schedules')->where('id', $parent)->update(['original_schedule_id' => $parent]);
        if ($scenario === 'outside_period') DB::table('schedules')->where('id', $parent)->update(['schedule_date' => '2026-07-27']);
        if ($scenario === 'wrong_branch') DB::table('schedules')->where('id', $parent)->update(['branch_id' => 2]);
        if ($scenario === 'wrong_student') DB::table('schedules')->where('id', $parent)->update(['student_id' => $source->StudentID + 1]);
        if ($scenario === 'active_parent') DB::table('schedules')->where('id', $parent)->update(['status' => 'scheduled']);
        if (in_array($scenario, ['foreign_parent', 'foreign_child', 'unowned_child'], true)) {
            $other = $source->replicate(); $other->save();
            $changed = $scenario === 'foreign_parent' ? $parent : $child;
            DB::table('schedules')->where('id', $changed)->update(['student_course_id' => $scenario === 'unowned_child' ? null : $other->ID]);
        }
        $before = app(\App\Services\MonthlyContractCorrectionService::class)->snapshotGraph($source, $target);
        try {
            app(MonthlyAccountingCorrectionService::class)->preview($source, $input);
            $this->fail('Unreviewable chain must be rejected');
        } catch (ValidationException) {
            $this->assertSame($before, app(\App\Services\MonthlyContractCorrectionService::class)->snapshotGraph($source, $target));
            $this->assertSame(0, (int) Payment::sum('Amount'));
            $this->assertSame(0, SessionCorrection::count());
        }
    }

    public static function unsafeChainScenarios(): array
    {
        return array_map(fn ($scenario) => [$scenario], ['missing_parent', 'cross_period', 'cycle', 'self_link', 'outside_period', 'wrong_branch',
            'wrong_student', 'active_parent', 'foreign_parent', 'foreign_child', 'unowned_child']);
    }

    public function test_new_external_chain_link_after_preview_prevents_receipt_registration(): void
    {
        [$source, , $input] = $this->existingTargetFixture();
        $parent = $this->chainSchedule($source, '2026-09-09', 'rescheduled');
        $this->chainSchedule($source, '2026-09-10', 'scheduled', $parent);
        $service = app(MonthlyAccountingCorrectionService::class);
        $plan = $service->preview($source, $input);
        $other = $source->replicate(); $other->save();
        $this->chainSchedule($other, '2026-09-11', 'scheduled', $parent);
        try {
            $service->execute($source, $input, $plan['confirmation_token'], 'accounting-chain-drift', 'pop:test');
            $this->fail('New external link must invalidate preview');
        } catch (ValidationException) {
            $this->assertSame(0, (int) Payment::sum('Amount'));
            $this->assertSame(2, Payment::count());
            $this->assertSame(0, SessionCorrection::count());
        }
    }

    public function test_changed_in_scope_chain_after_preview_invalidates_signed_cash_correction(): void
    {
        [$source, , $input] = $this->existingTargetFixture();
        $parent = $this->chainSchedule($source, '2026-09-09', 'rescheduled');
        $alternate = $this->chainSchedule($source, '2026-09-08', 'rescheduled');
        $child = $this->chainSchedule($source, '2026-09-10', 'scheduled', $parent);
        $service = app(MonthlyAccountingCorrectionService::class);
        $plan = $service->preview($source, $input);
        DB::table('schedules')->where('id', $child)->update(['original_schedule_id' => $alternate]);
        try {
            $service->execute($source, $input, $plan['confirmation_token'], 'accounting-in-scope-chain-drift', 'pop:test');
            $this->fail('Changed chain must invalidate the signed preview');
        } catch (ValidationException) {
            $this->assertSame(0, (int) Payment::sum('Amount'));
            $this->assertSame(2, Payment::count());
            $this->assertSame($alternate, (int) DB::table('schedules')->where('id', $child)->value('original_schedule_id'));
            $this->assertSame(0, SessionCorrection::count());
        }
    }

    private function simulateBinaryJsonOrder(string $reference): void
    {
        // MySQL JSON storage orders object keys; MariaDB's JSON text preserves them.
        $reorder = function (array $value) use (&$reorder): array {
            foreach ($value as &$item) if (is_array($item)) $item = $reorder($item);
            if (!array_is_list($value)) uksort($value, fn ($a, $b) => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
            return $value;
        };
        foreach (SessionCorrection::where('decision_reference', $reference)->get() as $correction) {
            $correction->update(['snapshot_before' => $reorder($correction->snapshot_before)]);
        }
    }

}
