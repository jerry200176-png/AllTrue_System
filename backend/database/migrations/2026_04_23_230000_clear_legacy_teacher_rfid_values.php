<?php

use Illuminate\Database\Migrations\Migration;

/**
 * R63 tombstone (ported 2026-07-10). This migration ran on production in April
 * 2026 (migrations batch 87) as a one-off legacy Teacher RFID data clear, but
 * the file was never committed and its content was later lost (0 bytes on the
 * Pi). Porting a documented no-op keeps migration history consistent across
 * environments: fresh databases have no legacy RFID values to clear, so a
 * no-op is semantically identical; production already has it recorded as ran.
 *
 * Found during the 2026-07-10 ops audit (#1128 cleanup); family: R63.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Intentionally empty — see tombstone note above.
    }

    public function down(): void
    {
        // One-off data clear; nothing to restore.
    }
};
