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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * In-app #406: saving a course whose teacher, class type, room and weekly slots are
 * unchanged must not be blocked by the recurring capacity guard, even when the slot is
 * already full. Changing the slot is still checked.
 */
class StudentClassUpdateNoAddedLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\DB::table('Subject')->where('Subject_Name', 'Math')->where('id', '<>', 1)->update(['Subject_Name' => 'Math (legacy)']); \Illuminate\Support\Facades\DB::table('Subject')->updateOrInsert(['id' => 1], ['School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => 'Math']); // fixtures use SubjectID 1 + subject=Math (ids are not reset between tests)
        Carbon::setTestNow(Carbon::parse('2026-04-12 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unchanged_schedule_saves_even_when_the_slot_is_already_full(): void
    {
        [$token, $course] = $this->seedDoubleBookedSlot();

        $this->update($token, $course, '15:00')->assertOk();
    }

    public function test_moving_into_the_occupied_slot_is_still_rejected(): void
    {
        [$token, $course] = $this->seedDoubleBookedSlot();

        $this->update($token, $course, '15:30')
            ->assertStatus(409)
            ->assertJsonPath('code', 'teacher_schedule_conflict');
    }

    public function test_a_course_that_held_no_seat_is_still_checked(): void
    {
        [$token, $course] = $this->seedDoubleBookedSlot();
        $course->forceFill(['RemainingSessions' => 0, 'UsedSessions' => 8])->save();

        $this->update($token, $course, '15:00')
            ->assertStatus(409)
            ->assertJsonPath('code', 'teacher_schedule_conflict');
    }

    /** #4098: same multi-slot set in a different array order still adds no load on a full slot. */
    public function test_reordered_unchanged_multi_slots_save_when_slot_is_full(): void
    {
        [$token, $course] = $this->seedDoubleBookedMultiSlot();

        // Stored order is Mon then Sun (week/week1); resubmit Sun then Mon.
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", [
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'duration_hours' => 2,
            'payment_type' => 'session',
            'days_of_week' => [7, 1],
            'start_time' => '15:00',
            'day_time_slots' => [
                ['day' => 7, 'start_time' => '15:00', 'duration_minutes' => 120],
                ['day' => 1, 'start_time' => '15:00', 'duration_minutes' => 120],
            ],
            'first_class_date' => '2026-03-01',
        ])->assertOk();
    }

    /**
     * The in-app #406 action itself: switch an existing count course on a full slot to 月結.
     * CourseManagement.vue submitEdit() counts a payment-type change as a schedule change
     * (scheduleFingerprintForEdit includes the mode), so the PUT re-sends the unchanged weekly
     * slot with end_date and force_rebuild_if_mismatch. The course already holds this seat.
     *
     * Rules that can still refuse this save, kept out of the fixture on purpose:
     * - BillingContractLockGuard (422 billing_contract_locked): after the first deduction ledger
     *   row, changing SessionCount / standard_lesson_minutes / deduction_basis is refused. This
     *   course has no ledger row and the payload re-sends the same sessions_purchased.
     * - MonthlyContractBoundaryService (422 monthly_paid_period_extension_requires_renewal):
     *   only for a course that is already 月結 and has collected money; this one is a count course.
     */
    public function test_switching_a_full_slot_course_to_monthly_saves_and_keeps_its_lessons(): void
    {
        [$token, $course] = $this->seedDoubleBookedSlot();
        // The lessons the count course materialized on its Sunday 15:00 slot from StartDate.
        // The first one must be on StartDate, otherwise force_rebuild_if_mismatch rebuilds them.
        foreach (['2026-03-01', '2026-03-08', '2026-03-15', '2026-03-22', '2026-03-29', '2026-04-05', '2026-04-12', '2026-04-19'] as $date) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => '15:00:00',
                'EndTime' => '17:00:00',
                // setUp() freezes today at 2026-04-12 08:00, so earlier lessons have ended.
                'Status' => $date < '2026-04-12' ? 'completed' : 'scheduled',
            ]);
        }
        $lessonsBefore = $this->lessonRows($course);
        $templateRowsBefore = DB::table('schedules')->where('student_course_id', $course->ID)->count();

        // The submitEdit() body after 繳費方式 changes from 堂數制 to 月結 (monthly_sessions is the
        // form's estimate for April: 4/5, 4/12, 4/19).
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", [
            'subject' => 'Math',
            'teacher_id' => 99,
            'class_type' => 'one_on_one',
            'rate_per_30min' => 500,
            'rate_unit' => 'session',
            'sessions_purchased' => 8,
            'remaining_sessions' => 8,
            'payment_type' => 'monthly',
            'scheduling_policy' => 'auto_recurrence',
            'settlement_day' => 5,
            'monthly_sessions' => 3,
            'room_id' => null,
            'Memo' => null,
            'duration_hours' => 2,
            'days_of_week' => [7],
            'start_time' => '15:00',
            'day_time_slots' => [['day' => 7, 'start_time' => '15:00', 'duration_minutes' => 120]],
            'end_time' => '17:00',
            'first_class_date' => '2026-03-01',
            'end_date' => '2026-04-19',
            'force_rebuild_if_mismatch' => true,
        ])->assertOk()
            ->assertJsonPath('billing_mode_conversion.old_mode', 'count')
            ->assertJsonPath('billing_mode_conversion.new_mode', 'date');

        $fresh = $course->fresh();
        // payment_type=monthly is stored as ScheduleMode=date (index() maps it back to 'monthly').
        $this->assertSame('date', (string) $fresh->ScheduleMode);
        $this->assertSame('2026-04-19', substr((string) $fresh->EndDate, 0, 10));
        $this->assertSame(5, (int) $fresh->settlement_day);
        $this->assertSame(3, (int) $fresh->monthly_sessions);
        // Billing-only change: same weekly slot, and no lesson added, moved or removed.
        $this->assertSame(7, (int) $fresh->week);
        $this->assertSame('15:00', substr((string) $fresh->time, 0, 5));
        $this->assertNull($fresh->week1);
        $this->assertSame(120, (int) $fresh->SessionDuration);
        $this->assertSame($lessonsBefore, $this->lessonRows($course));
        $this->assertSame($templateRowsBefore, DB::table('schedules')->where('student_course_id', $course->ID)->count());
    }

    /** @return array<int, string> one "id|date|start|end|status" line per ClassSession row */
    private function lessonRows(StudentClass $course): array
    {
        return DB::table('ClassSession')
            ->where('StudentClassID', $course->ID)
            ->orderBy('id')
            ->get(['id', 'SessionDate', 'StartTime', 'EndTime', 'Status'])
            ->map(fn ($row) => implode('|', [
                (int) $row->id,
                substr((string) $row->SessionDate, 0, 10),
                substr((string) $row->StartTime, 0, 5),
                substr((string) $row->EndTime, 0, 5),
                (string) $row->Status,
            ]))
            ->all();
    }

    private function update(string $token, StudentClass $course, string $start)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", [
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'duration_hours' => 2,
            'payment_type' => 'session',
            'days_of_week' => [7],
            'start_time' => $start,
            'day_time_slots' => [['day' => 7, 'start_time' => $start, 'duration_minutes' => 120]],
            'first_class_date' => '2026-03-01',
        ]);
    }

    /** @return array{0: string, 1: StudentClass} */
    private function seedDoubleBookedSlot(): array
    {
        $token = $this->createDirectorToken([1]);
        $courses = [];
        foreach (['滿堂既有學生甲', '滿堂既有學生乙'] as $name) {
            $student = Student::create([
                'name' => $name,
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);
            $courses[] = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => 99,
                'ClassType' => 'one_on_one',
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-03-01',
                'TotalHours' => 16,
                'Charge' => 0,
                'Paid' => 0,
                'Rate' => 500,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 8,
                'SessionDuration' => 120,
                'RemainingSessions' => 8,
                'UsedSessions' => 0,
                'week' => 7,
                'time' => '15:00:00',
            ]);
        }

        return [$token, $courses[0]];
    }

    /** Two one-on-one seats on Mon 15:00 and Sun 15:00 so both slots are full. */
    /** @return array{0: string, 1: StudentClass} */
    private function seedDoubleBookedMultiSlot(): array
    {
        $token = $this->createDirectorToken([1]);
        $courses = [];
        foreach (['滿堂複堂學生甲', '滿堂複堂學生乙'] as $name) {
            $student = Student::create([
                'name' => $name,
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);
            $courses[] = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => 99,
                'ClassType' => 'one_on_one',
                'by1' => 1,
                'Period' => 4,
                'StartDate' => '2026-03-01',
                'TotalHours' => 16,
                'Charge' => 0,
                'Paid' => 0,
                'Rate' => 500,
                'MDate' => now(),
                'Stop' => 0,
                'ScheduleMode' => 'count',
                'SessionCount' => 8,
                'SessionDuration' => 120,
                'RemainingSessions' => 8,
                'UsedSessions' => 0,
                'week' => 1,
                'time' => '15:00:00',
                'week1' => 7,
                'time1' => '15:00:00',
            ]);
        }

        return [$token, $courses[0]];
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director-no-added-load-' . bin2hex(random_bytes(4)) . '@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}
