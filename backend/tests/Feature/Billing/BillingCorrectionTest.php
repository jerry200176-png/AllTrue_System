<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\BillingCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_token_is_bound_to_actor_and_target(): void
    {
        $svc = app(BillingCorrection::class);
        $c = $this->course();

        $a = $svc->confirmationToken($c, 6, 3000, 0, [], 1);

        $this->assertSame($a, $svc->confirmationToken($c, 6, 3000, 0, [], 1));
        $this->assertNotSame($a, $svc->confirmationToken($c, 6, 3000, 0, [], 2));
        $this->assertNotSame($a, $svc->confirmationToken($c, 5, 2500, 0, [], 1));
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
}
