<?php

namespace Tests\Feature\Billing;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\ContractRenewal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Direct tests of the renewal module interface (ARCH2-C3 slice 1). */
class ContractRenewalTest extends TestCase
{
    use RefreshDatabase;

    private function course(array $over = []): StudentClass
    {
        $student = Student::create([
            'name' => '續報模組', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2032-03-01', 'TotalHours' => 20, 'Charge' => 4000, 'Paid' => 0, 'Rate' => 500, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0, 'week' => 2, 'time' => '20:00:00',
        ], $over));
    }

    public function test_purchase_batch_preview_prices_sessions_and_lists_schedule(): void
    {
        $r = app(ContractRenewal::class)->previewPurchaseBatch(
            $this->course(), ['sessions' => 6, 'start_date' => '2032-04-06'], 1, 'director'
        );

        $this->assertSame([], $r['blockers']);
        $this->assertSame(3000, $r['proposed_course']['charge']);
        $this->assertSame(6, $r['schedule']['created_sessions']);
        $this->assertSame('2032-04-06', $r['schedule']['first_session_date']);
        $this->assertSame('unpaid', $r['billing']['payment_status_after_confirm']);
        $this->assertSame(3000, $r['billing']['amount_due']);
    }

    public function test_purchase_batch_preview_blocks_missing_input_and_monthly_course(): void
    {
        $codes = fn (array $r) => array_column($r['blockers'], 'code');
        $svc = app(ContractRenewal::class);

        $this->assertSame(['sessions_required', 'start_date_required'], $codes($svc->previewPurchaseBatch($this->course(), [], 1, 'director')));
        $this->assertContains(
            'monthly_course_purchase_batch',
            $codes($svc->previewPurchaseBatch($this->course(['ScheduleMode' => 'date']), ['sessions' => 2, 'start_date' => '2032-04-06'], 1, 'director'))
        );
    }

    public function test_duplicate_finders_and_redaction(): void
    {
        $svc = app(ContractRenewal::class);
        $source = $this->course();
        $twin = StudentClass::create(array_merge($source->getAttributes(), ['ID' => null, 'StartDate' => '2032-05-04', 'SessionCount' => 6]));
        unset($twin);

        $this->assertNull($svc->findDuplicatePurchaseBatch($source, '2032-05-04', 7));
        $this->assertNotNull($svc->findDuplicatePurchaseBatch($source, '2032-05-04', 6));

        $p = ['billing' => ['discount' => ['x' => 1], 'k' => 2], 'payload' => ['discount' => 1]];
        $this->assertSame(['billing' => ['k' => 2], 'payload' => []], $svc->redactRenewalDiscount($p, false));
        $this->assertSame($p, $svc->redactRenewalDiscount($p, true));
    }

    public function test_preview_hash_is_stable_and_changes_with_payload(): void
    {
        $svc = app(ContractRenewal::class);
        $c = $this->course();
        $data = ['mode' => 'purchase_batch', 'sessions' => 4, 'start_date' => '2032-04-06'];

        $a = $svc->preview($c, $data, 1, 'director');
        $b = $svc->preview($c, $data, 1, 'director');
        $other = $svc->preview($c, ['sessions' => 5] + $data, 1, 'director');

        $this->assertSame($a['state_hash'], $b['state_hash']);
        $this->assertNotSame($a['state_hash'], $other['state_hash']);
        $this->assertSame('ok', $a['severity']);
        $this->assertSame($c->ID, $a['source_course']['id']);
        $this->assertSame('mode_required', $svc->preview($c, [], 1, 'director')['blockers'][0]['code']);
    }

    public function test_renew_monthly_preview_blocks_bad_dates_and_wrong_mode(): void
    {
        $svc = app(ContractRenewal::class);
        $monthly = $this->course(['ScheduleMode' => 'date', 'StartDate' => '2032-01-01', 'EndDate' => '2032-02-29']);

        $codes = fn (array $p) => array_column($p['blockers'], 'code');
        $this->assertContains('end_date_required', $codes($svc->preview($monthly, ['mode' => 'renew_monthly'], 1, 'director')));
        $this->assertContains('end_date_not_extended', $codes($svc->preview($monthly, ['mode' => 'renew_monthly', 'end_date' => '2032-02-01'], 1, 'director')));
        $this->assertContains('non_monthly_course', $codes($svc->preview($this->course(), ['mode' => 'renew_monthly', 'end_date' => '2032-09-30'], 1, 'director')));

        $ok = $svc->preview($monthly, ['mode' => 'renew_monthly', 'end_date' => '2032-03-31'], 1, 'director');
        $this->assertSame('date', $ok['proposed_course']['schedule_mode']);
        $this->assertSame('2032-03-31', $ok['schedule']['last_session_date']);
        $this->assertSame('unpaid', $ok['billing']['payment_status_after_confirm']);
    }

    public function test_cancel_future_sessions_tags_only_future_scheduled_rows(): void
    {
        $c = $this->course();
        $mk = fn (string $date, string $status) => ClassSession::create([
            'StudentClassID' => $c->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status,
        ]);
        $past = $mk('2000-01-03', 'scheduled');
        $future = $mk('2099-01-05', 'scheduled');
        $attended = $mk('2099-01-12', 'attended');

        $n = app(ContractRenewal::class)->cancelFutureScheduledSessions($c, 'settled');

        $this->assertSame(1, $n);
        $this->assertSame('scheduled', $past->fresh()->Status);
        $this->assertSame('attended', $attended->fresh()->Status);
        $this->assertSame('cancelled', $future->fresh()->Status);
        $this->assertStringContainsString('[結案取消]', (string) $future->fresh()->Note);
    }

    public function test_create_course_record_persists_and_reconciliation_flags_unpaid(): void
    {
        $svc = app(ContractRenewal::class);
        $c = $this->course(['Charge' => 4000, 'Paid' => 0]);
        $copy = $svc->createCourseRecord(['StudentID' => $c->StudentID, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2032-05-01', 'TotalHours' => 2, 'Charge' => 100, 'Paid' => 0, 'Rate' => 50, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionCount' => 2, 'SessionDuration' => 60, 'RemainingSessions' => 2, 'ClassType' => 'one_on_one', 'UsedSessions' => 0]);

        $this->assertTrue($copy->exists);
        $this->assertSame(100, (int) StudentClass::query()->where('ID', $copy->ID)->value('Charge'));
        $this->assertTrue($svc->courseNeedsPaymentReconciliation($c));
    }

    private function monthlyCourse(): StudentClass
    {
        return $this->course([
            'ScheduleMode' => 'date', 'SessionCount' => 0, 'RemainingSessions' => 0, 'settlement_day' => 15, 'monthly_sessions' => 8,
            'StartDate' => '2032-04-01', 'EndDate' => '2032-04-30', 'Charge' => 0, 'Rate' => 500, 'SessionDuration' => 120,
        ]);
    }

    public function test_renew_monthly_creates_next_period_unpaid_invoice_and_settles_source(): void
    {
        $source = $this->monthlyCourse();

        $r = app(ContractRenewal::class)->renewMonthly($source, '2032-05-31', null, 1, 'director');

        $this->assertSame(201, $r['status']);
        $this->assertSame('renew_monthly', $r['body']['mode']);
        $this->assertTrue($r['body']['source_closed']);
        $this->assertSame('2032-05-01', $r['body']['new_course']['start_date']);
        $this->assertSame('unpaid', $r['body']['invoice']['status']);
        $source->refresh();
        $this->assertSame(1, (int) $source->Stop);
        $this->assertStringStartsWith('settled', (string) $source->closed_reason);
        $this->assertSame($r['body']['new_course']['id'], (int) Invoice::query()->whereKey($r['body']['invoice']['id'])->value('StudentClassID'));
    }

    public function test_renew_monthly_rejects_a_duplicate_period_without_writing(): void
    {
        $source = $this->monthlyCourse();
        $svc = app(ContractRenewal::class);
        $this->assertSame(201, $svc->renewMonthly($source, '2032-05-31', null, 1, 'director')['status']);
        $before = StudentClass::query()->count();

        $again = $svc->renewMonthly($source->fresh(), '2032-05-31', null, 1, 'director');

        $this->assertGreaterThanOrEqual(409, $again['status']);
        $this->assertSame($before, StudentClass::query()->count());
    }
}
