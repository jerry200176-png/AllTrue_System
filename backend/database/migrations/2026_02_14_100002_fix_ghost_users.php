<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fix "Ghost Users" — User records with type 'T' or 'U' that
 * have no corresponding Teacher record. These users are invisible
 * in the teacher list because ProfileController joins with Teacher.
 *
 * This migration:
 * 1. Finds all ghost users (User exists, Teacher does not)
 * 2. Creates Teacher records for them (CampusID defaults to the
 *    first campus in the Campus table, or 0 if none exist)
 * 3. Logs the affected user IDs for auditing
 */
return new class extends Migration
{
    public function up(): void
    {
        // Find the default campus to assign ghost users to
        $defaultCampus = DB::table('Campus')->orderBy('id', 'asc')->first();
        $defaultCampusId = $defaultCampus ? $defaultCampus->id : 0;

        // Find ghost users: type is T or U, but no Teacher record exists
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            $ghostUsers = DB::select('
                SELECT u.id, u.LoginName, u.Name, u.type
                FROM `User` u
                LEFT JOIN `Teacher` t ON t.id = u.id
                WHERE u.type IN (\'T\', \'U\') AND t.id IS NULL
            ');
        } else {
            $ghostUsers = DB::select('
                SELECT u.id, u."LoginName", u."Name", u.type
                FROM "User" u
                LEFT JOIN "Teacher" t ON t.id = u.id
                WHERE u.type IN (\'T\', \'U\') AND t.id IS NULL
            ');
        }

        if (empty($ghostUsers)) {
            Log::info('[fix_ghost_users] No ghost users found. Nothing to do.');
            return;
        }

        Log::info('[fix_ghost_users] Found ' . count($ghostUsers) . ' ghost user(s). Creating Teacher records...');

        foreach ($ghostUsers as $ghost) {
            $enable = ($ghost->type === 'T') ? 1 : 0;

            DB::table('Teacher')->insert([
                'id'          => $ghost->id,
                'CampusID'    => $defaultCampusId,
                'T_Name'      => $ghost->Name ?? 'Unknown',
                'Enable'      => $enable,
                'MDT'         => now(),
                'TelegramID'  => '',
            ]);

            Log::info("[fix_ghost_users] Created Teacher record for User #{$ghost->id} ({$ghost->LoginName}), CampusID={$defaultCampusId}, Enable={$enable}");
        }

        Log::info('[fix_ghost_users] Done. ' . count($ghostUsers) . ' Teacher record(s) created.');
    }

    public function down(): void
    {
        // This migration is a data fix; reverting would delete Teacher records
        // that may have been subsequently modified. Intentionally left empty.
        Log::info('[fix_ghost_users] Rollback: no action taken (data fix migration).');
    }
};
