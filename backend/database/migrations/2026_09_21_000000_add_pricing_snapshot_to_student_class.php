<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('StudentClass', function (Blueprint $table): void {
            $table->json('pricing_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('StudentClass', function (Blueprint $table): void {
            $table->dropColumn('pricing_snapshot');
        });
    }
};
