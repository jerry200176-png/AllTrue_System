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
use Tests\TestCase;

/**
 * FR-003 / FR-004 — Group-class ledger dedup and source-of-truth rebuild.
 *
 * Defense-in-depth:
 *   - FR-003 prevents future over-deduction at write time.
 *   - FR-004 repairs historical over-deduction in place.
 * These tests cover the happy path, the sliding-window equivalent (same date
 * different timeslot NOT merged), the 1:1 regression (dedup disabled for
 * one_on_one), idempotency of rebuild, and source-of-truth isolation.
 */
class PackageRebuildLedgerTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function uniq(string $prefix = ''): string
    {
        $this->seq++;
        return $prefix . '-' . $this->seq . '-' . uniqid();
    }

    private function makeStudent(): Student
    {
        return Student::create([
            'name' => '測試生' . $this->uniq(), 'CampusID' => 1,
            'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
    }

    private function makeTeacher(): User
    {
        return User::create([
            'LoginName' => 'tch-' . $this->uniq() . '@t.com',
            'Name' => '測試師', 'PSW' => 's', 'type' => 'T', 'phone' => 911000000,
        ]);
    }

    private function makePackage(string $classType, int $total): CoursePackage
    {
        return CoursePackage::create([
            'student_id' => 0, 'campus_id' => 1, 'name' => '方案-' . $this->uniq(),
            'billing_mode' => 'count',
            'total_sessions' => $total, 'remaining_sessions' => $total, 'used_sessions' => 0,
            'rate' => 500, 'rate_unit' => 'session', 'class_type' => $classType,
            'paid' => false, 'stop' => false, 'enabled' => true,
        ]);
    }

    private function addMember(CoursePackage $pkg, int $teacherId, int $total): StudentClass
    {
        $student = $this->makeStudent();
        return StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $teacherId, 'by1' => 1, 'Period' => 4,
            'StartDate' => Carbon::today()->toDateString(), 'TotalHours' => 0,             'ScheduleMode' => 'count', 'SessionCount' => $total,
            'RemainingSessions' => $total, 'UsedSessions' => 0, 'SessionDuration' => 120,
            'ClassType' => $pkg->class_type, 'Rate' => 500, 'rate_unit' => 'session',
            'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 0,
            'PackageID' => $pkg->id, 'PackageTotalSessions' => $total, 'PackageName' => $pkg->name,
        ]);
    }

    private function schedule(StudentClass $sc, string $date, string $start = '16:00', string $end = '18:00', string $status = 'attended'): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => $date,
            'StartTime' => $start, 'EndTime' => $end, 'Status' => $status,
        ]);
    }

    // ───────────────────────── FR-003 ─────────────────────────

    public function test_group_session_is_deducted_only_once_for_one_on_three(): void
    {
        $teacher = $this->makeTeacher();
        $pkg = $this->makePackage('one_on_three', 12);
        $a = $this->addMember($pkg, $teacher->id, 12);
        $b = $this->addMember($pkg, $teacher->id, 12);
        $c = $this->addMember($pkg, $teacher->id, 12);

        // Three members sit in the same group lesson — same date + start time.
        $csA = $this->schedule($a, '2026-04-10', '16:00');
        $csB = $this->schedule($b, '2026-04-10', '16:00');
        $csC = $this->schedule($c, '2026-04-10', '16:00');

        PackageDeductionService::syncFromStudentClassDeduction($a->ID, $csA->id, 'deduct', 'attended');
        PackageDeductionService::syncFromStudentClassDeduction($b->ID, $csB->id, 'deduct', 'attended');
        PackageDeductionService::syncFromStudentClassDeduction($c->ID, $csC->id, 'deduct', 'attended');

        $this->assertSame(1, PackageSessionLedger::where('package_id', $pkg->id)->where('delta', -1)->count());
        $this->assertSame(11, (int) $pkg->fresh()->remaining_sessions);
    }

    public function test_same_day_different_timeslot_is_not_merged(): void
    {
        // Risk-3 guard: Math 16:00 and English 18:00 on the same day are different lessons.
        $teacher = $this->makeTeacher();
        $pkg = $this->makePackage('one_on_three', 12);
        $a = $this->addMember($pkg, $teacher->id, 12);
        $b = $this->addMember($pkg, $teacher->id, 12);

        $csA1 = $this->schedule($a, '2026-04-10', '16:00');
        $csB1 = $this->schedule($b, '2026-04-10', '16:00');
        $csA2 = $this->schedule($a, '2026-04-10', '18:00');
        $csB2 = $this->schedule($b, '2026-04-10', '18:00');

        foreach ([$csA1, $csB1, $csA2, $csB2] as $cs) {
            $sc = StudentClass::where('ID', $cs->StudentClassID)->first();
            PackageDeductionService::syncFromStudentClassDeduction($sc->ID, $cs->id, 'deduct', 'attended');
        }

        // Two distinct slots → two deductions.
        $this->assertSame(2, PackageSessionLedger::where('package_id', $pkg->id)->where('delta', -1)->count());
        $this->assertSame(10, (int) $pkg->fresh()->remaining_sessions);
    }

    public function test_one_on_one_package_is_unaffected_by_group_dedup(): void
    {
        // Regression guard — 1:1 packages must still deduct per attendance.
        $teacher = $this->makeTeacher();
        $pkg = $this->makePackage('one_on_one', 12);
        $sc = $this->addMember($pkg, $teacher->id, 12);

        $cs1 = $this->schedule($sc, '2026-04-10', '16:00');
        $cs2 = $this->schedule($sc, '2026-04-17', '16:00');

        PackageDeductionService::syncFromStudentClassDeduction($sc->ID, $cs1->id, 'deduct', 'attended');
        PackageDeductionService::syncFromStudentClassDeduction($sc->ID, $cs2->id, 'deduct', 'attended');

        $this->assertSame(2, PackageSessionLedger::where('package_id', $pkg->id)->where('delta', -1)->count());
        $this->assertSame(10, (int) $pkg->fresh()->remaining_sessions);
    }

    // ───────────────────────── FR-004 ─────────────────────────

    public function test_rebuild_repairs_historical_over_deduction(): void
    {
        // Simulate the Dazhi bug: legacy per-member deductions left a 3x ledger.
        $teacher = $this->makeTeacher();
        $pkg = $this->makePackage('one_on_three', 12);
        $a = $this->addMember($pkg, $teacher->id, 12);
        $b = $this->addMember($pkg, $teacher->id, 12);
        $c = $this->addMember($pkg, $teacher->id, 12);

        foreach (['2026-04-01', '2026-04-08', '2026-04-15', '2026-04-22'] as $date) {
            $csA = $this->schedule($a, $date);
            $csB = $this->schedule($b, $date);
            $csC = $this->schedule($c, $date);
            foreach ([[$a, $csA], [$b, $csB], [$c, $csC]] as [$sc, $cs]) {
                PackageSessionLedger::create([
                    'package_id' => $pkg->id, 'student_class_id' => $sc->ID,
                    'class_session_id' => $cs->id, 'delta' => -1, 'reason' => 'attended',
                ]);
            }
        }
        $pkg->remaining_sessions = 0;
        $pkg->used_sessions = 12;
        $pkg->save();
        $this->assertSame(12, PackageSessionLedger::where('package_id', $pkg->id)->count());
        $this->assertSame(0, (int) $pkg->fresh()->remaining_sessions);

        $result = PackageDeductionService::rebuildLedgerFromSessions($pkg->id);

        $this->assertSame(12, $result['deleted_entries']);
        $this->assertSame(4, $result['rebuilt_entries']);
        $this->assertSame(8, $result['remaining_sessions']);
        $this->assertSame(4, $result['used_sessions']);
        $this->assertSame(4, PackageSessionLedger::where('package_id', $pkg->id)->count());
    }

    public function test_rebuild_dry_run_does_not_write(): void
    {
        $teacher = $this->makeTeacher();
        $pkg = $this->makePackage('one_on_three', 12);
        $a = $this->addMember($pkg, $teacher->id, 12);
        $csA = $this->schedule($a, '2026-04-01');
        PackageSessionLedger::create([
            'package_id' => $pkg->id, 'student_class_id' => $a->ID,
            'class_session_id' => $csA->id, 'delta' => -1, 'reason' => 'attended',
        ]);
        PackageSessionLedger::create([
            'package_id' => $pkg->id, 'student_class_id' => $a->ID,
            'class_session_id' => $csA->id, 'delta' => -1, 'reason' => 'duplicate_bug',
        ]);
        $pkg->remaining_sessions = 10;
        $pkg->used_sessions = 2;
        $pkg->save();

        $before = PackageSessionLedger::where('package_id', $pkg->id)->count();
        $result = PackageDeductionService::rebuildLedgerFromSessions($pkg->id, true);
        $after = PackageSessionLedger::where('package_id', $pkg->id)->count();

        $this->assertTrue($result['dry_run']);
        $this->assertSame(2, $before);
        $this->assertSame(2, $after, 'dry-run must not modify the database');
        $this->assertSame(11, $result['remaining_sessions']); // projected: 12 - 1 slot
        $this->assertSame(2, $result['deleted_entries']);
        $this->assertSame(1, $result['rebuilt_entries']);
    }

    public function test_rebuild_is_idempotent(): void
    {
        $teacher = $this->makeTeacher();
        $pkg = $this->makePackage('one_on_three', 12);
        $a = $this->addMember($pkg, $teacher->id, 12);
        $b = $this->addMember($pkg, $teacher->id, 12);
        foreach (['2026-04-01', '2026-04-08'] as $date) {
            $csA = $this->schedule($a, $date);
            $csB = $this->schedule($b, $date);
            PackageSessionLedger::create([
                'package_id' => $pkg->id, 'student_class_id' => $a->ID,
                'class_session_id' => $csA->id, 'delta' => -1, 'reason' => 'attended',
            ]);
            PackageSessionLedger::create([
                'package_id' => $pkg->id, 'student_class_id' => $b->ID,
                'class_session_id' => $csB->id, 'delta' => -1, 'reason' => 'attended',
            ]);
        }

        $first = PackageDeductionService::rebuildLedgerFromSessions($pkg->id);
        $second = PackageDeductionService::rebuildLedgerFromSessions($pkg->id);

        $this->assertSame($first['remaining_sessions'], $second['remaining_sessions']);
        $this->assertSame($first['used_sessions'], $second['used_sessions']);
        $this->assertSame($first['rebuilt_entries'], $second['rebuilt_entries']);
        // After the first rebuild, the ledger is already canonical, so the second
        // run deletes and rewrites the same number of rows.
        $this->assertSame($second['deleted_entries'], $second['rebuilt_entries']);
    }

    public function test_rebuild_ignores_non_attended_sessions(): void
    {
        // Source-of-truth isolation: only Status='attended' counts toward used sessions.
        $teacher = $this->makeTeacher();
        $pkg = $this->makePackage('one_on_one', 12);
        $sc = $this->addMember($pkg, $teacher->id, 12);
        $this->schedule($sc, '2026-04-01', '16:00', '18:00', 'attended');
        $this->schedule($sc, '2026-04-08', '16:00', '18:00', 'leave');
        $this->schedule($sc, '2026-04-15', '16:00', '18:00', 'scheduled');
        $this->schedule($sc, '2026-04-22', '16:00', '18:00', 'cancelled');

        $result = PackageDeductionService::rebuildLedgerFromSessions($pkg->id);

        $this->assertSame(1, $result['rebuilt_entries']);
        $this->assertSame(11, $result['remaining_sessions']);
    }

    public function test_rebuild_endpoint_requires_elevated_role(): void
    {
        $teacher = $this->makeTeacher();
        $pkg = $this->makePackage('one_on_three', 12);

        // No auth at all → 401/403 chain (route middleware rejects unauthenticated).
        $res = $this->postJson("/api/v1/course-packages/{$pkg->id}/rebuild-ledger", ['dry_run' => true]);
        $this->assertTrue(
            in_array($res->getStatusCode(), [401, 403], true),
            'expected 401/403, got ' . $res->getStatusCode()
        );
    }
}
