<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            throw new RuntimeException('personal_access_tokens must exist before its expiry upgrade');
        }

        // The original 2019 migration is already recorded on existing installations.
        if (! Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->timestamp('expires_at')->nullable()->index();
            });

            return;
        }

        if (! Schema::hasIndex('personal_access_tokens', ['expires_at'])) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->index('expires_at');
            });
        }
    }

    public function down(): void
    {
        // Keep expiry values and pre-existing columns/indexes during code rollback.
        // An idempotent additive migration must never undo an existing schema.
    }
};
