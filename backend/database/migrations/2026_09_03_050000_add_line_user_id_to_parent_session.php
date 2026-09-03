<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope parent notification preferences to the LINE identity that logged in.
 * Nullable: phone-login sessions have no LINE subject; no production backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ParentSession') || Schema::hasColumn('ParentSession', 'line_user_id')) {
            return;
        }

        Schema::table('ParentSession', function (Blueprint $table) {
            $table->string('line_user_id', 64)->nullable()->after('identity_group_id')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ParentSession') || !Schema::hasColumn('ParentSession', 'line_user_id')) {
            return;
        }

        Schema::table('ParentSession', function (Blueprint $table) {
            $table->dropColumn('line_user_id');
        });
    }
};
