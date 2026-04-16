<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_line_bindings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->string('line_user_id', 64);
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->timestamp('bound_at')->useCurrent();

            $table->unique(['student_id', 'line_user_id'], 'slb_student_line_unique');
            $table->index('line_user_id', 'slb_line_user_id_idx');
            $table->index('campus_id', 'slb_campus_id_idx');
        });

        // Backfill from Student.LineID
        DB::statement("
            INSERT IGNORE INTO student_line_bindings (student_id, line_user_id, campus_id, bound_at)
            SELECT id, LineID, CampusID, NOW()
            FROM Student
            WHERE LineID IS NOT NULL AND LineID != ''
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('student_line_bindings');
    }
};
