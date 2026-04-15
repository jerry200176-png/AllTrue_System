<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ClassSession', 'IsContractException')) {
            return;
        }
        Schema::table('ClassSession', function (Blueprint $table) {
            $table->boolean('IsContractException')->default(false)->after('Note');
            $table->index('IsContractException');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('ClassSession', 'IsContractException')) {
            return;
        }
        Schema::table('ClassSession', function (Blueprint $table) {
            $table->dropIndex(['IsContractException']);
            $table->dropColumn('IsContractException');
        });
    }
};
