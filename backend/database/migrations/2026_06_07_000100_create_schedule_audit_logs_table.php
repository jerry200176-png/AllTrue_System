<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('session_id')->index();
            $table->enum('action_type', ['create', 'update', 'delete', 'batch_import'])->index();
            $table->string('description', 255)->nullable();
            $table->unsignedBigInteger('operator_id')->nullable()->index();
            $table->unsignedInteger('branch_id')->nullable()->index();
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_audit_logs');
    }
};
