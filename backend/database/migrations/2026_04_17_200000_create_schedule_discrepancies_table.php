<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('schedule_discrepancies')) {
            return;
        }

        Schema::create('schedule_discrepancies', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Optional link to the ClassSession the teacher is reporting about.
            // Nullable because the teacher may report a class that is missing from the system entirely.
            $table->unsignedBigInteger('class_session_id')->nullable();
            $table->unsignedBigInteger('reporter_id');
            $table->unsignedInteger('branch_id');

            // Discrepancy classification. Use a fixed enum set to keep reporting queries sharp.
            $table->enum('discrepancy_type', [
                'student_no_show',    // 系統有課，學生未到
                'wrong_student_list', // 學生名單有誤
                'wrong_time',         // 上課時間有誤
                'class_cancelled',    // 課程已取消（系統未更新）
                'missing_session',    // 學生到場，系統無課（搭配 class_session_id = null）
                'other',
            ]);

            // Free-form context fields — required for missing_session, optional otherwise.
            $table->date('session_date')->nullable();
            $table->string('subject', 64)->nullable();
            $table->string('student_name', 64)->nullable();
            $table->string('time_range', 32)->nullable();
            $table->string('notes', 500)->nullable();

            $table->enum('status', ['pending', 'acknowledged', 'resolved', 'withdrawn'])
                ->default('pending');

            // Audit trail — who moved this through the workflow.
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();

            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();

            $table->timestamp('withdrawn_at')->nullable();

            // Archival marker — set by monthly job 12 months after a resolved/withdrawn report.
            // Archived rows still queryable but excluded from dashboard counts.
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            // Dashboard list hotspot: branch + status + recency.
            $table->index(['branch_id', 'status', 'created_at'], 'sd_branch_status_created_idx');
            // Teacher "my reports" lookup.
            $table->index(['reporter_id', 'created_at'], 'sd_reporter_created_idx');
            // Duplicate-guard lookup (see FR-003).
            $table->index(['class_session_id', 'status'], 'sd_session_status_idx');
            $table->index('archived_at', 'sd_archived_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_discrepancies');
    }
};
