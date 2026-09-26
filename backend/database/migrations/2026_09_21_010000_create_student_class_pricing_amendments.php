<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_class_pricing_amendments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('student_class_id');
            $table->date('effective_from');
            $table->unsignedInteger('rate');
            $table->string('rate_unit', 16)->default('session');
            $table->string('source_reference', 160);
            $table->string('reason', 255);
            $table->unsignedInteger('created_by_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedInteger('voided_by_user_id')->nullable();
            $table->string('void_reason', 255)->nullable();

            $table->unique(['student_class_id', 'effective_from'], 'sc_pricing_amendment_effective_unique');
            $table->index(['student_class_id', 'effective_from'], 'sc_pricing_amendment_lookup');
            $table->index(['student_class_id', 'voided_at'], 'sc_pricing_amendment_active_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_class_pricing_amendments');
    }
};
