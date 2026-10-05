<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Services\Scheduling\ContractTeacherChangeCascade;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Direct interface test for the cascade moved out of StudentClassController (ADR-003 / #966).
 * End-to-end behaviour stays covered by ContractTeacherChangePreservesHistoryTest.
 */
class ContractTeacherChangeCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(string $date, int $teacher, array $extra = []): Schedule
    {
        return Schedule::create($extra + [
            'student_id' => 1,
            'teacher_id' => $teacher,
            'subject' => 'Math',
            'day_of_week' => 5,
            'start_time' => '16:00',
            'end_time' => '18:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => 1,
            'schedule_date' => $date,
            'student_course_id' => 700,
        ]);
    }

    public function test_future_contract_rows_follow_new_teacher_and_past_rows_stay(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 10:00:00', 'Asia/Taipei'));
        $past = $this->row('2026-07-17', 10);
        $future = $this->row('2026-07-24', 10);
        $other = $this->row('2026-07-24', 10, ['student_course_id' => 701]);

        ContractTeacherChangeCascade::syncFutureScheduleTeachersAfterContractTeacherChange(700, 10, 20);

        $this->assertSame(10, (int) $past->fresh()->teacher_id);
        $this->assertSame(20, (int) $future->fresh()->teacher_id);
        $this->assertSame(10, (int) $other->fresh()->teacher_id);
    }

    public function test_noop_when_teacher_unchanged_or_missing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 10:00:00', 'Asia/Taipei'));
        $future = $this->row('2026-07-24', 10);

        ContractTeacherChangeCascade::syncFutureScheduleTeachersAfterContractTeacherChange(700, 10, 10);
        ContractTeacherChangeCascade::syncFutureScheduleTeachersAfterContractTeacherChange(700, 0, 20);

        $this->assertSame(10, (int) $future->fresh()->teacher_id);
    }

    public function test_stale_substitute_target_is_dropped_with_its_anchor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 10:00:00', 'Asia/Taipei'));
        $anchor = $this->row('2026-07-24', 10, ['status' => 'rescheduled']);
        $target = $this->row('2026-07-25', 10, ['original_schedule_id' => $anchor->id]);

        ContractTeacherChangeCascade::syncFutureScheduleTeachersAfterContractTeacherChange(700, 10, 20);

        $this->assertNull(Schedule::find($target->id));
        $this->assertNull(Schedule::find($anchor->id));
    }
}
