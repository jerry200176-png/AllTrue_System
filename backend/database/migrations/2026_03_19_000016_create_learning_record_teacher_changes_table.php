<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('learning_record_teacher_changes')) {
            return;
        }

        Schema::create('learning_record_teacher_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('learning_record_id');
            $table->unsignedInteger('old_teacher_id')->nullable();
            $table->unsignedInteger('new_teacher_id');
            $table->unsignedInteger('changed_by');
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['learning_record_id', 'created_at'], 'lr_teacher_changes_record_created_idx');
            $table->index('new_teacher_id', 'lr_teacher_changes_new_teacher_idx');
            $table->index('changed_by', 'lr_teacher_changes_changed_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_record_teacher_changes');
    }
};
