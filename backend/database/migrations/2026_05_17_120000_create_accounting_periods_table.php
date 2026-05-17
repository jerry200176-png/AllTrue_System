<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id');
            $table->smallInteger('period_year');
            $table->tinyInteger('period_month');
            $table->dateTime('closed_at')->nullable();
            $table->unsignedInteger('closed_by')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->unsignedInteger('reopened_by')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
