<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dunning_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('student_id');
            $table->unsignedBigInteger('student_class_id');
            $table->unsignedInteger('campus_id');
            $table->string('rule_key', 30);
            $table->string('channel', 20)->default('in_app');
            $table->unsignedInteger('triggered_by')->nullable();
            $table->timestamp('sent_at');
            $table->index(['student_id', 'sent_at']);
            $table->index(['student_class_id', 'rule_key', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_events');
    }
};
