<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pop_operation_requests', function (Blueprint $table): void {
            $table->char('context_hash', 64)->nullable()->after('parameters_hash');
        });

        Schema::table('pop_execution_records', function (Blueprint $table): void {
            $table->unsignedInteger('attempt_no')->default(1)->after('phase');
            $table->string('attempt_key', 192)->nullable()->after('idempotency_key');
            $table->char('request_fingerprint', 64)->nullable()->after('attempt_key');
        });

        DB::table('pop_execution_records')
            ->whereNull('attempt_key')
            ->update(['attempt_key' => DB::raw('idempotency_key')]);

        Schema::table('pop_execution_records', function (Blueprint $table): void {
            $table->dropUnique('pop_execution_records_idempotency_key_unique');
            $table->unique('attempt_key', 'pop_execution_records_attempt_key_unique');
            $table->index(['operation_id', 'phase', 'attempt_no'], 'pop_execution_records_attempt_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('pop_execution_records', function (Blueprint $table): void {
            $table->dropIndex('pop_execution_records_attempt_lookup_index');
            $table->dropUnique('pop_execution_records_attempt_key_unique');
            $table->unique('idempotency_key');
            $table->dropColumn(['attempt_no', 'attempt_key', 'request_fingerprint']);
        });

        Schema::table('pop_operation_requests', function (Blueprint $table): void {
            $table->dropColumn('context_hash');
        });
    }
};
