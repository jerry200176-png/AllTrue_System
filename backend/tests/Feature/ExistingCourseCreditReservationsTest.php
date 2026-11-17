<?php

namespace Tests\Feature;

use App\Models\{ClassSession, LearningRecord, Student, StudentClass};
use App\Services\Billing\ExistingCourseCreditReservations;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SubjectCreditFixtures;
use Tests\TestCase;

class ExistingCourseCreditReservationsTest extends TestCase
{
    use RefreshDatabase;
    use SubjectCreditFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_preview_offers_only_unrecorded_future_tail_and_does_not_cancel_or_repair_anything(): void
    {
        [, $source] = $this->fixture();
        $early = $this->lesson($source, '2026-10-01');
        $late = $this->lesson($source, '2026-10-08');
        $recorded = $this->lesson($source, '2026-10-15');
        LearningRecord::create(['StudentClassID' => $source->ID, 'ClassSessionID' => $recorded->id,
            'TeacherID' => $source->TeacherID, 'Content' => '', 'Subject' => 'synthetic', 'SessionDate' => '2026-10-15',
            'StartTime' => '09:00', 'EndTime' => '11:00', 'Status' => 'pending']);
        $dated = $this->lesson($source, '2026-10-22');
        DB::table('schedules')->insert(['student_id' => $source->StudentID, 'student_course_id' => $source->ID,
            'teacher_id' => $source->TeacherID, 'branch_id' => Student::findOrFail($source->StudentID)->CampusID, 'subject' => 'synthetic', 'day_of_week' => 4,
            'schedule_date' => '2026-10-22', 'start_time' => '09:00', 'end_time' => '11:00',
            'duration_hours' => 2, 'status' => 'cancelled', 'type' => 'normal']);
        $before = $this->businessSnapshot();
        $rows = ClassSession::where('StudentClassID', $source->ID)->orderBy('id')->lockForUpdate()->get();
        $eligible = app(ExistingCourseCreditReservations::class)->eligibleLocked($source, $rows);
        $this->assertSame([(int) $late->id, (int) $early->id], $eligible->pluck('id')->all());
        $this->assertNotContains($dated->id, $eligible->pluck('id')->all(), 'Any dated history blocks release, even a cancelled booking.');
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_started_marked_nonstandard_or_differently_priced_lessons_are_not_free_credit(): void
    {
        [, $source] = $this->fixture();
        $this->lesson($source, '2026-09-30'); // already started, even though it ends after now
        foreach (['leave', 'cancelled', 'attended', 'completed', 'late', 'absent'] as $i => $status) {
            $this->lesson($source, '2026-10-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), ['Status' => $status]);
        }
        $this->lesson($source, '2026-10-10', ['EndTime' => '12:00']);
        $this->lesson($source, '2026-10-11', ['session_charge' => 1900]);
        $valid = $this->lesson($source, '2026-10-12', ['session_charge' => 1500]);
        $rows = ClassSession::where('StudentClassID', $source->ID)->orderBy('id')->lockForUpdate()->get();
        $this->assertSame([(int) $valid->id], app(ExistingCourseCreditReservations::class)->eligibleLocked($source, $rows)->pluck('id')->all());
    }

    public function test_a_future_two_hour_lesson_across_midnight_keeps_its_exact_contract_unit(): void
    {
        [, $source] = $this->fixture();
        $lesson = $this->lesson($source, '2026-10-02', ['StartTime' => '23:00', 'EndTime' => '01:00']);
        $this->assertSame([(int) $lesson->id], app(ExistingCourseCreditReservations::class)
            ->eligibleLocked($source, collect([$lesson]))->pluck('id')->all());
    }

    public function test_a_mixed_course_collection_cannot_offer_another_courses_reservations(): void
    {
        [, $source, $target] = $this->fixture();
        $foreign = $this->lesson($target, '2026-10-02');
        $this->expectException(\LogicException::class);
        app(ExistingCourseCreditReservations::class)->eligibleLocked($source, collect([$foreign]));
    }

    private function lesson(StudentClass $course, string $date, array $changes = []): ClassSession
    {
        $id = DB::table('ClassSession')->insertGetId(array_replace(['StudentClassID' => $course->ID,
            'SessionDate' => $date, 'StartTime' => '09:00', 'EndTime' => '11:00', 'Status' => 'scheduled'], $changes));
        return ClassSession::findOrFail($id);
    }
}
