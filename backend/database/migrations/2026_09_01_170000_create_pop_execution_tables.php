<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pop_operation_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('operation_id', 96);
            $table->string('strategy_id', 191);
            $table->unsignedInteger('catalog_version');
            $table->json('parameters');
            $table->char('parameters_hash', 64);
            $table->string('idempotency_key', 128)->unique();
            $table->string('status', 32);
            $table->string('actor', 128);
            $table->timestamps();
            $table->index(['status', 'operation_id']);
        });
        Schema::create('pop_approval_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('operation_id');
            $table->string('event_type', 32);
            $table->string('approval_reference', 128);
            $table->string('approver', 128);
            $table->string('approver_role', 32);
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->char('commit_sha', 40);
            $table->string('phase', 32);
            $table->char('parameters_hash', 64);
            $table->char('token_hash', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
            $table->index(['operation_id', 'phase', 'created_at']);
        });
        Schema::create('pop_execution_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operation_id');
            $table->uuid('execution_id')->unique();
            $table->string('phase', 32);
            $table->string('result', 32);
            $table->string('idempotency_key', 160)->unique();
            $table->uuid('correlation_id');
            $table->char('commit_sha', 40)->nullable();
            $table->string('approval_reference', 128)->nullable();
            $table->json('snapshot')->nullable();
            $table->json('payload');
            $table->string('actor', 128);
            $table->timestamp('created_at');
            $table->index(['operation_id', 'phase', 'created_at']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pop_execution_records');
        Schema::dropIfExists('pop_approval_events');
        Schema::dropIfExists('pop_operation_requests');
    }
};
