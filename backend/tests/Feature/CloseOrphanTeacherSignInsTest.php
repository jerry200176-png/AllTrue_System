<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * teacher-signin:close-orphans command 驗收測試
 *
 * AC 對應：
 *   AC-001  正常補登前日孤兒（SignOutDT = 23:59, Status = adjusted）
 *   AC-002  當日記錄不動
 *   AC-003  已有 SignOutDT 的記錄不重複處理
 *   AC-004  SignInDT >= 23:59 時 fallback SignInDT + 1hr
 */
class CloseOrphanTeacherSignInsTest extends TestCase
{
    use RefreshDatabase;

    private function insertSignIn(array $overrides = []): int
    {
        return DB::table('TeacherSingIn')->insertGetId(array_merge([
            'TeacherID' => 999,
            'CampusID'  => 1,
            'SignInDT'  => '2026-04-22 16:00:00',
            'SignOutDT' => null,
            'Status'    => 'source_only',
            'Source'    => 'rfid',
            'MDT'       => now(),
        ], $overrides));
    }

    // AC-001：前日孤兒應被補登 23:59
    public function test_previous_day_orphan_is_closed_at_2359(): void
    {
        Carbon::setTestNow('2026-04-23 00:10:00');

        $id = $this->insertSignIn(['SignInDT' => '2026-04-22 16:00:00']);

        $this->artisan('teacher-signin:close-orphans')->assertExitCode(0);

        $record = DB::table('TeacherSingIn')->where('id', $id)->first();
        $this->assertEquals('2026-04-22 23:59:00', $record->SignOutDT);
        $this->assertEquals('adjusted', $record->Status);
        $this->assertEquals('系統自動補登簽退', $record->Memo);

        Carbon::setTestNow();
    }

    // AC-002：當日記錄不動
    public function test_today_orphan_is_not_touched(): void
    {
        Carbon::setTestNow('2026-04-23 00:10:00');

        $id = $this->insertSignIn(['SignInDT' => '2026-04-23 16:00:00']);

        $this->artisan('teacher-signin:close-orphans')->assertExitCode(0);

        $record = DB::table('TeacherSingIn')->where('id', $id)->first();
        $this->assertNull($record->SignOutDT);

        Carbon::setTestNow();
    }

    // AC-003：已有 SignOutDT 的記錄不重複處理
    public function test_already_signed_out_record_is_not_modified(): void
    {
        Carbon::setTestNow('2026-04-23 00:10:00');

        $id = $this->insertSignIn([
            'SignInDT'  => '2026-04-22 16:00:00',
            'SignOutDT' => '2026-04-22 20:00:00',
            'Status'    => 'normal',
        ]);

        $this->artisan('teacher-signin:close-orphans')->assertExitCode(0);

        $record = DB::table('TeacherSingIn')->where('id', $id)->first();
        $this->assertEquals('2026-04-22 20:00:00', $record->SignOutDT);
        $this->assertEquals('normal', $record->Status);

        Carbon::setTestNow();
    }

    // AC-004：SignInDT >= 23:59 時 fallback SignInDT + 1hr
    public function test_late_signin_uses_fallback_plus_one_hour(): void
    {
        Carbon::setTestNow('2026-04-23 00:10:00');

        $id = $this->insertSignIn(['SignInDT' => '2026-04-22 23:59:30']);

        $this->artisan('teacher-signin:close-orphans')->assertExitCode(0);

        $record = DB::table('TeacherSingIn')->where('id', $id)->first();
        $this->assertEquals('2026-04-23 00:59:30', $record->SignOutDT);
        $this->assertEquals('adjusted', $record->Status);

        Carbon::setTestNow();
    }
}
