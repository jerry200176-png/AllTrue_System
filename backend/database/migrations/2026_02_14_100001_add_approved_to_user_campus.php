<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('UserCampus', 'Approved')) {
            return;
        }
        Schema::table('UserCampus', function (Blueprint $table) {
            $table->boolean('Approved')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('UserCampus', function (Blueprint $table) {
            $table->dropColumn('Approved');
        });
    }
};
