<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TD-062 Phase 2 characterization：鎖定 `/class-sessions` 的 start/end 日期視窗為
 * 「閉區間 [start, end]（含端點）」。此測試保護 `whereDate(cs.SessionDate,...)`
 * 改寫為索引友善的裸欄位 range（`SessionDate` 為 DATE 欄位，語意 byte-identical）。
 */
class ClassSessionDateWindowFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_end_window_is_inclusive_and_excludes_outside(): void
    {
        $base = Carbon::parse('2026-03-15'); // 固定中月日期，避免月初/今日耦合
        [$token, $scId] = $this->seedCourse();

        $dates = [
            'before' => $base->copy()->subDays(2)->toDateString(), // 03-13（窗外）
            'start'  => $base->copy()->subDay()->toDateString(),    // 03-14（端點）
            'mid'    => $base->copy()->toDateString(),              // 03-15
            'end'    => $base->copy()->addDay()->toDateString(),    // 03-16（端點）
            'after'  => $base->copy()->addDays(2)->toDateString(),  // 03-17（窗外）
        ];
        foreach ($dates as $d) {
            ClassSession::create([
                'StudentClassID' => $scId,
                'SessionDate'    => $d,
                'StartTime'      => '16:00',
                'EndTime'        => '18:00',
                'Status'         => 'scheduled',
                'Note'           => '',
            ]);
        }

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson("/api/v1/class-sessions?branch_id=1&student_class_id={$scId}"
            . "&start={$dates['start']}&end={$dates['end']}&per_page=100");
        $res->assertOk();

        $returned = collect($res->json('data') ?? [])
            ->pluck('session_date')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            [$dates['start'], $dates['mid'], $dates['end']],
            $returned,
            '日期視窗應為閉區間 [start, end]：含兩端點、排除前後一天'
        );
    }

    /** @return array{0:string,1:int} [directorToken, studentClassId] */
    private function seedCourse(): array
    {
        $teacherId = (int) User::create([
            'LoginName' => 'dw-t' . uniqid() . '@e.com', 'Name' => 'T', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0900000000', 'MustChangePassword' => false,
        ])->id;
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacherId, 'Admin' => 0, 'Approved' => 1]);

        $dirId = (int) User::create([
            'LoginName' => 'dw-d' . uniqid() . '@e.com', 'Name' => 'D', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0900000000', 'MustChangePassword' => false,
        ])->id;
        UserCampus::create(['CampusID' => 1, 'UserID' => $dirId, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dirId, 'token' => $token, 'expires_at' => now()->addDay()]);

        $student = Student::create([
            'name' => '視窗' . uniqid(), 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);

        $sc = StudentClass::create([
            'StudentID' => $student->id, 'TeacherID' => $teacherId, 'GradeID' => 1, 'SubjectID' => 1,
            'ClassType' => 'one_on_one', 'ScheduleMode' => 'count',
            'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'UsedSessions' => 0,
            'Rate' => 500, 'TotalHours' => 20, 'Charge' => 5000, 'Pay' => 5000, 'Paid' => 0,
            'Stop' => 0, 'by1' => 0, 'MDate' => now(),
            'StartDate' => '2026-03-01', 'EndDate' => '2026-06-30',
        ]);

        return [$token, (int) $sc->ID];
    }
}
