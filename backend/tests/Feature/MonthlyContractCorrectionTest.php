<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\MonthlyContractCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MonthlyContractCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $student = Student::create(['name' => 'Monthly correction fixture', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $source = StudentClass::create(['StudentID' => $student->id, 'TeacherID' => 1, 'GradeID' => 1, 'SubjectID' => 1,
            'by1' => 2, 'Period' => 4, 'StartDate' => '2026-08-01', 'EndDate' => '2026-09-30',
            'TotalHours' => 16, 'Charge' => 4000, 'Rate' => 1000, 'rate_unit' => 'session', 'Paid' => 1,
            'PayDate' => '2026-08-20', 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date',
            'SessionCount' => 8, 'UsedSessions' => 8, 'RemainingSessions' => 0, 'SessionDuration' => 120, 'settlement_day' => 20]);
        $ids = [];
        foreach (['2026-08-15', '2026-08-20', '2026-08-25', '2026-08-27', '2026-09-02', '2026-09-10', '2026-09-16', '2026-09-23'] as $date) {
            $ids[] = ClassSession::create(['StudentClassID' => $source->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended'])->id;
        }
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $source->ID,
            'IssueDate' => '2026-08-20', 'billing_period' => '2026-08', 'TotalAmount' => 4000, 'PaidAmount' => 4000, 'Status' => 'paid']);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 4000, 'Method' => 'cash', 'PaidAt' => '2026-08-20']);
        $input = ['source_start' => '2026-08-01', 'source_end' => '2026-08-31',
            'target_start' => '2026-09-01', 'target_end' => '2026-09-30',
            'target_course_id' => null, 'source_charge' => 4000, 'target_charge' => 4000,
            'payment_evidence_reference' => null];
        return [$source, $ids, $input, $invoice];
    }

    public function test_paid_monthly_split_preserves_ids_evidence_payment_and_can_rollback(): void
    {
        [$source, $ids, $input, $invoice] = $this->fixture();
        $id = $ids[4];
        DB::table('LearningRecord')->insert(['StudentClassID' => $source->ID, 'ClassSessionID' => $id, 'TeacherID' => 1, 'CreatedByUserID' => 1, 'Content' => 'Preserve content', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('StudentSingIn')->insert(['StudentClassID' => $source->ID, 'ClassSessionID' => $id, 'StudentID' => $source->StudentID, 'TeacherID' => 1, 'Status' => 'present', 'SignInDT' => now()]);
        $service = app(MonthlyContractCorrectionService::class);
        $plan = $service->preview($source, $input);
        $this->assertSame(4, count($plan['session_ids']));
        $this->assertSame(1, (int) $source->fresh()->Paid);
        $this->assertSame(1, StudentClass::count());
        $result = $service->execute($source, $input, $plan['confirmation_token'], 'monthly-fixture-1');
        $targetId = $result['target_course_id'];
        $this->assertSame('2026-08-31', substr((string) $source->fresh()->EndDate, 0, 10));
        $this->assertSame(1, (int) $source->fresh()->Paid);
        $this->assertSame(4000, (int) $invoice->fresh()->PaidAmount);
        $this->assertSame(4000, (int) Payment::first()->Amount);
        $this->assertSame(0, (int) StudentClass::find($targetId)->Paid);
        $this->assertNull(StudentClass::find($targetId)->PayDate);
        $this->assertSame($targetId, (int) ClassSession::find($id)->StudentClassID);
        $this->assertSame($targetId, (int) DB::table('LearningRecord')->value('StudentClassID'));
        $this->assertSame('Preserve content', DB::table('LearningRecord')->value('Content'));
        $this->assertSame($targetId, (int) DB::table('StudentSingIn')->value('StudentClassID'));
        $this->assertSame(8, ClassSession::count());
        $this->assertTrue($service->verify($result)['ok']);
        $this->assertSame($targetId, $service->execute($source->fresh(), $input, $plan['confirmation_token'], 'monthly-fixture-1')['target_course_id']);
        $service->rollback($result);
        $this->assertSame((int) $source->ID, (int) ClassSession::find($id)->StudentClassID);
        $this->assertSame('2026-09-30', substr((string) $source->fresh()->EndDate, 0, 10));
        $this->assertSame(1, StudentClass::count());
    }

    public function test_stale_preview_rejects_a_new_payment(): void
    {
        [$source, , $input, $invoice] = $this->fixture();
        $service = app(MonthlyContractCorrectionService::class);
        $plan = $service->preview($source, $input);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 1, 'Method' => 'cash', 'PaidAt' => '2026-08-21']);
        $this->expectException(ValidationException::class);
        $service->execute($source, $input, $plan['confirmation_token'], 'monthly-fixture-stale');
    }

    public function test_legacy_paid_flag_needs_explicit_payment_period_evidence(): void
    {
        [$source, , $input, $invoice] = $this->fixture();
        Payment::where('InvoiceID', $invoice->id)->delete();
        $invoice->delete();
        $this->expectException(ValidationException::class);
        app(MonthlyContractCorrectionService::class)->preview($source, $input);
    }

    public function test_source_charge_cannot_drop_below_preserved_collection(): void
    {
        [$source, , $input] = $this->fixture();
        $input['source_charge'] = 1000;
        $this->expectException(ValidationException::class);
        app(MonthlyContractCorrectionService::class)->preview($source, $input);
    }

    public function test_other_students_target_is_rejected(): void
    {
        [$source, , $input] = $this->fixture();
        $other = $source->replicate();
        $other->StudentID = $source->StudentID + 1;
        $other->save();
        $input['target_course_id'] = $other->ID;
        $this->expectException(ValidationException::class);
        app(MonthlyContractCorrectionService::class)->preview($source, $input);
    }

    public function test_existing_unpaid_contract_and_invoice_are_reused_and_restored(): void
    {
        [$source, $ids, $input] = $this->fixture();
        $target = $source->replicate();
        $target->forceFill(['StartDate' => $input['target_start'], 'EndDate' => $input['target_end'], 'Paid' => 0, 'PayDate' => null, 'UsedSessions' => 0, 'SessionCount' => 0])->save();
        $invoice = Invoice::create(['StudentID' => $source->StudentID, 'StudentClassID' => $source->ID,
            'IssueDate' => '2026-09-01', 'billing_period' => '2026-09', 'TotalAmount' => 4000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $input['target_course_id'] = $target->ID;
        $service = app(MonthlyContractCorrectionService::class);
        $plan = $service->preview($source, $input);
        $result = $service->execute($source, $input, $plan['confirmation_token'], 'monthly-existing-target');
        $this->assertSame((int) $target->ID, $result['target_course_id']);
        $this->assertSame(2, StudentClass::count());
        $this->assertSame((int) $target->ID, (int) $invoice->fresh()->StudentClassID);
        $this->assertSame(4, (int) $target->fresh()->UsedSessions);
        $service->rollback($result);
        $this->assertSame((int) $source->ID, (int) $invoice->fresh()->StudentClassID);
        $this->assertSame(0, (int) $target->fresh()->SessionCount);
        $this->assertSame((int) $source->ID, (int) ClassSession::find($ids[4])->StudentClassID);
    }

    public function test_rollback_refuses_new_attendance_and_preserves_it(): void
    {
        [$source, $ids, $input] = $this->fixture();
        $service = app(MonthlyContractCorrectionService::class);
        $plan = $service->preview($source, $input);
        $result = $service->execute($source, $input, $plan['confirmation_token'], 'monthly-rollback-drift');
        ClassSession::find($ids[4])->update(['Status' => 'completed']);
        $this->assertFalse($service->verify($result)['ok']);
        try {
            $service->rollback($result);
            $this->fail('Rollback should refuse post-repair drift');
        } catch (ValidationException $error) {
            $this->assertSame('completed', ClassSession::find($ids[4])->Status);
            $this->assertSame(2, StudentClass::count());
        }
    }

    public function test_transaction_rolls_back_if_audit_insert_fails(): void
    {
        [$source, $ids, $input] = $this->fixture();
        $service = app(MonthlyContractCorrectionService::class);
        $plan = $service->preview($source, $input);
        \App\Models\SessionCorrection::creating(function () { throw new \RuntimeException('injected audit failure'); });
        try {
            $service->execute($source, $input, $plan['confirmation_token'], 'monthly-atomic-failure');
            $this->fail('Injected failure must abort repair');
        } catch (\RuntimeException $error) {
            $this->assertSame('injected audit failure', $error->getMessage());
            $this->assertSame(1, StudentClass::count());
            $this->assertSame('2026-09-30', substr((string) $source->fresh()->EndDate, 0, 10));
            $this->assertSame((int) $source->ID, (int) ClassSession::find($ids[4])->StudentClassID);
            $this->assertSame(0, \App\Models\SessionCorrection::count());
        } finally {
            \App\Models\SessionCorrection::flushEventListeners();
        }
    }

    public function test_read_only_preview_enforces_campus_and_hides_financial_snapshot(): void
    {
        [$source, , $input] = $this->fixture();
        $controller = app(\App\Http\Controllers\MonthlyContractCorrectionController::class);
        $request = \Illuminate\Http\Request::create('/', 'POST', $input);
        $request->attributes->set('auth_role', 'director');
        $request->attributes->set('auth_campus_ids', [2]);
        $this->assertSame(403, $controller->preview($request, $source, app(MonthlyContractCorrectionService::class))->status());
        $request->attributes->set('auth_campus_ids', [1]);
        $response = $controller->preview($request, $source, app(MonthlyContractCorrectionService::class));
        $this->assertSame(200, $response->status());
        $this->assertArrayNotHasKey('snapshot', $response->getData(true));
        $this->assertSame('founder_approved_pop_only', $response->getData(true)['execution']);
        $this->assertSame(1, StudentClass::count());
        $this->assertSame(0, \App\Models\SessionCorrection::count());
    }

}
