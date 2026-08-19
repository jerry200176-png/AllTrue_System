<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * session_corrections was designed for "supersede a duplicate" cases (a keeper
 * + a superseded session), which always has a real replaced_by_session_id.
 * A plain "this session should never have counted, cancel it" correction (no
 * duplicate keeper — e.g. in-app #1903, a campus-closure day mistakenly
 * marked attended) has no replacement session. Widen the column instead of
 * forcing a fake self-reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw SQL — doctrine/dbal (required by Blueprint::change()) is not a
        // dependency of this project; avoid adding one for a single ALTER.
        DB::statement('ALTER TABLE session_corrections MODIFY replaced_by_session_id BIGINT UNSIGNED NULL COMMENT \'Keeper ClassSession.id; null when this correction has no replacement session\'');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE session_corrections MODIFY replaced_by_session_id BIGINT UNSIGNED NOT NULL COMMENT \'Keeper ClassSession.id\'');
    }
};
