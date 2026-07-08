<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\PackageSessionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\PackageDeductionService;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackageConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function makeStudent(int $campusId = 1): Student
    {
        return Student::create([
            'name' => '並發學生' . uniqid(), 'CampusID' => $campusId,
            'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
    }

    private function makeTeacher(): User
    {
        return User::create([
            'LoginName' => 'teacher-cc-' . uniqid() . '@t.com',
            'Name' => '並發老師', 'PSW' => 's', 'type' => 'T', 'phone' => 911000000,
        ]);
    }

    private function makePackageWithMember(int $studentId, int $teacherId, int $total = 10): array
    {
        $pkg = CoursePackage::create([
            'student_id' => $studentId, 'campus_id' => 1, 'name' => '並發測試方案',
            'total_sessions' => $total, 'remaining_sessions' => $total, 'used_sessions' => 0,
            'rate' => 500, 'rate_unit' => 'session', 'class_type' => 'one_on_one',
            'paid' => false, 'stop' => false, 'enabled' => true,
        ]);

        $sc = StudentClass::create([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $teacherId, 'by1' => 1, 'Period' => 4,
            'StartDate' => Carbon::today()->toDateString(), 'TotalHours' => 0,             'ScheduleMode' => 'count', 'SessionCount' => $total,
            'RemainingSessions' => $total, 'UsedSessions' => 0, 'SessionDuration' => 120,
            'ClassType' => 'one_on_one', 'Rate' => 500, 'rate_unit' => 'session',
            'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 0,
            'PackageID' => $pkg->id, 'PackageTotalSessions' => $total, 'PackageName' => $pkg->name,
        ]);

        return [$pkg, $sc];
    }

    // ─── Idempotent: Same session deducted twice yields one ledger row ──

    public function test_duplicate_deduction_is_idempotent(): void
    {
        $student = $this->makeStudent();
        $teacher = $this->makeTeacher();
        [$pkg, $sc] = $this->makePackageWithMember($student->id, $teacher->id, 10);
        $session = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-16',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
        ]);

        $r1 = PackageDeductionService::deductForSession($pkg->id, $sc->ID, $session->id, 'attendance');
        $r2 = PackageDeductionService::deductForSession($pkg->id, $sc->ID, $session->id, 'attendance');

        $this->assertTrue($r1);
        $this->assertFalse($r2);
        $this->assertEquals(1, PackageSessionLedger::where('package_id', $pkg->id)->count());
    }

    // ─── Idempotent: Same session reversed twice yields one reverse row ──

    public function test_duplicate_reverse_is_idempotent(): void
    {
        $student = $this->makeStudent();
        $teacher = $this->makeTeacher();
        [$pkg, $sc] = $this->makePackageWithMember($student->id, $teacher->id, 10);
        $session = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-16',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
        ]);

        PackageDeductionService::deductForSession($pkg->id, $sc->ID, $session->id, 'attendance');
        $r1 = PackageDeductionService::reverseForSession($pkg->id, $sc->ID, $session->id, 'retro_leave');
        $r2 = PackageDeductionService::reverseForSession($pkg->id, $sc->ID, $session->id, 'retro_leave');

        $this->assertTrue($r1);
        $this->assertFalse($r2);

        PackageDeductionService::recomputeCounters($pkg->id);
        $pkg->refresh();
        $this->assertEquals(10, $pkg->remaining_sessions);
    }

    // ─── Rapid sequential deductions across different sessions ──

    public function test_rapid_deductions_across_sessions(): void
    {
        $student = $this->makeStudent();
        $teacher = $this->makeTeacher();
        [$pkg, $sc] = $this->makePackageWithMember($student->id, $teacher->id, 20);

        $sessions = [];
        for ($i = 0; $i < 5; $i++) {
            $sessions[] = ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => Carbon::today()->addDays($i)->toDateString(),
                'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
            ]);
        }

        foreach ($sessions as $s) {
            PackageDeductionService::deductForSession($pkg->id, $sc->ID, $s->id, 'attendance');
        }

        PackageDeductionService::recomputeCounters($pkg->id);
        $pkg->refresh();

        $this->assertEquals(15, $pkg->remaining_sessions);
        $this->assertEquals(5, $pkg->used_sessions);
        $this->assertEquals(5, PackageSessionLedger::where('package_id', $pkg->id)->count());
    }

    // ─── Deduction then reverse then re-deduct yields correct balance ──

    public function test_deduct_reverse_rededuct_sequence(): void
    {
        $student = $this->makeStudent();
        $teacher = $this->makeTeacher();
        [$pkg, $sc] = $this->makePackageWithMember($student->id, $teacher->id, 10);
        $session = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-16',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
        ]);

        PackageDeductionService::deductForSession($pkg->id, $sc->ID, $session->id, 'attendance');
        PackageDeductionService::reverseForSession($pkg->id, $sc->ID, $session->id, 'retro_leave');

        $wrote = PackageDeductionService::deductForSession($pkg->id, $sc->ID, $session->id, 'attendance');
        $this->assertFalse($wrote, 'Same session+reason should be idempotent even after reverse');

        PackageDeductionService::recomputeCounters($pkg->id);
        $pkg->refresh();
        $this->assertEquals(10, $pkg->remaining_sessions, 'After deduct + reverse, balance should be restored');
    }

    // ─── SessionDeductionService integration: non-package courses unaffected ──

    public function test_non_package_course_does_not_create_ledger_entry(): void
    {
        $student = $this->makeStudent();
        $teacher = $this->makeTeacher();

        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $teacher->id, 'by1' => 1, 'Period' => 4,
            'StartDate' => Carbon::today()->toDateString(), 'TotalHours' => 0,             'ScheduleMode' => 'count', 'SessionCount' => 10,
            'RemainingSessions' => 10, 'UsedSessions' => 0, 'SessionDuration' => 120,
            'ClassType' => 'one_on_one', 'Rate' => 500, 'rate_unit' => 'session',
            'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 0,
        ]);

        $session = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-16',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
        ]);

        SessionDeductionService::deductForSession($sc->ID, $session->id, 'attendance');

        $this->assertEquals(0, PackageSessionLedger::count());
    }
}
