<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('User')) {
            return;
        }

        Schema::table('User', function (Blueprint $table) {
            if (!Schema::hasColumn('User', 'MustChangePassword')) {
                $table->boolean('MustChangePassword')->default(false);
            }
            if (!Schema::hasColumn('User', 'PasswordChangedAt')) {
                $table->timestamp('PasswordChangedAt')->nullable();
            }
            if (!Schema::hasColumn('User', 'PasswordSetByUserID')) {
                $table->unsignedBigInteger('PasswordSetByUserID')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('User')) {
            return;
        }

        Schema::table('User', function (Blueprint $table) {
            $dropColumns = [];
            foreach (['MustChangePassword', 'PasswordChangedAt', 'PasswordSetByUserID'] as $column) {
                if (Schema::hasColumn('User', $column)) {
                    $dropColumns[] = $column;
                }
            }

            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
