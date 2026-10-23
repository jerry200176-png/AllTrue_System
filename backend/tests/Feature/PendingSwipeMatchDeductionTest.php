<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\PackageSessionLedger;
use App\Models\PendingSwipe;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #4455 class D: matching an unmatched RFID swipe recorded the sign-in and set the lesson to attended but
 * never debited, so a package member's lesson was taught without a course- or package-ledger debit. The
 * match now debits through SessionDeductionService::deductOnAttendance(), like calendar attendance and
 * the RFID swipe, and the deduction trusts the ledger net over a stale SessionDeducted flag.
 */
class PendingSwipeMatchDeductionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-06 16:05:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_matched_swipe_debits_the_course_and_the_package_once(): void
    {
        [$pkg, $course, $lesson] = $this->packageLesson('scheduled');

        $this->match($this->pendingSwipe($course), $course, $lesson)->assertCreated();

        $this->assertSame('attended', (string) $lesson->fresh()->Status);
        $this->assertSame(1, SessionDeductionLedger::where('class_session_id', $lesson->id)->where('event_type', 'deduct')->count());
        $this->assertSame(1, SessionDeductionLedger::where('class_session_id', $lesson->id)->count());
        $this->assertSame(-1, (int) PackageSessionLedger::where('class_session_id', $lesson->id)->sum('delta'));
        $this->assertSame(1, PackageSessionLedger::where('class_session_id', $lesson->id)->count());
        $this->assertSame(9, (int) $pkg->refresh()->remaining_sessions);
        $this->assertTrue((bool) StudentSignIn::where('ClassSessionID', $lesson->id)->value('SessionDeducted'));
    }

    public function test_matching_twice_does_not_double_debit(): void
    {
        [$pkg, $course, $lesson] = $this->packageLesson('scheduled');

        $this->match($this->pendingSwipe($course), $course, $lesson)->assertCreated();
        $this->match($this->pendingSwipe($course), $course, $lesson)->assertStatus(409);

        $this->assertSame(1, StudentSignIn::where('ClassSessionID', $lesson->id)->count());
        $this->assertSame(1, SessionDeductionLedger::where('class_session_id', $lesson->id)->count());
        $this->assertSame(1, PackageSessionLedger::where('class_session_id', $lesson->id)->count());
        $this->assertSame(9, (int) $pkg->refresh()->remaining_sessions);
    }

    public function test_matching_a_lesson_already_debited_by_calendar_attendance_does_not_debit_again(): void
    {
        [$pkg, $course, $lesson] = $this->packageLesson('attended');
        $this->assertTrue(SessionDeductionService::deductOnAttendance($course, null, (int) $lesson->id));

        $this->match($this->pendingSwipe($course), $course, $lesson)->assertCreated();

        $this->assertSame(1, SessionDeductionLedger::where('class_session_id', $lesson->id)->count());
        $this->assertSame(1, PackageSessionLedger::where('class_session_id', $lesson->id)->count());
        $this->assertSame(9, (int) $pkg->refresh()->remaining_sessions);
    }

    public function test_matching_a_completed_lesson_keeps_todays_behaviour(): void
    {
        // Whether a `completed` lesson debits is a pending billing decision; the match must not decide it.
        [$pkg, $course, $lesson] = $this->packageLesson('completed');

        $this->match($this->pendingSwipe($course), $course, $lesson)->assertCreated();

        $this->assertSame('completed', (string) $lesson->fresh()->Status);
        $this->assertSame(0, SessionDeductionLedger::where('class_session_id', $lesson->id)->count());
        $this->assertSame(0, PackageSessionLedger::where('class_session_id', $lesson->id)->count());
        $this->assertSame(10, (int) $pkg->refresh()->remaining_sessions);
    }

    public function test_a_stale_session_deducted_flag_with_a_zero_ledger_net_is_debited(): void
    {
        [$pkg, $course, $lesson] = $this->packageLesson('attended');
        $signIn = $this->signIn($course, $lesson, true);

        $this->assertTrue(SessionDeductionService::deductOnAttendance($course, $signIn));

        $this->assertSame(1, SessionDeductionLedger::where('class_session_id', $lesson->id)->where('event_type', 'deduct')->count());
        $this->assertSame(-1, (int) PackageSessionLedger::where('class_session_id', $lesson->id)->sum('delta'));
        $this->assertSame(9, (int) $pkg->refresh()->remaining_sessions);

        // A flag backed by a real debit still short-circuits.
        $this->assertFalse(SessionDeductionService::deductOnAttendance($course, $signIn->fresh()));
        $this->assertSame(1, SessionDeductionLedger::where('class_session_id', $lesson->id)->count());
    }

    /** @return array{0: CoursePackage, 1: StudentClass, 2: ClassSession} */
    private function packageLesson(string $status): array
    {
        $student = Student::create([
            'name' => '刷卡配對學生' . uniqid(), 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $pkg = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1, 'name' => '刷卡配對方案',
            'total_sessions' => 10, 'remaining_sessions' => 10, 'used_sessions' => 0,
            'rate' => 500, 'rate_unit' => 'session', 'class_type' => 'one_on_one',
            'paid' => false, 'stop' => false, 'enabled' => true,
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-08-06', 'TotalHours' => 0,
            'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Rate' => 500, 'rate_unit' => 'session',
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 10, 'UsedSessions' => 0,
            'ClassType' => 'one_on_one', 'week' => 4, 'time' => '16:00:00',
            'PackageID' => $pkg->id, 'PackageTotalSessions' => 10, 'PackageName' => $pkg->name,
        ]);
        $lesson = ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => '2026-08-06',
            'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => $status,
        ]);

        return [$pkg, $course, $lesson];
    }

    private function pendingSwipe(StudentClass $course): PendingSwipe
    {
        return PendingSwipe::create([
            'RFID' => 'R' . random_int(100000, 999999), 'StudentID' => $course->StudentID,
            'CampusID' => 1, 'SwipeAt' => '2026-08-06 16:02:00', 'Reason' => 'no_course',
        ]);
    }

    private function signIn(StudentClass $course, ClassSession $lesson, bool $deducted): StudentSignIn
    {
        return StudentSignIn::create([
            'StudentClassID' => $course->ID, 'StudentID' => $course->StudentID, 'TeacherID' => 99,
            'GradeID' => 1, 'SubjectID' => 1, 'Get1byID' => 1, 'SignInDT' => '2026-08-06 16:02:00',
            'MDT' => now(), 'ClassSessionID' => $lesson->id, 'Status' => 'present',
            'CampusID' => 1, 'PersonType' => 'student', 'SessionDeducted' => $deducted,
        ]);
    }

    private function match(PendingSwipe $swipe, StudentClass $course, ClassSession $lesson)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->directorToken(),
            'Accept' => 'application/json',
        ])->postJson("/api/v1/pending-swipes/{$swipe->id}/match", [
            'StudentClassID' => $course->ID,
            'ClassSessionID' => $lesson->id,
        ]);
    }

    private function directorToken(): string
    {
        $user = User::create([
            'LoginName' => 'dir-swipe-' . uniqid() . '@example.com', 'Name' => 'dir', 'PSW' => 'x', 'type' => 'A',
            'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }
}
