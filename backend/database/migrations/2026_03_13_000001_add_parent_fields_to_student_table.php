<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('Student')) {
            return;
        }

        Schema::table('Student', function (Blueprint $table) {
            if (!Schema::hasColumn('Student', 'parent_name')) {
                $table->string('parent_name', 64)->nullable()->after('Phone');
            }
            if (!Schema::hasColumn('Student', 'parent_phone')) {
                $table->string('parent_phone', 20)->nullable()->after('parent_name');
            }
            if (!Schema::hasColumn('Student', 'notes')) {
                $table->text('notes')->nullable()->after('parent_phone');
            }
            if (!Schema::hasColumn('Student', 'status')) {
                $table->string('status', 32)->nullable()->default('active')->after('notes');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('Student')) {
            return;
        }

        Schema::table('Student', function (Blueprint $table) {
            if (Schema::hasColumn('Student', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('Student', 'notes')) {
                $table->dropColumn('notes');
            }
            if (Schema::hasColumn('Student', 'parent_phone')) {
                $table->dropColumn('parent_phone');
            }
            if (Schema::hasColumn('Student', 'parent_name')) {
                $table->dropColumn('parent_name');
            }
        });
    }
};
