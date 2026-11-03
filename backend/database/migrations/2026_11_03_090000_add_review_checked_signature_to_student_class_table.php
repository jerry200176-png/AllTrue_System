<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// #4798: add-only. Pins the billing picture a director marked 已核對; any change makes review return.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('StudentClass', 'review_checked_signature')) {
            Schema::table('StudentClass', function (Blueprint $table) {
                $table->string('review_checked_signature', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('StudentClass', 'review_checked_signature')) {
            Schema::table('StudentClass', function (Blueprint $table) {
                $table->dropColumn('review_checked_signature');
            });
        }
    }
};
