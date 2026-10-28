<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-POP-015 §2-3: the `pop_inapp_agent` POP requester credential.
 * IssuedByUserID = the super admin who issued it (write-back attribution); CampusIDs = its explicit campus list
 * (JSON list of ints; NULL or empty denies everything); ExpiresAt = issuance + TTL.
 * Additive only: nullable, no default, no backfill. Existing attendance and control-plane clients stay NULL and unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ApiClient')) {
            return;
        }
        Schema::table('ApiClient', function (Blueprint $table): void {
            if (!Schema::hasColumn('ApiClient', 'IssuedByUserID')) {
                $table->unsignedBigInteger('IssuedByUserID')->nullable();
            }
            if (!Schema::hasColumn('ApiClient', 'CampusIDs')) {
                $table->json('CampusIDs')->nullable();
            }
            if (!Schema::hasColumn('ApiClient', 'ExpiresAt')) {
                $table->timestamp('ExpiresAt')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ApiClient')) {
            return;
        }
        foreach (['ExpiresAt', 'CampusIDs', 'IssuedByUserID'] as $column) {
            if (Schema::hasColumn('ApiClient', $column)) {
                Schema::table('ApiClient', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
