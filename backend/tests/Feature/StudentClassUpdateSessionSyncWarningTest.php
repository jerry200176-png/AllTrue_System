<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Scheduling\ContractSessionSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #4435 (in-app #406 / #4099): the course-settings save answers 「部分堂次未同步」 only when an upcoming
 * lesson was really left off the saved slots by its status. Zero lessons moved because every lesson is
 * already in place is not a lock.
 */
class StudentClassUpdateSessionSyncWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('Subject')->where('Subject_Name', 'Math')->where('id', '<>', 1)->update(['Subject_Name' => 'Math (legacy)']);
        DB::table('Subject')->updateOrInsert(['id' => 1], ['School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => 'Math']); // fixtures use SubjectID 1 + subject=Math
        Carbon::setTestNow(Carbon::parse('2026-04-12 08:00:00', 'Asia/Taipei')); // a Sunday, before the 15:00 lesson
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The in-app #406 save: 堂數制 -> 月結 re-sends the unchanged Sunday 15:00 slot; nothing to move, no warning. */
    public function test_monthly_switch_without_history_returns_no_sync_warning(): void
    {
        [$token, $course] = $this->seedSundayCourse(withHistory: false);
        $before = $this->lessonRows($course);

        $sync = $this->switchToMonthly($token, $course)->json('session_sync');

        $this->assertSame(0, (int) ($sync['updated_future_sessions'] ?? -1));
        $this->assertSame(0, (int) ($sync['unaligned_future_sessions'] ?? -1));
        $this->assertArrayNotHasKey('warning', $sync);
        $this->assertArrayNotHasKey('reconcile_skipped', $sync);
        $this->assertSame($before, $this->lessonRows($course));
    }

    /**
     * Same switch on a course with attendance history whose upcoming lessons are locked (a signed-in lesson and a
     * pending leave) but already sit on the unchanged slot: they need no move, so there is nothing to warn about.
     */
    public function test_monthly_switch_with_locked_lessons_already_on_the_slot_returns_no_sync_warning(): void
    {
        [$token, $course] = $this->seedSundayCourse(withHistory: true, lockToday: true);
        $before = $this->lessonRows($course);

        $sync = $this->switchToMonthly($token, $course)->json('session_sync');

        $this->assertSame('history_exists', $sync['reason'] ?? '');
        $this->assertSame(0, (int) ($sync['updated_future_sessions'] ?? -1));
        $this->assertSame(0, (int) ($sync['unaligned_future_sessions'] ?? -1));
        $this->assertArrayNotHasKey('warning', $sync);
        $this->assertArrayNotHasKey('reconcile_skipped', $sync);
        $this->assertArrayNotHasKey('status_locked_future_sessions', $sync);
        $this->assertSame('date', (string) $course->fresh()->ScheduleMode);
        $this->assertSame($before, $this->lessonRows($course));
    }

    /**
     * The unchanged-slot switch leaves nothing behind, so a status-locked lesson that was already off the slot before
     * this save (e.g. an older one-off move without the exception flag) is not reported as this edit's doing.
     */
    public function test_monthly_switch_ignores_a_status_locked_lesson_that_was_already_off_the_slot(): void
    {
        [$token, $course] = $this->seedSundayCourse(withHistory: true);
        ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-04-16',
            'StartTime' => '18:00:00',
            'EndTime' => '20:00:00',
            'Status' => 'completed',
            'IsContractException' => 0,
        ]);
        $this->assertSame(1, app(ContractSessionSchedule::class)->countStatusLockedFutureContractSessions($course->fresh()));
        $before = $this->lessonRows($course);

        $sync = $this->switchToMonthly($token, $course)->json('session_sync');

        $this->assertSame(0, (int) ($sync['unaligned_future_sessions'] ?? -1));
        $this->assertArrayNotHasKey('warning', $sync);
        $this->assertArrayNotHasKey('reconcile_skipped', $sync);
        $this->assertArrayNotHasKey('status_locked_future_sessions', $sync);
        $this->assertSame($before, $this->lessonRows($course));
    }

    /**
     * A real move: 15:00 -> 13:00. Today's scheduled lesson moves; next week's lesson is status-locked (a pending
     * leave, or an attendance status such as 試聽 trial) and stays at 15:00, so the save still warns even though one
     * lesson was updated.
     *
     * @dataProvider statusLockedStatuses
     */
    public function test_status_locked_lesson_left_on_the_old_time_still_warns(string $nextWeekStatus): void
    {
        [$token, $course] = $this->seedSundayCourse(withHistory: true, nextWeekStatus: $nextWeekStatus);

        $res = $this->withHeaders($this->headers($token))->putJson("/api/v1/student-classes/{$course->ID}", [
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'payment_type' => 'session',
            'duration_hours' => 2,
            'days_of_week' => [7],
            'start_time' => '13:00',
            'day_time_slots' => [['day' => 7, 'start_time' => '13:00', 'duration_minutes' => 120]],
            'first_class_date' => '2026-03-01',
            'force_rebuild_if_mismatch' => true,
        ])->assertOk();

        $sync = $res->json('session_sync');
        $this->assertSame(1, (int) ($sync['updated_future_sessions'] ?? -1));
        $this->assertSame(0, (int) ($sync['unaligned_future_sessions'] ?? -1));
        $this->assertSame(1, (int) ($sync['status_locked_future_sessions'] ?? -1));
        $this->assertTrue($sync['reconcile_skipped'] ?? false);
        $this->assertStringContainsString('狀態鎖定', (string) ($sync['warning'] ?? ''));
        $this->assertSame('13:00:00', (string) $course->fresh()->time);
        $this->assertSame('13:00', $this->startOn($course, '2026-04-12'));
        $this->assertSame('15:00', $this->startOn($course, '2026-04-19'));
    }

    /** @return array<string, array{0: string}> */
    public function statusLockedStatuses(): array
    {
        return ['pending leave' => ['leave_requested'], 'trial attendance' => ['trial']];
    }

    /** Which rows count as a status-locked lesson left off the contract slot (Sunday 13:00-15:00 here). */
    public function test_status_locked_count_only_covers_upcoming_slot_holding_lessons(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-12 16:00:00', 'Asia/Taipei'));
        [, $course] = $this->seedSundayCourse(withHistory: false, withLessons: false);
        $course->forceFill(['time' => '13:00:00'])->save();
        $rows = [
            ['2026-04-12', '15:00', 'attended', 0],         // already started today: history, not counted
            ['2026-04-12', '18:00', 'attended', 0],         // later today, off the slot: counted
            ['2026-04-19', '15:00', 'leave_requested', 0],  // pending leave off the slot: counted
            ['2026-04-26', '15:00', 'cancelled', 0],        // holds no slot
            ['2026-05-03', '15:00', 'leave', 0],            // approved leave holds no slot
            ['2026-05-10', '15:00', 'completed', 1],        // contract exception: off the slot on purpose
            ['2026-05-17', '13:00', 'leave_requested', 0],  // on the slot
            ['2026-05-24', '15:00', 'scheduled', 0],        // movable: countUnalignedFutureContractSessions() covers it
            ['2026-05-31', '15:00', 'trial', 0],            // attendance status (AttendanceStatus::META): counted
            ['2026-06-07', '15:00', 'suspended', 0],        // 停課: off the contract on purpose
            ['2026-06-14', '15:00', 'rescheduled', 0],      // one-off move: off the contract on purpose
            ['2026-06-21', '15:00', 'new_status', 0],       // unknown status fails toward the warning: counted
        ];
        foreach ($rows as [$date, $start, $status, $exception]) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => "{$start}:00",
                'EndTime' => Carbon::parse("{$date} {$start}")->addHours(2)->format('H:i:s'),
                'Status' => $status,
                'IsContractException' => $exception,
            ]);
        }

        $schedule = app(ContractSessionSchedule::class);
        $this->assertSame(4, $schedule->countStatusLockedFutureContractSessions($course->fresh()));
        $this->assertSame(1, $schedule->countUnalignedFutureContractSessions($course->fresh()));
    }

    /** The CourseManagement.vue submitEdit() body after 繳費方式 changes from 堂數制 to 月結 (in-app #406). */
    private function switchToMonthly(string $token, StudentClass $course)
    {
        return $this->withHeaders($this->headers($token))->putJson("/api/v1/student-classes/{$course->ID}", [
            'subject' => 'Math',
            'teacher_id' => 99,
            'class_type' => 'one_on_one',
            'rate_per_30min' => 500,
            'rate_unit' => 'session',
            'sessions_purchased' => 8,
            'remaining_sessions' => (int) $course->RemainingSessions,
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
        ])->assertOk();
    }

    /**
     * A Sunday 15:00-17:00 count course from 2026-03-01 with its eight lessons: six past (completed), today's
     * scheduled one and next week's. With history, 03-08 was attended (a sign-in) and next week's lesson is in
     * $nextWeekStatus (a pending leave by default); lockToday also signs today's lesson in, which locks it.
     *
     * @return array{0: string, 1: StudentClass}
     */
    private function seedSundayCourse(
        bool $withHistory,
        bool $lockToday = false,
        bool $withLessons = true,
        string $nextWeekStatus = 'leave_requested'
    ): array
    {
        $token = $this->createDirectorToken();
        $student = Student::create([
            'name' => '同步提示測試學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
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
        if (!$withLessons) {
            return [$token, $course];
        }

        foreach (['2026-03-01', '2026-03-08', '2026-03-15', '2026-03-22', '2026-03-29', '2026-04-05', '2026-04-12', '2026-04-19'] as $date) {
            $status = $date < '2026-04-12' ? 'completed' : 'scheduled';
            if ($withHistory && $date === '2026-03-08') {
                $status = 'attended';
            } elseif ($withHistory && $date === '2026-04-19') {
                $status = $nextWeekStatus;
            }
            $session = ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => '15:00:00',
                'EndTime' => '17:00:00',
                'Status' => $status,
            ]);
            if ($withHistory && ($date === '2026-03-08' || ($lockToday && $date === '2026-04-12'))) {
                StudentSignIn::create([
                    'StudentClassID' => $course->ID,
                    'StudentID' => $student->id,
                    'TeacherID' => 99,
                    'GradeID' => 1,
                    'SubjectID' => 1,
                    'CampusID' => 1,
                    'SignInDT' => "{$date} 15:00:00",
                    'MDT' => now(),
                    'ClassSessionID' => $session->id,
                    'Status' => 'present',
                    'SessionDeducted' => $date === '2026-03-08' ? 1 : 0,
                ]);
            }
        }

        return [$token, $course];
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

    private function startOn(StudentClass $course, string $date): string
    {
        return substr((string) ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', $date)->value('StartTime'), 0, 5);
    }

    /** @return array<string, string> */
    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    private function createDirectorToken(): string
    {
        $user = User::create([
            'LoginName' => 'director-sync-warning-' . bin2hex(random_bytes(4)) . '@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $user->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}
