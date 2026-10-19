<?php

namespace Tests\Feature\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
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

    public function test_following_ordinary_refresh_reuses_the_restored_base_without_remigrating(): void
    {
        $fixture = $this->fixture();
        $report = $this->receiptFor($fixture);
        DB::table('invoice_corrections')->insert([
            'kind' => 'agreed_fee_unbilled', 'student_class_id' => $fixture['course']->ID,
            'reason' => 'Synthetic rollback isolation', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $this->assertTrue(AgreedFeeTestSchema::hasExtensionColumns());
        // Observe the real destruction callbacks after this case's outer rollback,
        // then exercise Laravel's ordinary RefreshDatabase path, without extension setup.
        $this->beforeApplicationDestroyed(function () use ($fixture, $report) {
            $this->assertTrue(RefreshDatabaseState::$migrated);
            $this->assertFalse(DB::table('Invoice')->where('id', $fixture['invoice']->id)->exists());
            $this->assertFalse(DB::table('payment_reports')->where('id', $report->id)->exists());
            $this->assertFalse(DB::table('invoice_corrections')->where('student_class_id', $fixture['course']->ID)->exists());
            $ddl = [];
            DB::listen(static function ($query) use (&$ddl) {
                if (preg_match('/^\s*(?:create|drop|alter)\s+table/i', $query->sql)) {
                    $ddl[] = $query->sql;
                }
            });
            try {
                $this->laravelRefreshTestDatabase();
                $this->assertFalse(Schema::hasColumn('invoice_corrections', 'kind'));
                $column = collect(DB::select('SHOW COLUMNS FROM invoice_corrections'))->firstWhere('Field', 'amount');
                $this->assertSame('NO', $column->Null);
                $this->assertSame([], $ddl, 'ordinary refresh must not remigrate an already restored base');
            } finally {
                $this->endOuterTestingTransactions();
                RefreshDatabaseState::$migrated = false;
            }
        });
    }

    public function test_mid_case_transaction_end_invalidates_the_schema_generation(): void
    {
        $this->endOuterTestingTransactions();
        $this->beginDatabaseTransaction();
        $this->beforeApplicationDestroyed(function () {
            $this->assertFalse(RefreshDatabaseState::$migrated);
            $this->assertTrue(Schema::hasColumn('invoice_corrections', 'kind'));
        });
    }

    /** @dataProvider existingCourseKeys */
    public function test_kind_only_schema_left_by_receipt_tests_can_be_completed(bool $keepCourseKey): void
    {
        $this->leaveNullableKindOnlySchema($keepCourseKey);
        $this->assertTrue(Schema::hasColumn('invoice_corrections', 'kind'));
        $this->assertFalse(AgreedFeeTestSchema::hasExtensionColumns());
        $this->assertFalse(AgreedFeeTestSchema::restoreBaseSchemaAfterTest(), 'partial schemas cannot be reused');
        $this->assertSame('YES', collect(DB::select('SHOW COLUMNS FROM invoice_corrections'))
            ->firstWhere('Field', 'kind')->Null, 'match the real receipt-test nullable definition');
        AgreedFeeTestSchema::ensureExtensionColumns();
        $this->assertTrue(AgreedFeeTestSchema::hasExtensionColumns());
        $kind = collect(DB::select('SHOW COLUMNS FROM invoice_corrections'))->firstWhere('Field', 'kind');
        $this->assertSame('NO', $kind->Null);
        $this->assertSame('receipt_reissue', $kind->Default);
        $index = collect(DB::select('SHOW INDEX FROM invoice_corrections'))
            ->where('Key_name', 'uq_invoice_corrections_course_key');
        $this->assertCount(2, $index, 'existing unique index must not be added twice');
        $this->beginDatabaseTransaction();
    }

    public static function existingCourseKeys(): array
    {
        return [[false], [true]];
    }

    public function test_null_kind_history_is_refused_without_reclassifying_it(): void
    {
        $this->leaveNullableKindOnlySchema(false);
        $id = DB::table('invoice_corrections')->insertGetId([
            'kind' => null, 'original_invoice_id' => 101, 'replacement_invoice_id' => 102,
            'student_class_id' => 1, 'amount' => 500, 'reason' => 'Synthetic unclassified history',
            'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        try {
            try {
                AgreedFeeTestSchema::ensureExtensionColumns();
                $this->fail('Null kind history must not be silently classified.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Retain unclassified correction history', $e->getMessage());
            }
            $this->assertNull(DB::table('invoice_corrections')->where('id', $id)->value('kind'));
            $this->assertFalse(AgreedFeeTestSchema::hasExtensionColumns());
            $this->assertSame(1, DB::table('invoice_corrections')->where('id', $id)->count());
        } finally {
            $this->rebuildIsolatedSchema();
        }
    }

    private function leaveNullableKindOnlySchema(bool $keepCourseKey): void
    {
        $this->endOuterTestingTransactions();
        AgreedFeeTestSchema::assertTestConnection();
        DB::statement("ALTER TABLE invoice_corrections DROP campus_id, DROP old_charge,
            DROP new_charge, DROP original_invoice_total, DROP request_hash, DROP state_hash,
            MODIFY kind VARCHAR(32) NULL DEFAULT 'receipt_reissue',
            MODIFY original_invoice_id BIGINT UNSIGNED NOT NULL,
            MODIFY replacement_invoice_id BIGINT UNSIGNED NOT NULL,
            MODIFY amount INT UNSIGNED NOT NULL");
        if (!$keepCourseKey) {
            Schema::table('invoice_corrections', fn ($table) => $table->dropUnique('uq_invoice_corrections_course_key'));
        }
    }
}
