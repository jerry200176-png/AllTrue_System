<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('User') || Schema::hasColumn('User', 'AvatarUrl')) {
            return;
        }

        Schema::table('User', function (Blueprint $table) {
            $table->string('AvatarUrl', 255)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('User') || !Schema::hasColumn('User', 'AvatarUrl')) {
            return;
        }

        Schema::table('User', function (Blueprint $table) {
            $table->dropColumn('AvatarUrl');
        });
    }
};
