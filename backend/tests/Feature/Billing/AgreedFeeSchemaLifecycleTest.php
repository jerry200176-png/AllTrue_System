<?php

namespace Tests\Feature\Billing;

use Illuminate\Support\Facades\DB;
use Tests\Support\AgreedFeeFixture;
use Tests\Support\AgreedFeeRefreshDatabase;
use Tests\Support\AgreedFeeTestSchema;
use Tests\TestCase;

class AgreedFeeSchemaLifecycleTest extends TestCase
{
    use AgreedFeeFixture, AgreedFeeRefreshDatabase;

    public function test_ready_schema_can_be_ensured_inside_the_outer_transaction(): void
    {
        $level = DB::connection()->transactionLevel();
        $this->assertGreaterThan(0, $level);
        AgreedFeeTestSchema::ensureExtensionColumns();
        $this->assertSame($level, DB::connection()->transactionLevel());
        $this->assertTrue(AgreedFeeTestSchema::hasExtensionColumns());
        $fixture = $this->fixture();
        $report = $this->receiptFor($fixture);
        $this->assertSame('confirmed', $report->fresh()->status);
        $this->assertSame(7600, (int) $fixture['invoice']->fresh()->PaidAmount);
    }

    public function test_existing_receipt_reissue_rows_keep_the_default_kind(): void
    {
        $id = DB::table('invoice_corrections')->insertGetId([
            'original_invoice_id' => 101, 'replacement_invoice_id' => 102,
            'student_class_id' => 1, 'amount' => 7600, 'reason' => 'Synthetic receipt history',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $this->assertSame('receipt_reissue', DB::table('invoice_corrections')->where('id', $id)->value('kind'));
    }

    public function test_rollback_preserves_agreed_fee_history(): void
    {
        DB::table('invoice_corrections')->insert([
            'student_class_id' => 1, 'kind' => 'agreed_fee_unbilled',
            'reason' => 'Synthetic unbilled history', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        try {
            AgreedFeeTestSchema::dropExtensionColumns();
            $this->fail('History must prevent destructive rollback.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Retain agreed-fee correction history', $e->getMessage());
        }
        $this->assertTrue(AgreedFeeTestSchema::hasExtensionColumns());
        $this->assertSame(1, DB::table('invoice_corrections')->where('kind', 'agreed_fee_unbilled')->count());
    }
}
