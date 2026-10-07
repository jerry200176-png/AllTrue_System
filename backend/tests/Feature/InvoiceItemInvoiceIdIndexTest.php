<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #3741 — InvoiceItem.InvoiceID must be indexed; migration is idempotent and reversible. */
class InvoiceItemInvoiceIdIndexTest extends TestCase
{
    use RefreshDatabase;

    /** DDL in test (DROP/ADD INDEX) cannot run inside RefreshDatabase transaction wrapper. */
    protected array $connectionsToTransact = [];

    private const MIGRATION = 'database/migrations/2026_10_07_150000_add_invoice_item_invoice_id_index.php';

    public function test_invoice_item_invoice_id_index_exists_after_migrations(): void
    {
        $this->assertTrue($this->hasIndex());
    }

    public function test_migration_is_idempotent_and_reversible(): void
    {
        $migration = require base_path(self::MIGRATION);

        $migration->up(); // second run: duplicate key is tolerated
        $this->assertTrue($this->hasIndex());

        $migration->down();
        $this->assertFalse($this->hasIndex());

        $migration->up();
        $this->assertTrue($this->hasIndex());
    }

    private function hasIndex(): bool
    {
        return collect(DB::select('SHOW INDEX FROM `InvoiceItem`'))
            ->contains(fn ($row) => $row->Key_name === 'idx_invitem_invoice_id' && $row->Column_name === 'InvoiceID');
    }
}
