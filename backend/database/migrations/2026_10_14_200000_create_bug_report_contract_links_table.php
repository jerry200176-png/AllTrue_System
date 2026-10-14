<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #4100 (Founder option B, 2026-10-14): a super admin links ONE open bug report to ONE course
 * contract; only that pair is readable through the triage billing snapshot. Additive: one new
 * table, no change to existing tables, no data writes. Revoke is soft (revoked_at/revoked_by)
 * so history is kept. `active` is 1 while the link is live and NULL once revoked; the unique
 * index on (bug_report_id, active) therefore allows at most ONE live contract per report while
 * keeping any number of revoked rows. Rollback = drop this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bug_report_contract_links')) {
            return;
        }
        Schema::create('bug_report_contract_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bug_report_id')->constrained('bug_reports');
            $table->unsignedBigInteger('student_class_id')->index();
            $table->unsignedBigInteger('created_by');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->boolean('active')->nullable();
            $table->unique(['bug_report_id', 'active'], 'uniq_bug_contract_link_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_report_contract_links');
    }
};
