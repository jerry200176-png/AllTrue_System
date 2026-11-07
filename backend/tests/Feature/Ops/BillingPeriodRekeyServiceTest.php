<?php

namespace Tests\Feature\Ops;

use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\BillingPeriodRekeyStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The re-key through PopOperationService: a crash part-way must still be rollbackable by an approved rollback (in-app #514). */
final class BillingPeriodRekeyServiceTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'repair-billing-period-rekey-20261107';

    private function cycle(int $class, int $student): void
    {
        DB::table('Student')->insert(['id' => $student, 'name' => "s{$student}", 'CampusID' => 16, 'ClassID' => 1, 'enable' => 1]);
        DB::table('StudentClass')->insert(['ID' => $class, 'StudentID' => $student, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1, 'Period' => 4, 'Pay' => 0, 'Rate' => 0,
            'TotalHours' => 4, 'StartDate' => '2026-04-29', 'EndDate' => '2026-05-27', 'ClassType' => 'one_on_one', 'ScheduleMode' => 'date', 'Stop' => 0, 'Paid' => 0, 'Charge' => 7500]);
        DB::table('Invoice')->insert(['id' => $class, 'StudentID' => $student, 'StudentClassID' => $class, 'IssueDate' => '2026-04-29', 'TotalAmount' => 7500, 'PaidAmount' => 0,
            'Status' => 'unpaid', 'Note' => '', 'billing_period' => '2026-04', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('InvoiceItem')->insert(['InvoiceID' => $class, 'StudentClassID' => $class, 'Description' => 'x', 'Amount' => 7500, 'PeriodStart' => '2026-04-29', 'PeriodEnd' => '2026-05-27']);
        DB::table('ClassSession')->insert(['StudentClassID' => $class, 'SessionDate' => '2026-05-05', 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended']);
    }

    public function test_approved_rollback_after_a_crash_and_drift_restores_the_committed_bills(): void
    {
        foreach ([1, 2, 3] as $n) $this->cycle(100 + $n, $n);
        $this->app->bind(BillingPeriodRekeyStrategy::class, fn () => new class extends BillingPeriodRekeyStrategy {
            protected function beforeRow(int $index, array $row): void { if ($index === 2) throw new \LogicException('process died'); }
        });
        $service = new PopOperationService(new PopOperationCatalog(dirname(base_path()) . '/operations/catalog.yaml'));
        $ctx = ['production_sha' => str_repeat('a', 40), 'source' => 'github-actions:test'];
        $plan = (new BillingPeriodRekeyStrategy)->plan(['campus_ids' => ['all'], 'decision_reference' => self::REF, 'expected_digest' => '']);
        $params = ['campus_ids' => ['all'], 'decision_reference' => self::REF, 'expected_digest' => $plan['digest']];
        $draft = $service->createDraft('billing-period-rekey-20261107', $params, 'rekey-svc-test', 'machine:test', 'super_admin', [16], 1, $ctx);
        $id = (string) $draft['id'];
        $service->runDryRun($id, 'machine:test', 1, 'super_admin', [16], $ctx);
        $sha = str_repeat('c', 40);
        $token = $service->approve($id, 'founder-go-rekey-svc-test', 'user:2', 'super_admin', $sha, 2, [16], 15)['token'];

        $exec = $service->run($id, 'execute', $token, $sha, 'pop-pi-local', null, null, $ctx);
        $this->assertNotSame('succeeded', $exec['result'], 'the run crashed after two bills');
        $moved = DB::table('Invoice')->where('billing_period', '2026-05')->count();
        $this->assertSame(2, $moved);
        $this->assertSame(2, DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'rekey-row')->count());

        // drift: the third bill is voided, so the approved digest no longer matches and the plan gate would refuse
        DB::table('Invoice')->where('id', 103)->update(['Status' => 'void']);

        $rb = $service->run($id, 'rollback', $token, $sha, 'pop-pi-local', null, null, $ctx);
        $this->assertSame('rolled_back', $rb['result'], json_encode($rb));
        $this->assertSame(0, DB::table('Invoice')->where('billing_period', '2026-05')->count(), 'both committed bills are back on April');
    }
}
