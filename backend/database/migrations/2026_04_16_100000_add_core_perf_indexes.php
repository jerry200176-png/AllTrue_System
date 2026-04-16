<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P0 performance indexes for core tables.
 *
 * Addresses full-table scans documented in
 * docs/DB_PERF_BASELINE_2026-04-16.md §3.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addIfMissing('Student', 'idx_student_campus_name', ['CampusID', 'name']);
        $this->addIfMissing('Student', 'idx_student_campus_status', ['CampusID', 'status']);
        $this->addIfMissing('Student', 'idx_student_rfid', ['RFID']);

        $this->addIfMissing('StudentClass', 'idx_sc_student_id', ['StudentID']);
        $this->addIfMissing('StudentClass', 'idx_sc_teacher_id', ['TeacherID']);
        $this->addIfMissing('StudentClass', 'idx_sc_stop_student', ['Stop', 'StudentID']);

        $this->addIfMissing('ClassSession', 'idx_cs_status', ['Status']);

        $this->addIfMissing('StudentSingIn', 'idx_ssi_student_id', ['StudentID']);
        $this->addIfMissing('StudentSingIn', 'idx_ssi_sc_id', ['StudentClassID']);
        $this->addIfMissing('StudentSingIn', 'idx_ssi_signindt', ['SignInDT']);

        $this->addIfMissing('Invoice', 'idx_inv_student_id', ['StudentID']);
        $this->addIfMissing('Invoice', 'idx_inv_sc_id', ['StudentClassID']);
        $this->addIfMissing('Invoice', 'idx_inv_status', ['Status']);

        $this->addIfMissing('Payment', 'idx_pay_invoice_id', ['InvoiceID']);

        $this->addIfMissing('UserCampus', 'idx_uc_user_id', ['UserID']);
        $this->addIfMissing('UserCampus', 'idx_uc_campus_approved', ['CampusID', 'Approved']);
    }

    public function down(): void
    {
        $this->dropIfExists('Student', 'idx_student_campus_name');
        $this->dropIfExists('Student', 'idx_student_campus_status');
        $this->dropIfExists('Student', 'idx_student_rfid');

        $this->dropIfExists('StudentClass', 'idx_sc_student_id');
        $this->dropIfExists('StudentClass', 'idx_sc_teacher_id');
        $this->dropIfExists('StudentClass', 'idx_sc_stop_student');

        $this->dropIfExists('ClassSession', 'idx_cs_status');

        $this->dropIfExists('StudentSingIn', 'idx_ssi_student_id');
        $this->dropIfExists('StudentSingIn', 'idx_ssi_sc_id');
        $this->dropIfExists('StudentSingIn', 'idx_ssi_signindt');

        $this->dropIfExists('Invoice', 'idx_inv_student_id');
        $this->dropIfExists('Invoice', 'idx_inv_sc_id');
        $this->dropIfExists('Invoice', 'idx_inv_status');

        $this->dropIfExists('Payment', 'idx_pay_invoice_id');

        $this->dropIfExists('UserCampus', 'idx_uc_user_id');
        $this->dropIfExists('UserCampus', 'idx_uc_campus_approved');
    }

    private function addIfMissing(string $table, string $indexName, array $columns): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }
        try {
            $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
            DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` ({$cols})");
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate key name')) {
                return;
            }
            throw $e;
        }
    }

    private function dropIfExists(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }
        try {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
        } catch (\Throwable $e) {
            // safe to ignore if index doesn't exist
        }
    }
};
