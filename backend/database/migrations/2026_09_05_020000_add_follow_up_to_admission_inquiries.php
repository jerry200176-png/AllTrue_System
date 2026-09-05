<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admission_inquiries') || Schema::hasColumn('admission_inquiries', 'follow_up_at')) {
            return;
        }

        Schema::table('admission_inquiries', function (Blueprint $table): void {
            $table->timestamp('follow_up_at')->nullable()->index()->after('assigned_to');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('admission_inquiries') && Schema::hasColumn('admission_inquiries', 'follow_up_at')) {
            Schema::table('admission_inquiries', function (Blueprint $table): void {
                $table->dropColumn('follow_up_at');
            });
        }
    }
};
