<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #3836 (Founder GO 2026-10-13) — append-only billing-center notes. Additive: one new table, no data writes.
 * Rows are never updated or deleted (no updated_at, no soft delete). Legacy Payment.Note,
 * payment_reports.note, Invoice.Note and StudentClass.Memo are untouched, so rollback = drop this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('billing_notes')) {
            return;
        }
        Schema::create('billing_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('campus_id')->index();
            // invoice | payment | payment_report (validated by BillingNote::SUBJECT_TYPES)
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->text('body');
            $table->unsignedBigInteger('author_user_id');
            // Name at write time: a later rename or account deletion must not change who wrote a permanent note.
            $table->string('author_name', 100)->default('');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id', 'created_at'], 'idx_billing_notes_subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_notes');
    }
};
