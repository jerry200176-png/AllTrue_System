<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('Subject')) {
            return;
        }
        if (Schema::hasColumn('Subject', 'CampusID')) {
            return;
        }
        Schema::table('Subject', function (Blueprint $table) {
            $table->unsignedInteger('CampusID')->nullable()->after('Subject_Name');
            $table->index('CampusID');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('Subject') || !Schema::hasColumn('Subject', 'CampusID')) {
            return;
        }
        Schema::table('Subject', function (Blueprint $table) {
            $table->dropIndex(['CampusID']);
            $table->dropColumn('CampusID');
        });
    }
};
