<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 驗證 2026_04_17_500000_backfill_session_charge_for_session_mode 的行為：
 *
 *   - session mode 課程若 session_charge != Rate，應改寫為 Rate
 *   - StudentClass.Charge 應按 delta = new_session_charge − old_session_charge 加總
 *   - 稽核記錄應寫入 session_charge_backfill_log
 *   - hour mode 不受影響
 */
class SessionChargeBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_corrects_session_mode_and_preserves_hour_mode(): void
    {
        // 先清除 backfill_log（避免 truncate 破壞 RefreshDatabase 的 transaction）
        if (Schema::hasTable('session_charge_backfill_log')) {
            DB::table('session_charge_backfill_log')->delete();
        }

        // Session mode course：Rate=5000, SessionDuration=120
        //   session1: session_charge=3750 (舊的 75% 縮放) → 應修正為 5000
        //   session2: session_charge=5000 (已正確) → 不動
        //   session3: session_charge=NULL → 不動
        $sessionCourseId = DB::table('StudentClass')->insertGetId($this->studentClassRow([
            'Memo' => 'backfill-session',
            'Rate' => 5000,
            'rate_unit' => 'session',
            'SessionDuration' => 120,
            'SessionCount' => 4,
            'Charge' => 20000,
        ]));

        $s1 = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $sessionCourseId,
            'SessionDate' => '2026-04-18',
            'StartTime' => '16:00',
            'EndTime' => '17:30',
            'Status' => 'attended',
            'session_charge' => 3750,
        ]);
        DB::table('ClassSession')->insert([
            'StudentClassID' => $sessionCourseId,
            'SessionDate' => '2026-04-19',
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
            'session_charge' => 5000,
        ]);
        DB::table('ClassSession')->insert([
            'StudentClassID' => $sessionCourseId,
            'SessionDate' => '2026-04-20',
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
            'session_charge' => null,
        ]);

        // Hour mode course：Rate=750/hour, SessionDuration=120
        //   session_charge=1125 (90分鐘正確) → 不動
        $hourCourseId = DB::table('StudentClass')->insertGetId($this->studentClassRow([
            'Memo' => 'backfill-hour',
            'Rate' => 750,
            'rate_unit' => 'hour',
            'SessionDuration' => 120,
            'SessionCount' => 4,
            'Charge' => 5625,
        ]));

        DB::table('ClassSession')->insert([
            'StudentClassID' => $hourCourseId,
            'SessionDate' => '2026-04-21',
            'StartTime' => '16:00',
            'EndTime' => '17:30',
            'Status' => 'attended',
            'session_charge' => 1125,
        ]);

        // 執行 migration（artisan migrate 已於 RefreshDatabase 跑過，我們手動 re-run up）
        $migrationFile = database_path('migrations/2026_04_17_500000_backfill_session_charge_for_session_mode.php');
        $migration = require $migrationFile;

        // 先手動製造「尚未修正」的狀態：RefreshDatabase 已跑過 migration，
        // 所以本次測試的 insert 資料尚未被舊 migration 碰到。我們直接呼叫 up() 驗證邏輯。
        $migration->up();

        // Session mode session1：應修正
        $this->assertSame(5000, (int) DB::table('ClassSession')->where('id', $s1)->value('session_charge'));

        // Session mode 課程 Charge：delta = 5000 - 3750 = +1250 → 20000 + 1250 = 21250
        $this->assertSame(21250, (int) DB::table('StudentClass')->where('ID', $sessionCourseId)->value('Charge'));

        // Hour mode 不受影響
        $this->assertSame(5625, (int) DB::table('StudentClass')->where('ID', $hourCourseId)->value('Charge'));

        // 稽核記錄已寫入
        $logs = DB::table('session_charge_backfill_log')
            ->where('class_session_id', $s1)
            ->get();
        $this->assertCount(1, $logs);
        $log = $logs->first();
        $this->assertSame(3750, (int) $log->old_session_charge);
        $this->assertSame(5000, (int) $log->new_session_charge);
        $this->assertSame(1250, (int) $log->charge_delta);
        $this->assertSame('session_mode_fixed_rate_backfill', $log->reason);
    }

    public function test_migration_is_idempotent_when_rerun(): void
    {
        // 建立一筆乾淨的 session mode 課程（session_charge 已 = Rate）
        $sessionCourseId = DB::table('StudentClass')->insertGetId($this->studentClassRow([
            'Memo' => 'backfill-idempotent',
            'Rate' => 5000,
            'rate_unit' => 'session',
            'SessionDuration' => 120,
            'SessionCount' => 4,
            'Charge' => 20000,
        ]));

        DB::table('ClassSession')->insert([
            'StudentClassID' => $sessionCourseId,
            'SessionDate' => '2026-04-22',
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
            'session_charge' => 5000,
        ]);

        $migrationFile = database_path('migrations/2026_04_17_500000_backfill_session_charge_for_session_mode.php');
        $migration = require $migrationFile;

        $migration->up();
        $chargeAfterFirst = (int) DB::table('StudentClass')->where('ID', $sessionCourseId)->value('Charge');

        $migration->up();
        $chargeAfterSecond = (int) DB::table('StudentClass')->where('ID', $sessionCourseId)->value('Charge');

        $this->assertSame($chargeAfterFirst, $chargeAfterSecond);
    }

    private function studentClassRow(array $overrides): array
    {
        return array_merge([
            'StudentID' => 1,
            'GradeID' => 1,
            'SubjectID' => 66,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'TotalHours' => 8,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
        ], $overrides);
    }
}
