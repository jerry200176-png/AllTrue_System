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

    public function test_extension_metadata_keeps_nullable_links_and_course_key_uniqueness(): void
    {
        $columns = collect(DB::select('SHOW COLUMNS FROM invoice_corrections'))->keyBy('Field');
        $this->assertSame('NO', $columns['kind']->Null);
        $this->assertSame('receipt_reissue', $columns['kind']->Default);
        foreach (['original_invoice_id', 'replacement_invoice_id', 'amount'] as $name) {
            $this->assertSame('YES', $columns[$name]->Null, $name);
        }
        $index = collect(DB::select('SHOW INDEX FROM invoice_corrections'))
            ->where('Key_name', 'uq_invoice_corrections_course_key')->sortBy('Seq_in_index');
        $this->assertSame(['student_class_id', 'idempotency_key'], $index->pluck('Column_name')->values()->all());
        $this->assertSame([0], $index->pluck('Non_unique')->map(fn ($value) => (int) $value)->unique()->values()->all());
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
