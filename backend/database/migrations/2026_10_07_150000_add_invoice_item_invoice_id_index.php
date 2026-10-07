<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #3741 — InvoiceItem.InvoiceID had no index, so every items lookup
 * (incl. the batched eager loads from #3726/#3454) was a full-table scan.
 * Additive only; rollback = drop the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('InvoiceItem')) {
            return;
        }
        try {
            DB::statement('ALTER TABLE `InvoiceItem` ADD INDEX `idx_invitem_invoice_id` (`InvoiceID`)');
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'Duplicate key name')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('InvoiceItem')) {
            return;
        }
        try {
            DB::statement('ALTER TABLE `InvoiceItem` DROP INDEX `idx_invitem_invoice_id`');
        } catch (\Throwable $e) {
            // index already absent
        }
    }
};
