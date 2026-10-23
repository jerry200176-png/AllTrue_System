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
        $this->assertSame(1, DB::table('session_deduction_ledger')->where('class_session_id', $moving->id)->count(),
            'the course ledger is untouched');
    }

    public function test_a_failed_reconciliation_rolls_the_time_edit_back_so_a_retry_repairs_it(): void
    {
        [$pkg, $first, $second] = $this->groupPackage();
        $moving = $this->makeSession($first->ID, '16:00');
        $other = $this->makeSession($second->ID, '18:00');
        SessionDeductionService::deductForSession($first->ID, $moving->id, 'attendance');
        SessionDeductionService::deductForSession($second->ID, $other->id, 'attendance');
        $token = $this->directorToken();
        $fail = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$fail) {
            if ($fail && str_contains(strtolower($sql), 'insert into `package_session_ledger`')) {
                throw new \RuntimeException('synthetic ledger failure');
            }
        });

        $this->retime($token, $moving->id, '18:00', '20:00')->assertStatus(500);
        $this->assertSame('16:00', substr((string) $moving->fresh()->StartTime, 0, 5), 'the new time rolled back with the ledger fix');
        $this->assertSame(2, PackageSessionLedger::where('package_id', $pkg->id)->count());

        $fail = false;
        $this->retime($token, $moving->id, '18:00', '20:00')->assertOk();
        $this->assertSame(-1, $this->slotNet($pkg->id, '18:00'), 'retrying the same edit repairs the slot');
        $this->assertSame(9, (int) $pkg->fresh()->remaining_sessions);
    }

    public function test_an_overlapping_edit_loaded_before_another_retime_reconciles_against_the_locked_row(): void
    {
        [$pkg, $first, $second] = $this->groupPackage();
        $moving = $this->makeSession($first->ID, '16:00');
        SessionDeductionService::deductForSession($first->ID, $moving->id, 'attendance');
        SessionDeductionService::deductForSession($second->ID, $this->makeSession($second->ID, '18:00')->id, 'attendance');
        $token = $this->directorToken();
        $this->retime($token, $moving->id, '18:00', '20:00')->assertOk(); // request 1 merges A into 18:00
        $this->assertSame(0, $this->slotNet($pkg->id, '16:00'));

        // Request 2 loaded A before request 1 committed, so its first read still says 16:00.
        $stale = true;
        ClassSession::retrieved(function (ClassSession $s) use (&$stale, $moving) {
            if ($stale && (int) $s->id === (int) $moving->id) {
                $stale = false;
                $s->setRawAttributes(array_replace($s->getAttributes(), ['StartTime' => '16:00:00', 'EndTime' => '18:00:00']), true);
            }
        });
        $this->retime($token, $moving->id, '16:00', '18:00')->assertOk();

        $this->assertFalse($stale, 'precondition: request 2 started from the stale row');
        $this->assertSame('16:00', substr((string) $moving->fresh()->StartTime, 0, 5));
        $this->assertSame(-1, $this->slotNet($pkg->id, '16:00'), 'A is back at 16:00 and debits it again');
        $this->assertSame(-1, $this->slotNet($pkg->id, '18:00'));
        $this->assertSame(8, (int) $pkg->fresh()->remaining_sessions);
    }

    public function test_moving_the_carrier_away_keeps_the_old_slot_debited_for_a_member_still_there(): void
    {
        [$pkg, $a, $b, $c] = $this->groupPackage(3);
        [$carrier, $stays, $other] = [$this->makeSession($a->ID, '16:00'), $this->makeSession($b->ID, '16:00'), $this->makeSession($c->ID, '18:00')];
        foreach ([[$a, $carrier], [$b, $stays], [$c, $other]] as [$course, $lesson]) {
            SessionDeductionService::deductForSession($course->ID, $lesson->id, 'attendance');
        }
        $this->assertSame(-1, $this->sessionNet($pkg->id, $carrier->id), 'precondition: the first member carries 16:00');
        $this->assertSame(8, (int) $pkg->fresh()->remaining_sessions);

        $this->retime($this->directorToken(), $carrier->id, '18:00', '20:00')->assertOk();

        $this->assertSame(-1, $this->slotNet($pkg->id, '16:00'), 'the member still taught at 16:00 keeps that slot debited');
        $this->assertSame(-1, $this->sessionNet($pkg->id, $stays->id));
        $this->assertSame(-1, $this->slotNet($pkg->id, '18:00'));
        $this->assertSame(8, (int) $pkg->fresh()->remaining_sessions, 'two taught slots, two debits');
    }

    /** @return array<string, array{0: string, 1: bool, 2: int}> */
    public static function memberLeftBehind(): array
    {
        return ['completed, charged (learning-record approval)' => ['completed', true, -1],
            'absent after attended (charge kept)' => ['absent', true, -1], 'completed, never charged' => ['completed', false, 0]];
    }

    /** @dataProvider memberLeftBehind */
    public function test_a_member_left_behind_keeps_the_old_slot_debited_only_when_it_is_charged(string $status, bool $charged, int $oldSlotNet): void
    {
        [$pkg, $a, $b, $c] = $this->groupPackage(3);
        $carrier = $this->makeSession($a->ID, '16:00');
        $completed = $this->makeSession($b->ID, '16:00', $status);
        $other = $this->makeSession($c->ID, '18:00');
        SessionDeductionService::deductForSession($a->ID, $carrier->id, 'attendance');
        SessionDeductionService::deductForSession($c->ID, $other->id, 'attendance');
        if ($charged) {
            SessionDeductionService::deductForSession($b->ID, $completed->id, 'attendance');
        }
        $this->assertSame(-1, $this->sessionNet($pkg->id, $carrier->id));

        $this->retime($this->directorToken(), $carrier->id, '18:00', '20:00')->assertOk();

        $this->assertSame($oldSlotNet, $this->slotNet($pkg->id, '16:00'));
        $this->assertSame(-1, $this->slotNet($pkg->id, '18:00'));
        $this->assertSame(9 + $oldSlotNet, (int) $pkg->fresh()->remaining_sessions);
    }

    public function test_a_lesson_seeded_at_creation_and_merged_by_a_retime_is_debited_again_when_moved_back(): void
    {
        $subject = fn (int $id, string $start) => ['subject_id' => $id, 'teacher_id' => $this->makeTeacher()->id,
            'duration_hours' => 2, 'start_time' => $start, 'confirmed_dates' => ['2026-04-16']];
        $token = $this->directorToken();
        $pkgId = (int) $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/course-packages/create-multi-subject', [
                'student_id' => $this->makeStudent()->id, 'branch_id' => 1, 'name' => '建立補登', 'total_sessions' => 10,
                'rate' => 500, 'class_type' => 'one_on_three', 'subjects' => [$subject(1, '16:00'), $subject(2, '18:00')],
            ])->assertStatus(201)->json('package_id');
        $seeded = ClassSession::whereIn('StudentClassID', StudentClass::where('PackageID', $pkgId)->pluck('ID'))
            ->where('StartTime', 'like', '16:00%')->firstOrFail();
        $this->assertSame(0, DB::table('session_deduction_ledger')->where('class_session_id', $seeded->id)->count(),
            'precondition: a creation seed debits the package only');

        $this->retime($token, $seeded->id, '18:00', '20:00')->assertOk();
        $this->assertSame(-1, $this->slotNet($pkgId, '18:00'));
        $this->assertSame(9, (int) CoursePackage::findOrFail($pkgId)->remaining_sessions);

        $this->retime($token, $seeded->id, '16:00', '18:00')->assertOk();
        $this->assertSame(-1, $this->slotNet($pkgId, '16:00'));
        $this->assertSame(-1, $this->slotNet($pkgId, '18:00'));
        $this->assertSame(8, (int) CoursePackage::findOrFail($pkgId)->remaining_sessions);
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

    private function sessionNet(int $packageId, int $sessionId): int
    {
        return (int) PackageSessionLedger::where('package_id', $packageId)->where('class_session_id', $sessionId)->sum('delta');
    }

    private function slotNet(int $packageId, string $startHm): int
    {
        return (int) DB::table('package_session_ledger as l')->join('ClassSession as c', 'c.id', '=', 'l.class_session_id')
            ->where('l.package_id', $packageId)->where('c.SessionDate', '2026-04-16')
            ->whereRaw('SUBSTRING(c.StartTime, 1, 5) = ?', [$startHm])->sum('l.delta');
    }

    /** @return array<int, CoursePackage|StudentClass> the package, then one member course per student */
    private function groupPackage(int $members = 2): array
    {
        $owner = $this->makeStudent();
        $pkg = CoursePackage::create(['student_id' => $owner->id, 'campus_id' => 1, 'name' => '一對二時段方案',
            'total_sessions' => 10, 'remaining_sessions' => 10, 'used_sessions' => 0, 'rate' => 500, 'rate_unit' => 'session',
            'class_type' => 'one_on_two', 'paid' => false, 'stop' => false, 'enabled' => true]);
        $teacherId = $this->makeTeacher()->id;
        $member = fn (int $studentId) => StudentClass::create([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacherId, 'by1' => 1, 'Period' => 4,
            'StartDate' => Carbon::today()->toDateString(), 'TotalHours' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10,
            'RemainingSessions' => 10, 'UsedSessions' => 0, 'SessionDuration' => 120, 'ClassType' => 'one_on_two',
            'Rate' => 500, 'rate_unit' => 'session', 'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 0,
            'PackageID' => $pkg->id, 'PackageTotalSessions' => 10, 'PackageName' => $pkg->name,
        ]);

        return [$pkg, ...array_map(fn (int $i) => $member($i === 0 ? $owner->id : $this->makeStudent()->id), range(0, $members - 1))];
    }

    private function makeSession(int $studentClassId, string $start, string $status = 'attended'): ClassSession
    {
        $end = Carbon::createFromFormat('H:i', substr($start, 0, 5))->addHours(2)->format('H:i');

        return ClassSession::create(['StudentClassID' => $studentClassId, 'SessionDate' => '2026-04-16',
            'StartTime' => $start, 'EndTime' => $end, 'Status' => $status]);
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
