<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teacher_signin_adjustments')) {
            return;
        }

        Schema::create('teacher_signin_adjustments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('teacher_signin_id');
            $table->unsignedInteger('adjusted_by_user_id');
            $table->text('adjust_reason');
            $table->dateTime('original_signin_dt');
            $table->dateTime('original_signout_dt')->nullable();
            $table->dateTime('new_signin_dt');
            $table->dateTime('new_signout_dt')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index('teacher_signin_id', 'idx_tsa_signin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_signin_adjustments');
    }
};
