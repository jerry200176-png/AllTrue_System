<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('Campus', 'staff_line_group_id')) {
            return;
        }
        Schema::table('Campus', function (Blueprint $table) {
            $table->string('staff_line_group_id', 64)->nullable()->after('messaging_channel_secret');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('Campus', 'staff_line_group_id')) {
            return;
        }
        Schema::table('Campus', function (Blueprint $table) {
            $table->dropColumn('staff_line_group_id');
        });
    }
};
