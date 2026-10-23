<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\PackageSessionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\PackageDeductionService;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #4275 / in-app 419: a lesson marked attended, reverted, then marked attended again was
 * debited once on the course ledger (net-based since #1338) but not on the shared package
 * ledger (row/reason-based), so the pool showed one lesson fewer than was taught.
 * The package ledger now follows the same net rule; group slots stay debited once while any
 * member's lesson in the slot is charged.
 */
class PackageLedgerNetIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function makeStudent(): Student
    {
        return Student::create([
            'name' => '帳本學生' . uniqid(), 'CampusID' => 1,
            'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
    }

    private function makeTeacher(): User
    {
        return User::create([
            'LoginName' => 'teacher-net-' . uniqid() . '@t.com',
            'Name' => '帳本老師', 'PSW' => 's', 'type' => 'T', 'phone' => 911000000,
        ]);
    }

    private function makePackage(int $studentId, string $classType, int $total = 10): CoursePackage
    {
        return CoursePackage::create([
            'student_id' => $studentId, 'campus_id' => 1, 'name' => '淨額測試方案',
            'total_sessions' => $total, 'remaining_sessions' => $total, 'used_sessions' => 0,
            'rate' => 500, 'rate_unit' => 'session', 'class_type' => $classType,
            'paid' => false, 'stop' => false, 'enabled' => true,
        ]);
    }

    private function makeMember(CoursePackage $pkg, int $studentId, int $teacherId, string $classType): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $teacherId, 'by1' => 1, 'Period' => 4,
            'StartDate' => Carbon::today()->toDateString(), 'TotalHours' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => $pkg->total_sessions,
            'RemainingSessions' => $pkg->total_sessions, 'UsedSessions' => 0, 'SessionDuration' => 120,
            'ClassType' => $classType, 'Rate' => 500, 'rate_unit' => 'session',
            'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 0,
            'PackageID' => $pkg->id, 'PackageTotalSessions' => $pkg->total_sessions, 'PackageName' => $pkg->name,
        ]);
    }

    private function makeSession(int $studentClassId, string $date = '2026-04-16'): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $studentClassId, 'SessionDate' => $date,
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
        ]);
    }

    private function sessionNet(int $packageId, int $classSessionId): int
    {
        return (int) PackageSessionLedger::where('package_id', $packageId)
            ->where('class_session_id', $classSessionId)
            ->sum('delta');
    }

    /** @return array{0: CoursePackage, 1: StudentClass} */
    private function oneOnOnePackage(int $total = 10): array
    {
        $student = $this->makeStudent();
        $pkg = $this->makePackage($student->id, 'one_on_one', $total);

        return [$pkg, $this->makeMember($pkg, $student->id, $this->makeTeacher()->id, 'one_on_one')];
    }

    public function test_reattend_after_revert_debits_the_package_again(): void
    {
        [$pkg, $sc] = $this->oneOnOnePackage(10);
        $session = $this->makeSession($sc->ID);

        $this->assertTrue(SessionDeductionService::deductForSession($sc->ID, $session->id, 'attendance'));
        $this->assertTrue(SessionDeductionService::reverseForSession($sc->ID, $session->id, 'status_adjust'));
        $this->assertTrue(SessionDeductionService::deductForSession($sc->ID, $session->id, 'attendance'));

        $pkg->refresh();
        $this->assertSame(-1, $this->sessionNet($pkg->id, $session->id));
        $this->assertSame(9, (int) $pkg->remaining_sessions);
        $this->assertSame(1, (int) $pkg->used_sessions);
        $this->assertEqualsCanonicalizing(
            ['attendance', 'status_adjust', 'attendance#2'],
            PackageSessionLedger::where('package_id', $pkg->id)->pluck('reason')->all()
        );
    }

    public function test_repeated_revert_and_reattend_cycles_keep_exactly_one_debit(): void
    {
        [$pkg, $sc] = $this->oneOnOnePackage(10);
        $session = $this->makeSession($sc->ID);

        SessionDeductionService::deductForSession($sc->ID, $session->id, 'attendance');
        for ($cycle = 0; $cycle < 3; $cycle++) {
            SessionDeductionService::reverseForSession($sc->ID, $session->id, 'status_adjust');
            SessionDeductionService::deductForSession($sc->ID, $session->id, 'attendance');
        }

        $pkg->refresh();
        $this->assertSame(-1, $this->sessionNet($pkg->id, $session->id));
        $this->assertSame(9, (int) $pkg->remaining_sessions);
        $this->assertSame(7, PackageSessionLedger::where('package_id', $pkg->id)->count());
        $this->assertSame(
            PackageSessionLedger::where('package_id', $pkg->id)->count(),
            PackageSessionLedger::where('package_id', $pkg->id)->distinct()->count('reason'),
            'each cycle uses its own reason code (unique key package_id, class_session_id, reason)'
        );

        SessionDeductionService::reverseForSession($sc->ID, $session->id, 'status_adjust');
        $pkg->refresh();
        $this->assertSame(0, $this->sessionNet($pkg->id, $session->id));
        $this->assertSame(10, (int) $pkg->remaining_sessions);
    }

    public function test_double_deduct_and_double_reverse_stay_idempotent(): void
    {
        [$pkg, $sc] = $this->oneOnOnePackage(10);
        $session = $this->makeSession($sc->ID);

        $this->assertTrue(PackageDeductionService::deductForSession($pkg->id, $sc->ID, $session->id, 'attendance'));
        $this->assertFalse(PackageDeductionService::deductForSession($pkg->id, $sc->ID, $session->id, 'attendance'));
        $this->assertFalse(PackageDeductionService::deductForSession($pkg->id, $sc->ID, $session->id, 'retro_leave'));
        $this->assertSame(-1, $this->sessionNet($pkg->id, $session->id));

        $this->assertTrue(PackageDeductionService::reverseForSession($pkg->id, $sc->ID, $session->id, 'retro_leave'));
        $this->assertFalse(PackageDeductionService::reverseForSession($pkg->id, $sc->ID, $session->id, 'retro_leave'));
        $this->assertFalse(PackageDeductionService::reverseForSession($pkg->id, $sc->ID, $session->id, 'status_adjust'));
        $this->assertSame(0, $this->sessionNet($pkg->id, $session->id));
        $this->assertSame(2, PackageSessionLedger::where('package_id', $pkg->id)->count());
    }

    public function test_reverse_of_a_session_that_was_never_debited_writes_nothing(): void
    {
        [$pkg, $sc] = $this->oneOnOnePackage(10);
        $session = $this->makeSession($sc->ID);

        $this->assertFalse(PackageDeductionService::reverseForSession($pkg->id, $sc->ID, $session->id, 'status_adjust'));

        PackageDeductionService::recomputeCounters($pkg->id);
        $pkg->refresh();
        $this->assertSame(0, PackageSessionLedger::where('package_id', $pkg->id)->count());
        $this->assertSame(10, (int) $pkg->remaining_sessions);
    }

    public function test_group_slot_reattend_after_revert_debits_the_slot_again(): void
    {
        $student = $this->makeStudent();
        $pkg = $this->makePackage($student->id, 'one_on_two', 56);
        $sc = $this->makeMember($pkg, $student->id, $this->makeTeacher()->id, 'one_on_two');
        $session = $this->makeSession($sc->ID);

        SessionDeductionService::deductForSession($sc->ID, $session->id, 'attendance');
        SessionDeductionService::reverseForSession($sc->ID, $session->id, 'status_adjust');
        SessionDeductionService::deductForSession($sc->ID, $session->id, 'attendance');

        $pkg->refresh();
        $this->assertSame(-1, $this->sessionNet($pkg->id, $session->id));
        $this->assertSame(55, (int) $pkg->remaining_sessions);
    }

    public function test_group_slot_debit_moves_to_a_member_still_charged_and_never_over_credits(): void
    {
        $teacherId = $this->makeTeacher()->id;
        $owner = $this->makeStudent();
        $pkg = $this->makePackage($owner->id, 'one_on_two', 10);
        $first = $this->makeMember($pkg, $owner->id, $teacherId, 'one_on_two');
        $second = $this->makeMember($pkg, $this->makeStudent()->id, $teacherId, 'one_on_two');
        $firstSession = $this->makeSession($first->ID);
        $secondSession = $this->makeSession($second->ID);

        SessionDeductionService::deductForSession($first->ID, $firstSession->id, 'attendance');
        SessionDeductionService::deductForSession($second->ID, $secondSession->id, 'attendance');
        $pkg->refresh();
        $this->assertSame(9, (int) $pkg->remaining_sessions, 'one group lesson = one pool slot');

        // The member that does not carry the slot debit is reverted: no credit without a debit.
        SessionDeductionService::reverseForSession($second->ID, $secondSession->id, 'status_adjust');
        $pkg->refresh();
        $this->assertSame(9, (int) $pkg->remaining_sessions);
        $this->assertSame(0, $this->sessionNet($pkg->id, $secondSession->id));

        // Re-attend, then revert the carrier while the other member is still charged.
        SessionDeductionService::deductForSession($second->ID, $secondSession->id, 'attendance');
        SessionDeductionService::reverseForSession($first->ID, $firstSession->id, 'status_adjust');
        $pkg->refresh();
        $this->assertSame(9, (int) $pkg->remaining_sessions, 'slot stays debited while a member lesson in it is charged');
        $this->assertSame(0, $this->sessionNet($pkg->id, $firstSession->id));
        $this->assertSame(-1, $this->sessionNet($pkg->id, $secondSession->id));

        SessionDeductionService::reverseForSession($second->ID, $secondSession->id, 'status_adjust');
        $pkg->refresh();
        $this->assertSame(10, (int) $pkg->remaining_sessions, 'no member charged: the slot is free again');
    }
}
