<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\SessionDeductionLedger;
use App\Models\StudentClass;
use App\Services\SessionDeductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #613 A1 PR2：分鐘制權威餘額引擎。
 * 核心安全保證：未含「部分時數」事件的課程，RemainingSessions / UsedSessions
 * 與舊 count-based 邏輯 byte-identical（golden）；只在 ledger 出現 minutes≠整堂的
 * 事件時，才切換為分鐘權威、RemainingSessions 改 ROUND_HALF_UP 衍生顯示。
 */
class SessionDeductionMinutesEngineTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourse(int $sessions, int $durationMinutes): StudentClass
    {
        return StudentClass::create([
            'StudentID'         => 990001,
            'GradeID'           => 1,
            'SubjectID'         => 1,
            'TeacherID'         => 990002,
            'by1'               => 1,
            'Period'            => 4,
            'StartDate'         => '2026-03-01',
            'TotalHours'        => 40,
            'Memo'              => '分鐘引擎測試',
            'Paid'              => 0,
            'Stop'              => 0,
            'ScheduleMode'      => 'count',
            'SessionCount'      => $sessions,
            'RemainingSessions' => $sessions,
            'UsedSessions'      => 0,
            'SessionDuration'   => $durationMinutes,
            'ClassType'         => 'one_on_one',
            'Rate'              => 500,
            'RoomID'            => 'R1',
            'MDate'             => now(),
        ]);
    }

    /** GOLDEN：整堂路徑（無部分事件）行為不變，且衍生分鐘欄正確。 */
    public function test_whole_session_path_unchanged_and_minutes_derived(): void
    {
        $course = $this->makeCourse(6, 120);
        $cs = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate'    => '2026-03-02',
            'StartTime'      => '16:00',
            'EndTime'        => '18:00',
            'Status'         => 'attended',
            'Note'           => '',
        ]);
        LearningRecord::create([
            'StudentClassID' => $course->ID,
            'ClassSessionID' => $cs->id,
            'TeacherID'      => $course->TeacherID,
            'Content'        => 'x',
            'Status'         => 'approved',
            'SessionDate'    => '2026-03-02',
            'StartTime'      => '16:00',
            'EndTime'        => '18:00',
        ]);
        SessionDeductionService::deductForSession($course->ID, $cs->id, 'attendance');
        SessionDeductionService::recomputeCounters($course->ID);

        $after = StudentClass::findOrFail($course->ID);
        $this->assertSame(5, (int) $after->RemainingSessions, '整堂扣 1 → 剩 5（與舊邏輯相同）');
        $this->assertSame(1, (int) $after->UsedSessions);
        $this->assertSame(720, (int) $after->PurchasedMinutes, '6×120');
        $this->assertSame(600, (int) $after->RemainingMinutes, '5×120');
    }

    /** 部分事件：60 分（半堂）扣除 → 分鐘權威，RemainingSessions 以 ROUND_HALF_UP 衍生。 */
    public function test_partial_minute_event_makes_fractional_remaining(): void
    {
        $course = $this->makeCourse(6, 120);
        SessionDeductionService::deductForSession($course->ID, 70001, 'attendance', null, null, 60);
        SessionDeductionService::recomputeCounters($course->ID);

        $after = StudentClass::findOrFail($course->ID);
        $this->assertSame(660, (int) $after->RemainingMinutes, '720 − 60 = 660（權威）');
        $this->assertSame(720, (int) $after->PurchasedMinutes);
        // ROUND_HALF_UP(660/120) = ROUND_HALF_UP(5.5) = 6；UsedSessions = 6 − 6 = 0
        $this->assertSame(6, (int) $after->RemainingSessions);
        $this->assertSame(0, (int) $after->UsedSessions);
        $this->assertSame(6, (int) ($after->RemainingSessions + $after->UsedSessions), 'used+remaining=count 不變式');
    }

    /** 部分事件：90 分扣除（720−90=630）→ ROUND_HALF_UP(630/120)=ROUND_HALF_UP(5.25)=5。 */
    public function test_partial_minute_event_rounds_half_up_down(): void
    {
        $course = $this->makeCourse(6, 120);
        SessionDeductionService::deductForSession($course->ID, 70002, 'attendance', null, null, 90);
        SessionDeductionService::recomputeCounters($course->ID);

        $after = StudentClass::findOrFail($course->ID);
        $this->assertSame(630, (int) $after->RemainingMinutes);
        $this->assertSame(5, (int) $after->RemainingSessions, 'ROUND_HALF_UP(5.25)=5');
        $this->assertSame(1, (int) $after->UsedSessions);
    }

    /** 部分扣除後再以部分還原 → 分鐘淨值回補，餘額回到整數堂。 */
    public function test_partial_reverse_restores_minutes(): void
    {
        $course = $this->makeCourse(6, 120);
        SessionDeductionService::deductForSession($course->ID, 70003, 'attendance', null, null, 60);
        SessionDeductionService::reverseForSession($course->ID, 70003, 'retro_leave', null, null, 60);
        SessionDeductionService::recomputeCounters($course->ID);

        $after = StudentClass::findOrFail($course->ID);
        $this->assertSame(720, (int) $after->RemainingMinutes, '扣 60 + 還 60 = 0 淨用');
        $this->assertSame(6, (int) $after->RemainingSessions);
        $this->assertSame(0, (int) $after->UsedSessions);
    }

    /** 部分扣除不可超扣：扣超過購買分鐘時 used 封頂、remaining 為 0。 */
    public function test_partial_minutes_capped_at_purchased(): void
    {
        $course = $this->makeCourse(1, 120);
        SessionDeductionService::deductForSession($course->ID, 70004, 'attendance', null, null, 200);
        SessionDeductionService::recomputeCounters($course->ID);

        $after = StudentClass::findOrFail($course->ID);
        $this->assertSame(0, (int) $after->RemainingMinutes);
        $this->assertSame(0, (int) $after->RemainingSessions);
        $this->assertSame(1, (int) $after->UsedSessions);
    }
}
