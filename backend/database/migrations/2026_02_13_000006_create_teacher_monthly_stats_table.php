<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teacher_monthly_stats')) {
            return;
        }
        Schema::create('teacher_monthly_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('TeacherID');
            $table->string('YearMonth', 7); // YYYY-MM
            $table->integer('SessionCount')->default(0);
            $table->timestamps();

            // Unique constraint to prevent duplicate rows per teacher per month
            $table->unique(['TeacherID', 'YearMonth']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_monthly_stats');
    }
};
