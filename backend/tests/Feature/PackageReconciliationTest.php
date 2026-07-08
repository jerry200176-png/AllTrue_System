<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\PackageSessionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\PackageDeductionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PackageReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function makeStudent(): Student
    {
        return Student::create([
            'name' => '對帳學生' . uniqid(), 'CampusID' => 1,
            'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
    }

    private function makeTeacher(): User
    {
        return User::create([
            'LoginName' => 'teacher-rec-' . uniqid() . '@t.com',
            'Name' => '對帳老師', 'PSW' => 's', 'type' => 'T', 'phone' => 911000000,
        ]);
    }

    private function makePackageWithMember(int $studentId, int $teacherId, int $total = 20): array
    {
        $pkg = CoursePackage::create([
            'student_id' => $studentId, 'campus_id' => 1, 'name' => '對帳測試',
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

    // ─── Reconcile detects stale cache ───────────────────

    public function test_reconcile_detects_discrepancy(): void
    {
        $student = $this->makeStudent();
        $teacher = $this->makeTeacher();
        [$pkg, $sc] = $this->makePackageWithMember($student->id, $teacher->id, 20);

        $session = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-16',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
        ]);

        PackageDeductionService::deductForSession($pkg->id, $sc->ID, $session->id, 'attendance');

        // Corrupt the cached counter
        $pkg->remaining_sessions = 999;
        $pkg->used_sessions = 0;
        $pkg->save();

        $ledgerRemaining = $pkg->computeRemainingFromLedger();
        $this->assertNotEquals(999, $ledgerRemaining);
        $this->assertEquals(19, $ledgerRemaining);
    }

    // ─── Reconcile command fixes discrepancy ─────────────

    public function test_reconcile_command_fixes_discrepancy(): void
    {
        $student = $this->makeStudent();
        $teacher = $this->makeTeacher();
        [$pkg, $sc] = $this->makePackageWithMember($student->id, $teacher->id, 20);

        for ($i = 0; $i < 3; $i++) {
            $session = ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => Carbon::today()->addDays($i)->toDateString(),
                'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
            ]);
            PackageDeductionService::deductForSession($pkg->id, $sc->ID, $session->id, 'attendance');
        }

        // Corrupt cache
        $pkg->remaining_sessions = 100;
        $pkg->save();

        $exitCode = Artisan::call('packages:reconcile', ['--fix' => true]);
        $this->assertEquals(1, $exitCode, 'Should return non-zero when discrepancies found');

        $pkg->refresh();
        $this->assertEquals(17, $pkg->remaining_sessions, 'Should be fixed to 20 - 3 = 17');
        $this->assertEquals(3, $pkg->used_sessions);
    }

    // ─── Reconcile when all consistent returns 0 ─────────

    public function test_reconcile_all_consistent(): void
    {
        $student = $this->makeStudent();
        $teacher = $this->makeTeacher();
        [$pkg, $sc] = $this->makePackageWithMember($student->id, $teacher->id, 10);

        $session = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-16',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
        ]);

        PackageDeductionService::deductForSession($pkg->id, $sc->ID, $session->id, 'attendance');
        PackageDeductionService::recomputeCounters($pkg->id);

        $exitCode = Artisan::call('packages:reconcile');
        $this->assertEquals(0, $exitCode);
    }

    // ─── Full recompute fixes per-member counters ────────

    public function test_full_recompute_updates_member_counters(): void
    {
        $student = $this->makeStudent();
        $teacher1 = $this->makeTeacher();
        $teacher2 = $this->makeTeacher();

        $pkg = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1, 'name' => '多科對帳',
            'total_sessions' => 20, 'remaining_sessions' => 20, 'used_sessions' => 0,
            'rate' => 500, 'rate_unit' => 'session', 'class_type' => 'one_on_one',
            'paid' => false, 'stop' => false, 'enabled' => true,
        ]);

        $sc1 = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $teacher1->id, 'by1' => 1, 'Period' => 4,
            'StartDate' => Carbon::today()->toDateString(), 'TotalHours' => 0,             'ScheduleMode' => 'count', 'SessionCount' => 20,
            'RemainingSessions' => 20, 'UsedSessions' => 0, 'SessionDuration' => 120,
            'ClassType' => 'one_on_one', 'Rate' => 500, 'rate_unit' => 'session',
            'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 0,
            'PackageID' => $pkg->id, 'PackageTotalSessions' => 20, 'PackageName' => $pkg->name,
        ]);

        $sc2 = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 2,
            'TeacherID' => $teacher2->id, 'by1' => 1, 'Period' => 4,
            'StartDate' => Carbon::today()->toDateString(), 'TotalHours' => 0,             'ScheduleMode' => 'count', 'SessionCount' => 20,
            'RemainingSessions' => 20, 'UsedSessions' => 0, 'SessionDuration' => 120,
            'ClassType' => 'one_on_one', 'Rate' => 500, 'rate_unit' => 'session',
            'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 0,
            'PackageID' => $pkg->id, 'PackageTotalSessions' => 20, 'PackageName' => $pkg->name,
        ]);

        // Deduct 2 from sc1, 1 from sc2
        for ($i = 0; $i < 2; $i++) {
            $s = ClassSession::create([
                'StudentClassID' => $sc1->ID,
                'SessionDate' => Carbon::today()->addDays($i)->toDateString(),
                'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
            ]);
            PackageDeductionService::deductForSession($pkg->id, $sc1->ID, $s->id, 'attendance');
        }
        $s3 = ClassSession::create([
            'StudentClassID' => $sc2->ID, 'SessionDate' => '2026-04-20',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended',
        ]);
        PackageDeductionService::deductForSession($pkg->id, $sc2->ID, $s3->id, 'attendance');

        $result = PackageDeductionService::fullRecompute($pkg->id);

        $this->assertEquals(17, $result['remaining_sessions']);
        $this->assertEquals(3, $result['used_sessions']);

        $sc1->refresh();
        $sc2->refresh();
        $this->assertEquals(17, (int) $sc1->RemainingSessions);
        $this->assertEquals(17, (int) $sc2->RemainingSessions);
    }
}
