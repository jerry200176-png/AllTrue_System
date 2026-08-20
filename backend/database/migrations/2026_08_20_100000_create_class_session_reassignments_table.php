<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log for POST /api/v1/class-sessions/{id}/reassign-contract (contract
 * renewal pain point — 洪睿淵 case; prior art: RFC_COURSE_CONTINUITY.md #1382).
 *
 * Append-only. Moving a ClassSession between StudentClass contracts never
 * touches LearningRecord — this table is the only record of "which contract
 * used to own this session".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('class_session_reassignments')) {
            return;
        }

        Schema::create('class_session_reassignments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('class_session_id')->comment('ClassSession.id at time of reassignment');
            $table->unsignedBigInteger('old_student_class_id')->comment('StudentClass.ID it moved off of');
            $table->unsignedBigInteger('new_student_class_id')->comment('StudentClass.ID it moved onto');
            $table->text('reason');
            $table->unsignedBigInteger('performed_by')->nullable()->comment('User.id of the acting super_admin');
            $table->timestamp('created_at')->nullable();

            $table->index('class_session_id');
            $table->index('old_student_class_id');
            $table->index('new_student_class_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_session_reassignments');
    }
};
