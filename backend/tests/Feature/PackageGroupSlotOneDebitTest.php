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
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #4455 class E: FR-003 debits a group package once per (SessionDate, HH:MM) slot, but package creation with past
 * dates, lessons stored with seconds and retiming an attended lesson could each leave one slot debited twice.
 */
class PackageGroupSlotOneDebitTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: int}> */
    public static function classTypes(): array
    {
        return ['group package: one debit per slot' => ['one_on_three', -1], '1:1 package: one debit per lesson' => ['one_on_one', -2]];
    }

    /** @dataProvider classTypes */
    public function test_package_creation_debits_a_past_slot_shared_by_two_subjects_by_class_type(string $classType, int $net): void
    {
        $token = $this->directorToken();
        $student = $this->makeStudent();
        $subject = fn (int $id) => ['subject_id' => $id, 'teacher_id' => $this->makeTeacher()->id, 'duration_hours' => 2,
            'start_time' => '16:00', 'confirmed_dates' => ['2026-05-04']];

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/course-packages/create-multi-subject', [
                'student_id' => $student->id, 'branch_id' => 1, 'name' => '同時段兩科', 'total_sessions' => 10,
                'rate' => 500, 'class_type' => $classType, 'subjects' => [$subject(1), $subject(2)],
            ]);

        $res->assertStatus(201);
        $pkgId = (int) $res->json('package_id');
        $this->assertSame(2, ClassSession::whereIn('StudentClassID', StudentClass::where('PackageID', $pkgId)->pluck('ID'))
            ->where('Status', 'attended')->count(), 'each subject keeps its attended lesson');
        $this->assertSame($net, (int) PackageSessionLedger::where('package_id', $pkgId)->sum('delta'));
        $this->assertSame(10 + $net, (int) CoursePackage::findOrFail($pkgId)->remaining_sessions);
    }

    public function test_a_lesson_stored_with_seconds_shares_the_slot_of_one_without(): void
    {
        [$pkg, $first, $second] = $this->groupPackage();
        $plain = $this->makeSession($first->ID, '16:00');
        $swiped = $this->makeSession($second->ID, '16:00:37');
        $this->assertSame('16:00:37', (string) DB::table('ClassSession')->where('id', $swiped->id)->value('StartTime'));

        $this->assertTrue(SessionDeductionService::deductForSession($first->ID, $plain->id, 'attendance'));
        $this->assertTrue(SessionDeductionService::deductForSession($second->ID, $swiped->id, 'attendance'));

        $this->assertSame(-1, (int) PackageSessionLedger::where('package_id', $pkg->id)->sum('delta'));
        $this->assertSame(9, (int) $pkg->fresh()->remaining_sessions);
        $this->assertSame(0, PackageSessionLedger::where('package_id', $pkg->id)->where('class_session_id', $swiped->id)->count());
    }

    public function test_retiming_an_attended_lesson_into_a_debited_slot_keeps_one_debit_and_restores_the_old_slot(): void
    {
        [$pkg, $first, $second] = $this->groupPackage();
        $moving = $this->makeSession($first->ID, '16:00');
        $other = $this->makeSession($second->ID, '18:00');
        SessionDeductionService::deductForSession($first->ID, $moving->id, 'attendance');
        SessionDeductionService::deductForSession($second->ID, $other->id, 'attendance');
        $this->assertSame(8, (int) $pkg->fresh()->remaining_sessions, 'two slots, two debits');
        $token = $this->directorToken();

        $this->retime($token, $moving->id, '18:00', '20:00')->assertOk();
        $this->assertSame(-1, $this->slotNet($pkg->id, '18:00'), 'the 18:00 slot holds one debit');
        $this->assertSame(0, $this->slotNet($pkg->id, '16:00'), 'the 16:00 slot no longer holds a debit');
        $this->assertSame(9, (int) $pkg->fresh()->remaining_sessions);

        // Idempotent: saving the same time again writes nothing.
        $rows = PackageSessionLedger::where('package_id', $pkg->id)->count();
        $this->retime($token, $moving->id, '18:00', '20:00')->assertOk();
        $this->assertSame($rows, PackageSessionLedger::where('package_id', $pkg->id)->count());

        // Moving it back (a corrected mistake) debits its own slot again.
        $this->retime($token, $moving->id, '16:00', '18:00')->assertOk();
        $this->assertSame(-1, $this->slotNet($pkg->id, '16:00'));
        $this->assertSame(-1, $this->slotNet($pkg->id, '18:00'));
        $this->assertSame(8, (int) $pkg->fresh()->remaining_sessions);
        $this->assertSame(1, SessionDeductionService::sessionLedgerNet($first->ID, $moving->id), 'the course ledger is untouched');
    }

    public function test_retiming_a_debited_lesson_into_a_free_slot_moves_its_debit(): void
    {
        [$pkg, $first] = $this->groupPackage();
        $moving = $this->makeSession($first->ID, '16:00');
        SessionDeductionService::deductForSession($first->ID, $moving->id, 'attendance');

        $this->retime($this->directorToken(), $moving->id, '17:00', '19:00')->assertOk();

        $this->assertSame(0, $this->slotNet($pkg->id, '16:00'));
        $this->assertSame(-1, $this->slotNet($pkg->id, '17:00'));
        $this->assertSame(1, PackageSessionLedger::where('package_id', $pkg->id)->count(), 'the debit moves with the lesson; no new rows');
        $this->assertSame(9, (int) $pkg->fresh()->remaining_sessions);
    }

    private function retime(string $token, int $sessionId, string $start, string $end)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->patchJson("/api/v1/class-sessions/{$sessionId}", ['status' => 'attended', 'start_time' => $start, 'end_time' => $end]);
    }

    private function slotNet(int $packageId, string $startHm): int
    {
        return (int) DB::table('package_session_ledger as l')->join('ClassSession as c', 'c.id', '=', 'l.class_session_id')
            ->where('l.package_id', $packageId)->where('c.SessionDate', '2026-04-16')
            ->whereRaw('SUBSTRING(c.StartTime, 1, 5) = ?', [$startHm])->sum('l.delta');
    }

    /** @return array{0: CoursePackage, 1: StudentClass, 2: StudentClass} */
    private function groupPackage(): array
    {
        $owner = $this->makeStudent();
        $pkg = CoursePackage::create([
            'student_id' => $owner->id, 'campus_id' => 1, 'name' => '一對二時段方案',
            'total_sessions' => 10, 'remaining_sessions' => 10, 'used_sessions' => 0,
            'rate' => 500, 'rate_unit' => 'session', 'class_type' => 'one_on_two',
            'paid' => false, 'stop' => false, 'enabled' => true,
        ]);
        $teacherId = $this->makeTeacher()->id;
        $member = fn (int $studentId) => StudentClass::create([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacherId, 'by1' => 1, 'Period' => 4,
            'StartDate' => Carbon::today()->toDateString(), 'TotalHours' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10,
            'RemainingSessions' => 10, 'UsedSessions' => 0, 'SessionDuration' => 120, 'ClassType' => 'one_on_two',
            'Rate' => 500, 'rate_unit' => 'session', 'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 0,
            'PackageID' => $pkg->id, 'PackageTotalSessions' => 10, 'PackageName' => $pkg->name,
        ]);

        return [$pkg, $member($owner->id), $member($this->makeStudent()->id)];
    }

    private function makeSession(int $studentClassId, string $start): ClassSession
    {
        $end = Carbon::createFromFormat('H:i', substr($start, 0, 5))->addHours(2)->format('H:i');

        return ClassSession::create(['StudentClassID' => $studentClassId, 'SessionDate' => '2026-04-16',
            'StartTime' => $start, 'EndTime' => $end, 'Status' => 'attended']);
    }

    private function makeStudent(): Student
    {
        return Student::create(['name' => '時段學生' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '']);
    }

    private function makeTeacher(): User
    {
        return User::create(['LoginName' => 'slot-t-' . uniqid() . '@t.com', 'Name' => '時段老師' . uniqid(),
            'PSW' => 's', 'type' => 'T', 'phone' => 911000000]);
    }

    private function directorToken(): string
    {
        $director = User::create(['LoginName' => 'slot-dir-' . uniqid() . '@t.com', 'Name' => '主任', 'PSW' => 's',
            'type' => 'A', 'phone' => 900000000, 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }
}
