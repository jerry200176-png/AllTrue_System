<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teacher_subject_levels')) {
            return;
        }

        Schema::create('teacher_subject_levels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedInteger('subject_id');
            $table->enum('level', ['elementary', 'junior', 'high']);
            $table->timestamps();

            $table->unique(['teacher_id', 'subject_id', 'level'], 'tsl_unique');
            $table->index('teacher_id');
            $table->index('subject_id');
            $table->index('level');
        });

        // Backfill: for each existing teacher_subjects row, create entries
        // for all three levels so no teacher is accidentally blocked.
        if (Schema::hasTable('teacher_subjects')) {
            $rows = DB::table('teacher_subjects')->get();
            $now = now();
            foreach ($rows as $row) {
                foreach (['elementary', 'junior', 'high'] as $level) {
                    DB::table('teacher_subject_levels')->insertOrIgnore([
                        'teacher_id' => $row->teacher_id,
                        'subject_id' => $row->subject_id,
                        'level' => $level,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_subject_levels');
    }
};
