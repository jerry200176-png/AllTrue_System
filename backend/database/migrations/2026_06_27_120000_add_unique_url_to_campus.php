<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRDE Phase 4 — atomic mapping / constraint layer.
 *
 * Makes the 2026-06-27 failure mode (two campuses sharing one URL →
 * non-deterministic host→campus resolution) impossible at the data layer.
 *
 * Steps:
 *   1. Normalize empty-string URLs to NULL so the unique index permits many
 *      "no public URL" campuses (MySQL allows multiple NULLs, not multiple '').
 *   2. Add a UNIQUE index on Campus.URL.
 *
 * Safety: verified read-only before authoring that exactly one campus (id 15,
 * 大安) holds a non-empty URL in production, so this applies cleanly. If a future
 * duplicate is ever attempted, the write fails loudly instead of silently
 * corrupting routing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('Campus', 'URL')) {
            return;
        }

        // 1. Empty string → NULL (idempotent).
        DB::table('Campus')->where('URL', '')->update(['URL' => null]);

        // 2. Add unique index, guarded against re-run.
        $exists = collect(DB::select("SHOW INDEX FROM `Campus` WHERE Key_name = 'campus_url_unique'"))->isNotEmpty();
        if (!$exists) {
            Schema::table('Campus', function ($table) {
                $table->unique('URL', 'campus_url_unique');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('Campus', 'URL')) {
            return;
        }
        $exists = collect(DB::select("SHOW INDEX FROM `Campus` WHERE Key_name = 'campus_url_unique'"))->isNotEmpty();
        if ($exists) {
            Schema::table('Campus', function ($table) {
                $table->dropUnique('campus_url_unique');
            });
        }
    }
};
