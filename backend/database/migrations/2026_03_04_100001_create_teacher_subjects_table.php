<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teacher_subjects')) {
            return;
        }
        Schema::create('teacher_subjects', function (Blueprint $table) {
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedInteger('subject_id');
            $table->primary(['teacher_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_subjects');
    }
};
