<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('StudentSingIn', function (Blueprint $table) {
            if (!Schema::hasColumn('StudentSingIn', 'SessionDeducted')) {
                $table->boolean('SessionDeducted')->default(false)->after('Status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('StudentSingIn', function (Blueprint $table) {
            $table->dropColumn('SessionDeducted');
        });
    }
};
