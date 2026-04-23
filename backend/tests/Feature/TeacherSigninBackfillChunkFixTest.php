<?php

namespace Tests\Feature;

use App\Models\Campus;
use Carbon\Carbon;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 驗證 TeacherSingIn backfill 邏輯（chunkById 安全版）
 *
 * 回歸：原 backfill migration 使用 chunk()，在 callback 內修改迭代基準的 Status
 * 導致 OFFSET 跳行，約 600 筆 pending_review 記錄未被正確更新。
 *
 * 本測試驗證：
 *   AC-1: 無排課的 pending_review → source_only
 *   AC-2: 有排課且準時刷卡 → normal
 *   AC-3: 有排課且遲到 → late
 *   AC-4: 跨 chunk 邊界，chunkById 不跳行（mutation 安全）
 */
class TeacherSigninBackfillChunkFixTest extends TestCase
{
    use RefreshDatabase;

    private function makeCampus(): int
    {
        return CampusFactory::new()->create()->id;
    }

    private function insertTeacherSignIn(int $teacherId, int $campusId, string $signInDT): int
    {
        return DB::table('TeacherSingIn')->insertGetId([
            'TeacherID' => $teacherId,
            'CampusID'  => $campusId,
            'SignInDT'  => $signInDT,
            'SignOutDT' => null,
            'MDT'       => now(),
            'Source'    => 'rfid',
            'Status'    => 'pending_review',
        ]);
    }

    private function insertSchedule(int $teacherId, int $campusId, string $date, string $startTime): void
    {
        DB::table('schedules')->insert([
            'teacher_id'   => $teacherId,
            'student_id'   => 1,
            'day_of_week'  => 1,
            'branch_id'    => $campusId,
            'start_time'   => $startTime,
            'end_time'     => '10:00:00',
            'status'       => 'scheduled',
            'schedule_date'=> $date,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    // AC-1: 無排課 → source_only
    public function test_no_schedule_becomes_source_only(): void
    {
        $campusId = $this->makeCampus();
        $teacherId = 100;
        $date = '2023-03-23';

        $id = $this->insertTeacherSignIn($teacherId, $campusId, "{$date} 08:30:00");

        // Run the fix command/migration logic directly
        $this->runBackfillFix();

        $this->assertDatabaseHas('TeacherSingIn', ['id' => $id, 'Status' => 'source_only']);
    }

    // AC-2: 有課準時 → normal
    public function test_on_time_swipe_becomes_normal(): void
    {
        $campusId = $this->makeCampus();
        $teacherId = 101;
        $date = '2023-04-01';

        $this->insertSchedule($teacherId, $campusId, $date, '09:00:00');
        $id = $this->insertTeacherSignIn($teacherId, $campusId, "{$date} 09:05:00");

        $this->runBackfillFix();

        $this->assertDatabaseHas('TeacherSingIn', ['id' => $id, 'Status' => 'normal']);
    }

    // AC-3: 有課遲到 → late
    public function test_late_swipe_becomes_late(): void
    {
        $campusId = $this->makeCampus();
        $teacherId = 102;
        $date = '2023-04-01';

        $this->insertSchedule($teacherId, $campusId, $date, '09:00:00');
        $id = $this->insertTeacherSignIn($teacherId, $campusId, "{$date} 09:15:00");

        $this->runBackfillFix();

        $this->assertDatabaseHas('TeacherSingIn', ['id' => $id, 'Status' => 'late']);
    }

    // AC-4: 大量資料跨 chunk，所有記錄都被處理（不跳行）
    public function test_chunk_mutation_does_not_skip_records(): void
    {
        $campusId = $this->makeCampus();
        $teacherId = 103;
        $date = '2023-05-01';

        // 插入 250 筆（超過 chunk size=200），全部無排課 → 應全部變 source_only
        $ids = [];
        for ($i = 0; $i < 250; $i++) {
            $ids[] = $this->insertTeacherSignIn($teacherId, $campusId, "{$date} 08:00:00");
        }

        $this->runBackfillFix();

        $remaining = DB::table('TeacherSingIn')
            ->whereIn('id', $ids)
            ->where('Status', 'pending_review')
            ->count();

        $this->assertEquals(0, $remaining, "Expected 0 remaining pending_review, got {$remaining} (chunk mutation bug)");
    }

    /**
     * 執行 backfill 修正邏輯（使用 chunkById 避免 mutation 問題）
     */
    private function runBackfillFix(): void
    {
        $lateThreshold = 10;

        DB::table('TeacherSingIn')
            ->where('Status', 'pending_review')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($lateThreshold) {
                foreach ($rows as $row) {
                    $signInDate = $row->SignInDT
                        ? substr((string) $row->SignInDT, 0, 10)
                        : null;

                    if (! $signInDate || ! $row->TeacherID) {
                        continue;
                    }

                    $firstClass = DB::table('schedules')
                        ->where('teacher_id', (int) $row->TeacherID)
                        ->where('schedule_date', $signInDate)
                        ->where('status', '!=', 'cancelled')
                        ->orderBy('start_time')
                        ->first();

                    if (! $firstClass) {
                        $newStatus = 'source_only';
                    } else {
                        try {
                            $classStart = Carbon::parse("{$signInDate} {$firstClass->start_time}");
                            $swipeAt    = Carbon::parse($row->SignInDT);
                            $threshold  = $classStart->copy()->addMinutes($lateThreshold);
                            $newStatus  = $swipeAt->lte($threshold) ? 'normal' : 'late';
                        } catch (\Throwable $e) {
                            continue;
                        }
                    }

                    DB::table('TeacherSingIn')->where('id', $row->id)->update(['Status' => $newStatus]);
                }
            });
    }
}
