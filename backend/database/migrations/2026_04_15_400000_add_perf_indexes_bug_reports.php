<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bug_reports', function (Blueprint $table) {
            $table->index(['CampusID', 'status', 'created_at'], 'idx_bugs_campus_status_created');
            $table->index(['CampusID', 'severity', 'created_at'], 'idx_bugs_campus_severity_created');
            $table->index(['reporter_user_id', 'created_at'], 'idx_bugs_reporter_created');
        });
    }

    public function down(): void
    {
        Schema::table('bug_reports', function (Blueprint $table) {
            $table->dropIndex('idx_bugs_campus_status_created');
            $table->dropIndex('idx_bugs_campus_severity_created');
            $table->dropIndex('idx_bugs_reporter_created');
        });
    }
};
