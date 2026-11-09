<?php

namespace Tests\Feature\Ops;

use App\Models\PaymentReport;
use App\Operations\Strategies\LegacyVoidLinkManifest;
use App\Operations\Strategies\LegacyVoidLinkStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LegacyVoidLinkStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const PARAMS = ['decision_reference' => 'link-legacy-void-20261107', 'expected_digest' => ''];

    /** @var array<int,array<string,int>> void payment id => manifest row */
    private array $cases = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('Student')->insert(['id' => 9431, 'name' => 's', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        foreach ([[9401, 8800], [9402, 2500]] as [$inv, $amt]) {
            DB::table('Invoice')->insert(['id' => $inv, 'StudentID' => 9431, 'StudentClassID' => null, 'IssueDate' => '2026-06-01',
                'TotalAmount' => $amt, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
            $pay = DB::table('Payment')->insertGetId(['InvoiceID' => $inv, 'Amount' => $amt, 'PaidAt' => now(), 'Method' => 'transfer', 'created_at' => now()]);
            $report = PaymentReport::create(['StudentID' => 9431, 'StudentClassID' => 1, 'InvoiceID' => $inv, 'reported_by_name' => 's', 'payment_date' => now(),
                'payment_method' => 'transfer', 'reported_amount' => $amt, 'status' => 'confirmed', 'payment_id' => $pay,
                'report_token_hash' => hash('sha256', "k{$inv}"), 'token_expires_at' => now()->addDay()]);
            $void = DB::table('Payment')->insertGetId(['InvoiceID' => $inv, 'Amount' => -$amt, 'PaidAt' => now(), 'Method' => 'void', 'created_at' => now()]);
            $this->cases[$void] = ['invoice_id' => $inv, 'report_id' => (int) $report->id, 'payment_id' => $pay, 'amount' => -$amt];
        }
        LegacyVoidLinkManifest::useCasesForTesting($this->cases);
    }

    protected function tearDown(): void
    {
        LegacyVoidLinkManifest::useCasesForTesting(null);
        parent::tearDown();
    }

    private function payments(): array
    {
        return DB::table('Payment')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function readyPlan(LegacyVoidLinkStrategy $strategy): array
    {
        return $strategy->plan(array_replace(self::PARAMS, ['expected_digest' => $strategy->plan(self::PARAMS)['digest']]));
    }

    /** Everything but payment_report_id / updated_at, to prove nothing else moves. */
    private function money(): array
    {
        return array_map(fn ($r) => array_diff_key($r, ['payment_report_id' => 1, 'updated_at' => 1]), $this->payments());
    }

    public function test_links_each_void_row_to_its_receipt_only_then_second_apply_is_noop(): void
    {
        $s = new LegacyVoidLinkStrategy();
        $plan = $this->readyPlan($s);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame('before', $plan['state']);
        $money = $this->money();
        $invoices = DB::table('Invoice')->orderBy('id')->get(['id', 'PaidAmount', 'Status'])->map(fn ($r) => (array) $r)->all();

        $result = $s->execute($plan, ['operation_id' => 't']);

        self::assertSame(2, $result['updated']);
        foreach ($this->cases as $voidId => $c) {
            self::assertSame($c['report_id'], (int) DB::table('Payment')->where('id', $voidId)->value('payment_report_id'));
        }
        self::assertSame($money, $this->money());
        self::assertSame($invoices, DB::table('Invoice')->orderBy('id')->get(['id', 'PaidAmount', 'Status'])->map(fn ($r) => (array) $r)->all());
        self::assertTrue($s->verify($plan, $result)['ok']);
        self::assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop.legacy_void_link')->count());

        $again = $this->readyPlan($s);
        self::assertSame('after', $again['state']);
        $before = $this->payments();
        self::assertSame(0, $s->execute($again, ['operation_id' => 't2'])['updated']);
        self::assertSame($before, $this->payments());
    }

    public function test_receipt_with_another_linked_reversal_or_wrong_amount_fails_and_writes_nothing(): void
    {
        $s = new LegacyVoidLinkStrategy();
        $plan = $this->readyPlan($s);
        [$first, $second] = array_keys($this->cases);
        DB::table('Payment')->insert(['InvoiceID' => 9401, 'Amount' => -8800, 'PaidAt' => now(), 'Method' => 'void',
            'payment_report_id' => $this->cases[$first]['report_id'], 'created_at' => now()]);
        DB::table('Payment')->where('id', $second)->update(['Amount' => -100]);
        $before = $this->payments();

        $errors = $s->plan(self::PARAMS)['errors'];
        self::assertContains("void_{$first}_receipt_has_other_reversal", $errors);
        self::assertContains("void_{$second}_amount", $errors);
        self::assertContains('case_parameters_mismatch', $s->plan([])['errors']);
        try {
            $s->execute($plan, ['operation_id' => 't']);
            self::fail('apply must abort on drift');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('legacy_void_link_drift', $e->getMessage());
        }
        self::assertSame($before, $this->payments());
    }

    public function test_rollback_unlinks_only_what_this_run_linked(): void
    {
        $s = new LegacyVoidLinkStrategy();
        $original = $this->payments();
        $result = $s->execute($this->readyPlan($s), ['operation_id' => 't']);

        self::assertTrue($s->rollback($result['snapshot'], ['operation_id' => 't'])['ok']);
        self::assertSame($original, $this->payments(), 'all original timestamps and fields are restored exactly');
    }

    public function test_unbound_preview_cannot_execute_or_write_audit(): void
    {
        $strategy = new LegacyVoidLinkStrategy();
        $before = $this->payments();
        try {
            $strategy->execute($strategy->plan(self::PARAMS), ['operation_id' => 't']);
            self::fail('unbound preview must not execute');
        } catch (\RuntimeException $e) {
            self::assertSame('legacy_void_link_plan_not_ready', $e->getMessage());
        }
        self::assertSame($before, $this->payments());
        self::assertSame(0, DB::table('security_audit_events')->where('event_type', 'pop.legacy_void_link')->count());
    }

    public function test_new_payment_after_preview_aborts_even_when_manifest_rows_still_match(): void
    {
        $strategy = new LegacyVoidLinkStrategy();
        $plan = $this->readyPlan($strategy);
        DB::table('Payment')->insert(['InvoiceID' => 9401, 'Amount' => 100, 'Method' => 'cash', 'PaidAt' => now()]);
        $before = $this->payments();
        try {
            $strategy->execute($plan, ['operation_id' => 't']);
            self::fail('new payment must invalidate the reviewed digest');
        } catch (\RuntimeException $e) {
            self::assertSame('legacy_void_link_preview_digest_drift', $e->getMessage());
        }
        self::assertSame($before, $this->payments());
    }

    public function test_fractional_money_is_not_truncated_into_an_allowlisted_amount(): void
    {
        $first = array_key_first($this->cases);
        DB::table('Payment')->where('id', $first)->update(['Amount' => -8800.50]);
        DB::table('payment_reports')->where('id', $this->cases[$first]['report_id'])->update(['reported_amount' => 8800.50]);
        $before = $this->payments();
        $plan = (new LegacyVoidLinkStrategy())->plan(self::PARAMS);
        self::assertFalse($plan['ok']);
        self::assertContains("void_{$first}_amount", $plan['errors']);
        self::assertSame($before, $this->payments());
    }

    public function test_verify_and_rollback_refuse_later_state_changes(): void
    {
        $strategy = new LegacyVoidLinkStrategy();
        $plan = $this->readyPlan($strategy);
        $result = $strategy->execute($plan, ['operation_id' => 't']);
        $first = array_key_first($this->cases);
        DB::table('Payment')->where('id', $first)->update(['updated_at' => now()->addMinute()]);
        $before = $this->payments();
        self::assertContains('post_execution_state_drift', $strategy->verify($plan, $result)['errors']);
        try {
            $strategy->rollback($result['snapshot'], ['operation_id' => 't']);
            self::fail('rollback must not overwrite later work');
        } catch (\RuntimeException $e) {
            self::assertSame('legacy_void_link_rollback_state_drift', $e->getMessage());
        }
        self::assertSame($before, $this->payments());
    }

    public function test_catalog_entry_drafts_and_dry_runs(): void
    {
        $pop = new \App\Operations\PopOperationService(new \App\Operations\PopOperationCatalog(dirname(base_path()) . '/operations/catalog.yaml'));
        $id = $pop->createDraft('legacy-void-link-20261107', self::PARAMS, (string) \Illuminate\Support\Str::uuid(), 'synthetic-pop', 'super_admin', [])['id'];

        self::assertTrue($pop->runDryRun($id, 'synthetic-pop', null, 'super_admin', [])['ok']);
    }

    public function test_approval_rejects_unbound_preview_and_current_data_drift(): void
    {
        $founder = \App\Models\User::create(['LoginName' => 'synthetic-founder@test.invalid', 'Name' => 'fixture', 'PSW' => 'x', 'type' => 'S']);
        $actor = 'user:' . $founder->id;
        $pop = new \App\Operations\PopOperationService(new \App\Operations\PopOperationCatalog(dirname(base_path()) . '/operations/catalog.yaml'));
        foreach ([false, true] as $bound) {
            $parameters = self::PARAMS;
            if ($bound) $parameters['expected_digest'] = (new LegacyVoidLinkStrategy())->plan(self::PARAMS)['digest'];
            $id = $pop->createDraft('legacy-void-link-20261107', $parameters, (string) \Illuminate\Support\Str::uuid(), $actor, 'super_admin', [])['id'];
            self::assertTrue($pop->runDryRun($id, $actor, $founder->id, 'super_admin', [])['ok']);
            if ($bound) DB::table('Payment')->insert(['InvoiceID' => 9401, 'Amount' => 100, 'Method' => 'cash', 'PaidAt' => now()]);
            try {
                $pop->approve($id, 'founder-go-synthetic-legacy-link', $actor, 'super_admin', str_repeat('a', 40), $founder->id);
                self::fail('unbound or stale preview must not be approved');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('exact current reviewed preview digest', $e->getMessage());
            }
            self::assertSame(0, DB::table('pop_approval_events')->where('operation_id', $id)->count());
        }
    }

    public function test_current_bound_preview_can_be_approved_without_executing_data(): void
    {
        $founder = \App\Models\User::create(['LoginName' => 'synthetic-founder@test.invalid', 'Name' => 'fixture', 'PSW' => 'x', 'type' => 'S']);
        $actor = 'user:' . $founder->id;
        $pop = new \App\Operations\PopOperationService(new \App\Operations\PopOperationCatalog(dirname(base_path()) . '/operations/catalog.yaml'));
        $parameters = array_replace(self::PARAMS, ['expected_digest' => (new LegacyVoidLinkStrategy())->plan(self::PARAMS)['digest']]);
        $id = $pop->createDraft('legacy-void-link-20261107', $parameters, (string) \Illuminate\Support\Str::uuid(), $actor, 'super_admin', [])['id'];
        self::assertTrue($pop->runDryRun($id, $actor, $founder->id, 'super_admin', [])['ok']);
        $before = $this->payments();
        self::assertTrue($pop->approve($id, 'founder-go-synthetic-legacy-link', $actor, 'super_admin', str_repeat('a', 40), $founder->id)['ready']);
        self::assertSame($before, $this->payments());
    }
}
