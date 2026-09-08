<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('Campus') || Schema::hasColumn('Campus', 'is_test')) {
            return;
        }

        Schema::table('Campus', function (Blueprint $table): void {
            // Nullable during the additive phase; the following migration backfills
            // existing rows before making the contract NOT NULL DEFAULT false.
            $table->boolean('is_test')->nullable()->after('active')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('Campus') || !Schema::hasColumn('Campus', 'is_test')) {
            return;
        }

        Schema::table('Campus', function (Blueprint $table): void {
            $table->dropIndex(['is_test']);
            $table->dropColumn('is_test');
        });
    }
};
