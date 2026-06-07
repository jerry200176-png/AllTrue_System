<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_time_rate_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();          // 老師 (User.id)
            $table->unsignedInteger('branch_id')->index();           // 分校
            $table->enum('class_size', ['1v1', '1v2', '1v3_plus']); // 班制
            $table->enum('session_type', ['subject', 'tutoring', 'trial'])->default('subject');
            $table->decimal('rate_per_30min', 8, 2);                // 每 30 分鐘薪資（TWD）
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'branch_id', 'class_size', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_time_rate_cards');
    }
};
