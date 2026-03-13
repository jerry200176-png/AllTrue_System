<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('LearningRecord')) {
            return;
        }
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE LearningRecord MODIFY Progress TEXT NULL');
            DB::statement('ALTER TABLE LearningRecord MODIFY NextHomework TEXT NULL');
            DB::statement('ALTER TABLE LearningRecord MODIFY Comment TEXT NULL');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('LearningRecord')) {
            return;
        }
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE LearningRecord MODIFY Progress VARCHAR(128) NULL');
            DB::statement('ALTER TABLE LearningRecord MODIFY NextHomework VARCHAR(128) NULL');
            DB::statement('ALTER TABLE LearningRecord MODIFY Comment VARCHAR(255) NULL');
        }
    }
};
