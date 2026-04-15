<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('StudentClass', function (Blueprint $table) {
            $table->unsignedBigInteger('PackageID')->nullable()->after('rate_unit');
            $table->unsignedInteger('PackageTotalSessions')->nullable()->after('PackageID');
            $table->string('PackageName', 128)->nullable()->after('PackageTotalSessions');

            $table->index('PackageID', 'idx_sc_package_id');
        });
    }

    public function down(): void
    {
        Schema::table('StudentClass', function (Blueprint $table) {
            $table->dropIndex('idx_sc_package_id');
            $table->dropColumn(['PackageID', 'PackageTotalSessions', 'PackageName']);
        });
    }
};
