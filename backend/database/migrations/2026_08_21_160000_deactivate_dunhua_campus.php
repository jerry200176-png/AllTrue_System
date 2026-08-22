<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CAMPUS_ID = 22;
    private const CAMPUS_CODE = 'dunhua';
    private const CAMPUS_NAME = '敦化分校';

    public function up(): void
    {
        if (!Schema::hasTable('Campus') || !Schema::hasColumn('Campus', 'active')) {
            return;
        }

        $updated = DB::table('Campus')
            ->where('id', self::CAMPUS_ID)
            ->where('code', self::CAMPUS_CODE)
            ->where('name', self::CAMPUS_NAME)
            ->where('active', true)
            ->update(['active' => false]);

        if ($updated > 0) {
            Log::notice('[Campus] Deactivated campus for product visibility', [
                'campus_id' => self::CAMPUS_ID,
                'campus_code' => self::CAMPUS_CODE,
                'reason' => 'campus_no_longer_in_use',
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally no-op: rolling back application code must not silently
        // make a retired campus selectable again. Re-enable through the
        // super-admin workflow only if the business decision is reversed.
    }
};
