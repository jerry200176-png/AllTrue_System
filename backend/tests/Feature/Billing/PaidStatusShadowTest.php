<?php

namespace Tests\Feature\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\PaidStatusShadow;
use App\Services\BillingPayableResolver;
use App\Services\DunningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** F7 S5: shadow comparison logs resolver disagreements and never changes outbound behaviour. */
class PaidStatusShadowTest extends TestCase
{
    use RefreshDatabase;

    /** Paid=1 flag, but the only invoice is half paid: legacy says paid, resolver says partial. */
    private function partialPaidFlagCourse(): StudentClass
    {
        $student = Student::create(['name' => 'Shadow', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-08-01', 'TotalHours' => 20, 'Charge' => 10000, 'Paid' => 1, 'Rate' => 1500, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 5,
            'UsedSessions' => 0, 'ClassType' => 'one_on_one', 'LearnTimeID' => null,
        ]);
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-08-01', 'DueDate' => '2026-08-15',
            'TotalAmount' => 10000, 'PaidAmount' => 0, 'Status' => 'partial', 'billing_period' => '2026-08']);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 4000, 'PaidAt' => '2026-08-05', 'Method' => 'cash']);

        return $course;
    }

    public function test_logs_diff_for_partial_course_with_paid_flag(): void
    {
        $course = $this->partialPaidFlagCourse();
        Log::spy();

        app(PaidStatusShadow::class)->compare(collect([$course]), 'unit');

        Log::shouldHaveReceived('info')->once()->with('paid_status_shadow', \Mockery::on(fn (array $c) => $c['site'] === 'unit'
            && $c['total'] === 1 && $c['diff_total'] === 1 && $c['old_settled_resolver_owes'] === 1
            && $c['diff_course_ids'] === [(int) $course->ID]));
    }

    public function test_dunning_sends_nothing_new_and_logs_shadow(): void
    {
        $this->partialPaidFlagCourse();
        Log::spy();

        $events = (new DunningService())->evaluateAll(null, false);

        $this->assertSame([], array_values(array_filter($events, fn ($e) => ($e['event_type'] ?? $e['type'] ?? null) === 'unpaid_reminder')));
        Log::shouldHaveReceived('info')->with('paid_status_shadow', \Mockery::on(fn (array $c) => $c['site'] === 'dunning_count' && $c['diff_total'] === 1));
    }

    public function test_flag_off_logs_nothing(): void
    {
        $course = $this->partialPaidFlagCourse();
        config(['billing.paid_status_shadow' => false]);
        Log::spy();

        app(PaidStatusShadow::class)->compare(collect([$course]), 'unit');

        Log::shouldNotHaveReceived('info');
    }

    public function test_resolver_exception_is_swallowed_and_dunning_still_works(): void
    {
        $course = $this->partialPaidFlagCourse();
        $this->app->instance(BillingPayableResolver::class, new class extends BillingPayableResolver {
            public function __construct()
            {
            }

            public function courseStatusesByStudentClassIds(array $studentClassIds, iterable $courses = []): array
            {
                throw new \RuntimeException('boom');
            }
        });
        Log::spy();

        app(PaidStatusShadow::class)->compare(collect([$course]), 'unit');
        $this->assertIsArray((new DunningService())->evaluateAll(null, false));

        Log::shouldHaveReceived('warning')->with('paid_status_shadow_failed', \Mockery::type('array'));
    }
}
