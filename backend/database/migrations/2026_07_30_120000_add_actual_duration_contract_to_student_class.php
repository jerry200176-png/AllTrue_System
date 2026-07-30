<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING Phase 1 — additive contract fields.
 *
 * `standard_lesson_minutes`: how many minutes ONE standard billing unit represents
 * for THIS course. Deliberately per-course and nullable — there is no company-wide
 * lesson length, and legacy/fixed-session courses simply do not need one. Separate
 * from `SessionDuration`, which stays the scheduling default; conflating the two is
 * the coupling this RFC exists to break.
 *
 * `deduction_basis`: `fixed_session` (default, every existing course — one attended
 * session consumes one lesson regardless of clock time) or `actual_duration`
 * (opt-in — consumes minutes proportional to this course's own standard).
 *
 * Expand-phase, additive only. The default makes every existing row explicitly
 * fixed_session rather than leaving an ambiguous NULL, so no course silently
 * changes behaviour. No runtime code reads these columns until the Phase 2
 * feature flag is enabled, so applying this migration alone changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('StudentClass')) {
            return;
        }

        Schema::table('StudentClass', function (Blueprint $table) {
            if (!Schema::hasColumn('StudentClass', 'standard_lesson_minutes')) {
                $table->integer('standard_lesson_minutes')->nullable();
            }
            if (!Schema::hasColumn('StudentClass', 'deduction_basis')) {
                $table->string('deduction_basis', 32)->default('fixed_session');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('StudentClass')) {
            return;
        }

        Schema::table('StudentClass', function (Blueprint $table) {
            if (Schema::hasColumn('StudentClass', 'deduction_basis')) {
                $table->dropColumn('deduction_basis');
            }
            if (Schema::hasColumn('StudentClass', 'standard_lesson_minutes')) {
                $table->dropColumn('standard_lesson_minutes');
            }
        });
    }
};
