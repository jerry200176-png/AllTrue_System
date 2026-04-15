<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addIndexIfNotExists('LearningRecord', 'lr_status_sessiondate_idx', ['Status', 'SessionDate']);
        $this->addIndexIfNotExists('LearningRecord', 'lr_teachid_status_idx', ['TeacherID', 'Status']);
        $this->addIndexIfNotExists('LearningRecord', 'lr_scid_sessiondate_idx', ['StudentClassID', 'SessionDate']);
        $this->addIndexIfNotExists('ClassSession', 'cs_scid_sessiondate_idx', ['StudentClassID', 'SessionDate']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('LearningRecord', 'lr_status_sessiondate_idx');
        $this->dropIndexIfExists('LearningRecord', 'lr_teachid_status_idx');
        $this->dropIndexIfExists('LearningRecord', 'lr_scid_sessiondate_idx');
        $this->dropIndexIfExists('ClassSession', 'cs_scid_sessiondate_idx');
    }

    private function addIndexIfNotExists(string $table, string $indexName, array $columns): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }
        try {
            $cols = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
            DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` ({$cols})");
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate key name')) {
                return;
            }
            throw $e;
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }
        try {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
        } catch (\Throwable $e) {
            // Index doesn't exist — safe to ignore
        }
    }
};
