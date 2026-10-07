<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #3741 — InvoiceItem.InvoiceID had no index, so every items lookup
 * (incl. the batched eager loads from #3726/#3454) was a full-table scan.
 * Additive only; rollback = ALTER TABLE `InvoiceItem` DROP INDEX `idx_invitem_invoice_id`.
 */
return new class extends Migration
{
    private const INDEX = 'idx_invitem_invoice_id';

    public function up(): void
    {
        if (!Schema::hasTable('InvoiceItem')) {
            return;
        }
        $columns = $this->indexColumns();
        if ($columns === ['InvoiceID']) {
            return;
        }
        if ($columns !== []) {
            throw new \RuntimeException(self::INDEX.' exists on InvoiceItem with unexpected columns: '.implode(',', $columns));
        }
        DB::statement('ALTER TABLE `InvoiceItem` ADD INDEX `'.self::INDEX.'` (`InvoiceID`)');
    }

    public function down(): void
    {
        if (Schema::hasTable('InvoiceItem') && $this->indexColumns() !== []) {
            DB::statement('ALTER TABLE `InvoiceItem` DROP INDEX `'.self::INDEX.'`');
        }
    }

    /** @return string[] columns of the index in Seq_in_index order; [] when absent */
    private function indexColumns(): array
    {
        return collect(DB::select('SHOW INDEX FROM `InvoiceItem` WHERE Key_name = ?', [self::INDEX]))
            ->sortBy('Seq_in_index')
            ->pluck('Column_name')
            ->values()
            ->all();
    }
};
