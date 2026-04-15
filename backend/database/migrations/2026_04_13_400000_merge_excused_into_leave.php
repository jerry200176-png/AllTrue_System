<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE ClassSession SET Status = 'leave' WHERE Status = 'excused'");
        DB::statement("UPDATE StudentSingIn SET Status = 'leave' WHERE Status = 'excused'");
    }

    public function down(): void
    {
        // Not reversible — excused vs leave origin is lost after merge.
    }
};
