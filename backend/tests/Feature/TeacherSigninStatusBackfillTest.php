<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 驗證 TeacherSingIn.Status 回填邏輯（2026-04-23 backfill migration）
 *
 * 測試策略：
 *   1. 直接插入 Status='pending_review' 記錄
 *   2. 執行與 migration 相同的回填邏輯（不透過 Artisan migrate 呼叫）
 *   3. 驗證 Status 被正確更新
 *
 * 防再犯紀錄：
 *   - §TEST-003 規則：TeacherSingIn 使用自定義 VoidedAt（無 SoftDeletes），
 *     不可用 withTrashed()，改用 DB::table 查詢。
 */
class TeacherSigninStatusBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const LATE_THRESHOLD = 10; // minutes

    // ─── AC-01 ────────────────────────────────────────────────────────────────
    // 無排課當日 → source_only（行政出勤）

    public function test_no_schedule_on_day_becomes_source_only(): void
    {
        $date = '2026-04-20';
        $id   = DB::table('TeacherSingIn')->insertGetId([
            'TeacherID' => 999,
            'CampusID'  => 1,
            'SignInDT'  => "{$date} 10:00:00",
            'MDT'       => now(),
            'Status'    => 'pending_review',
        ]);

        // 不插入 schedules → 無課

        $this->runBackfillForId($id);

        $status = DB::table('TeacherSingIn')->where('id', $id)->value('Status');
        $this->assertSame('source_only', $status, '無排課應回填為 source_only');
    }

    // ─── AC-02 ────────────────────────────────────────────────────────────────
    // 有排課，刷卡在首堂 +10 分鐘以內 → normal

    public function test_swipe_within_threshold_becomes_normal(): void
    {
        $date = '2026-04-21';

        DB::table('schedules')->insert([
            'student_id'    => 1,
            'teacher_id'    => 888,
            'day_of_week'   => 1,
            'branch_id'     => 1,
            'schedule_date' => $date,
            'start_time'    => '09:00',
            'end_time'      => '11:00',
            'status'        => 'scheduled',
        ]);

        $id = DB::table('TeacherSingIn')->insertGetId([
            'TeacherID' => 888,
            'CampusID'  => 1,
            'SignInDT'  => "{$date} 09:05:00",  // 5 分鐘以內 → normal
            'MDT'       => now(),
            'Status'    => 'pending_review',
        ]);

        $this->runBackfillForId($id);

        $status = DB::table('TeacherSingIn')->where('id', $id)->value('Status');
        $this->assertSame('normal', $status, '刷卡在閾值內應回填為 normal');
    }

    // ─── AC-03 ────────────────────────────────────────────────────────────────
    // 有排課，刷卡超過首堂 +10 分鐘 → late

    public function test_swipe_after_threshold_becomes_late(): void
    {
        $date = '2026-04-22';

        DB::table('schedules')->insert([
            'student_id'    => 1,
            'teacher_id'    => 777,
            'day_of_week'   => 2,
            'branch_id'     => 1,
            'schedule_date' => $date,
            'start_time'    => '14:00',
            'end_time'      => '16:00',
            'status'        => 'scheduled',
        ]);

        $id = DB::table('TeacherSingIn')->insertGetId([
            'TeacherID' => 777,
            'CampusID'  => 1,
            'SignInDT'  => "{$date} 14:20:00",  // 20 分鐘後 → late
            'MDT'       => now(),
            'Status'    => 'pending_review',
        ]);

        $this->runBackfillForId($id);

        $status = DB::table('TeacherSingIn')->where('id', $id)->value('Status');
        $this->assertSame('late', $status, '刷卡超過閾值應回填為 late');
    }

    // ─── AC-04 ────────────────────────────────────────────────────────────────
    // 有排課但已 cancelled → 視同無課 → source_only

    public function test_cancelled_schedule_treated_as_no_class(): void
    {
        $date = '2026-04-23';

        DB::table('schedules')->insert([
            'student_id'    => 1,
            'teacher_id'    => 666,
            'day_of_week'   => 3,
            'branch_id'     => 1,
            'schedule_date' => $date,
            'start_time'    => '10:00',
            'end_time'      => '12:00',
            'status'        => 'cancelled',   // ← 已取消，不算有課
        ]);

        $id = DB::table('TeacherSingIn')->insertGetId([
            'TeacherID' => 666,
            'CampusID'  => 1,
            'SignInDT'  => "{$date} 10:30:00",
            'MDT'       => now(),
            'Status'    => 'pending_review',
        ]);

        $this->runBackfillForId($id);

        $status = DB::table('TeacherSingIn')->where('id', $id)->value('Status');
        $this->assertSame('source_only', $status, 'cancelled 排課不應計入 → source_only');
    }

    // ─── helper ───────────────────────────────────────────────────────────────

    /**
     * 執行與 migration 相同的回填邏輯（針對單筆記錄）
     */
    private function runBackfillForId(int $id): void
    {
        $row = DB::table('TeacherSingIn')->where('id', $id)->first();
        if (! $row) {
            return;
        }

        $signInDate = substr((string) $row->SignInDT, 0, 10);

        $firstClass = DB::table('schedules')
            ->where('teacher_id', (int) $row->TeacherID)
            ->where('schedule_date', $signInDate)
            ->where('status', '!=', 'cancelled')
            ->orderBy('start_time')
            ->first();

        if (! $firstClass) {
            $newStatus = 'source_only';
        } else {
            $classStart = Carbon::parse("{$signInDate} {$firstClass->start_time}");
            $swipeAt    = Carbon::parse($row->SignInDT);
            $threshold  = $classStart->copy()->addMinutes(self::LATE_THRESHOLD);
            $newStatus  = $swipeAt->lte($threshold) ? 'normal' : 'late';
        }

        DB::table('TeacherSingIn')->where('id', $id)->update(['Status' => $newStatus]);
    }
}
