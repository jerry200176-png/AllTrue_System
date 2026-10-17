<?php
namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** #4132 ownership/adoption, independent of the fee API; all DDL uses an isolated synthetic database. */
class InvoiceCorrectionSchemaAdoptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringStartsWith('AllTrue_test', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void { RefreshDatabaseState::$migrated = false; parent::tearDown(); }

    public function test_4132_extension_retains_fee_history_and_supports_no_invoice_reference(): void
    {
        $id = DB::table('invoice_corrections')->insertGetId(['kind' => 'agreed_fee_unbilled',
            'student_class_id' => 991, 'original_invoice_id' => null, 'replacement_invoice_id' => null,
            'amount' => null, 'new_charge' => 500, 'old_charge' => 8000, 'reason' => 'Synthetic',
            'idempotency_key' => '6f1c2b7e-3a4d-4e5f-8a9b-0c1d2e3f4a5b', 'created_at' => now()]);
        foreach (['2026_10_17_120000_extend_invoice_corrections_for_agreed_fee.php',
            '2026_10_15_120000_create_invoice_corrections_table.php'] as $file) {
            try { (require database_path('migrations/'.$file))->down(); $this->fail('Permanent history must survive code rollback'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('Retain', $e->getMessage()); }
        }
        $this->assertTrue(Schema::hasColumn('invoice_corrections', 'kind'));
        $this->assertSame(500, (int) DB::table('invoice_corrections')->where('id', $id)->value('new_charge'));
    }

    public function test_4132_adoption_refuses_duplicate_legacy_course_keys_before_altering_schema(): void
    {
        $extension = require database_path('migrations/2026_10_17_120000_extend_invoice_corrections_for_agreed_fee.php');
        $extension->down();
        foreach ([1, 3] as $id) DB::table('invoice_corrections')->insert(['original_invoice_id' => $id,
            'replacement_invoice_id' => $id + 1, 'student_class_id' => 991, 'amount' => 500,
            'reason' => 'Synthetic', 'idempotency_key' => '6f1c2b7e-3a4d-4e5f-8a9b-0c1d2e3f4a5b', 'created_at' => now()]);
        try { $extension->up(); $this->fail('Duplicate history must stop adoption'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('duplicate', $e->getMessage()); }
        $this->assertFalse(Schema::hasColumn('invoice_corrections', 'kind'));
        $this->assertSame(2, DB::table('invoice_corrections')->count());
    }
}
