<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** #4100 (option B): a super admin links ONE open bug report to ONE contract. Additive: one new table, no data writes.
 * Soft revoke keeps history; `active` is 1 while live, NULL once revoked, so unique (bug_report_id, active) = ONE live link per report. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bug_report_contract_links')) {
            $this->assertExistingTableIsComplete(); // a half-created table must fail loudly, never be skipped
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

    /** MySQL DDL is not transactional: a failed earlier run can leave the table without its unique index or FK. */
    private function assertExistingTableIsComplete(): void
    {
        $unique = collect(DB::select("SHOW INDEX FROM bug_report_contract_links WHERE Key_name = 'uniq_bug_contract_link_active' AND Non_unique = 0"))
            ->sortBy('Seq_in_index')->pluck('Column_name')->all();
        $fk = DB::select("SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bug_report_contract_links'"
            . " AND COLUMN_NAME = 'bug_report_id' AND REFERENCED_TABLE_NAME = 'bug_reports' AND REFERENCED_COLUMN_NAME = 'id'");
        $missing = array_keys(array_filter(['unique index uniq_bug_contract_link_active(bug_report_id, active)' => $unique !== ['bug_report_id', 'active'], 'foreign key bug_report_id -> bug_reports.id' => $fk === []]));
        if ($missing !== []) {
            throw new \RuntimeException('bug_report_contract_links already exists but is missing: ' . implode('; ', $missing)
                . '. Confirm it is empty, drop it and re-run migrate (see the #4118 release notes).');
        }
    }

    public function down(): void
    {
        // Intentionally empty: up() may adopt an existing table, so rollback never drops link history (Founder 2026-10-14 補充 #1).
    }
};
