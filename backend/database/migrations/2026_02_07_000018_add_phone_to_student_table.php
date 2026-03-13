<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('Student', 'Phone')) {
            return;
        }
        Schema::table('Student', function (Blueprint $table) {
            $table->string('Phone', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('Student', function (Blueprint $table) {
            $table->dropColumn('Phone');
        });
    }
};
