<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #4021 — fix each AI triage token's expiry at issue time, so raising
 * config('triage_tokens.ttl_days') can never extend or revive a token.
 * Additive: one nullable column, no data writes. Rows issued before this
 * column existed stay NULL and get a fixed legacy cap in TriageTokenService.
 * Same column name as Sanctum 3+, which checks it natively after an upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            return;
        }
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('expires_at');
            });
        }
    }
};
