<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fulltime_salary_profiles')) {
            Schema::create('fulltime_salary_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('teacher_id');
                $table->unsignedInteger('branch_id')->nullable();
                $table->decimal('base_salary', 10, 2);
                $table->date('effective_from');
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['teacher_id', 'effective_from']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fulltime_salary_profiles');
    }
};
