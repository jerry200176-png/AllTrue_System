<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only fixture for the legacy `teacher_branches` table.
 *
 * Production has this table from an out-of-band provisioning step, but no committed migration creates it, so the
 * CI test schema lacked it and the legacy-binding regression cases had to be skipped. This migration lives only
 * under tests/ and is registered only by the test application factory (Tests\CreatesApplication); it is never on
 * the production migration path. It runs during RefreshDatabase's one-time migrate:fresh, so the table exists
 * outside the per-test transaction and the legacy fallback can be exercised for real.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teacher_branches')) {
            return;
        }
        Schema::create('teacher_branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('teacher_id');
            $table->unsignedInteger('branch_id');
            $table->unique(['teacher_id', 'branch_id'], 'teacher_branches_teacher_branch_unique');
            $table->index('teacher_id');
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_branches');
    }
};
