<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\SubstituteScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Parity: production shape 2026-10-09 (ClassSession 41612). Two schedule chains hit the same
 * slot: an older reschedule left the contract teacher A a status=scheduled row (id 12696,
 * original_schedule_id=12695), and a later substitute row for B (id 12698, original=12697).
 * Every "who teaches this session" reader must resolve B.
 */
class SubstituteTwoChainsSameSlotParityTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-10-09';

    public function test_all_teacher_readers_resolve_substitute_b(): void
    {
        [$aId, $bId, $aToken, $bToken, $sc, $session] = $this->seed2026Shape();

        // 1. class-sessions index read service
        $svc = app(\App\Services\ClassSessionIndexReadService::class);
        $rows = $svc->buildQuery(Request::create('/api/v1/class-sessions', 'GET', [
                'start' => self::DATE, 'end' => self::DATE,
            ]))->get();
        $row = $rows->firstWhere('id', $session->id);
        $row = $row ? $svc->transformRow($row) : null;
        $this->assertNotNull($row, 'index buildQuery should return the session');
        $this->assertSame($bId, (int) $row->teacher_id, 'index teacher_id');
        $this->assertSame($bId, (int) $row->substitute_teacher_id, 'index substitute_teacher_id');

        // 2. SubstituteScheduleService
        $this->assertSame($bId, SubstituteScheduleService::effectiveInstructorUserId(
            (int) $sc->ID, self::DATE, $aId, '10:00:00'));

        // 3. attendance write permission: A refused, B allowed
        $this->postAttendance($aToken, $session, $sc)
            ->assertStatus(403)
            ->assertJson(['message' => '非該堂的授課老師，無法操作']);
        $this->postAttendance($bToken, $session, $sc)->assertCreated();
        $this->assertDatabaseHas('StudentSingIn', [
            'ClassSessionID' => $session->id, 'TeacherID' => $bId, 'Status' => 'present',
        ]);
    }

    public function test_payroll_attributes_session_to_substitute_b(): void
    {
        [$aId, $bId, , , $sc, $session, $stu] = $this->seed2026Shape(partTime: true);

        // Sign-in still points at contract teacher A (the in-app #307 shape).
        StudentSignIn::create([
            'StudentClassID' => $sc->ID, 'StudentID' => $stu->id, 'TeacherID' => $aId,
            'ClassSessionID' => $session->id, 'Status' => 'present',
            'SignInDT' => self::DATE . ' 10:00:00',
        ]);

        $dir = User::create([
            'LoginName' => 'dir-parity@example.com', 'Name' => '主任', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0910000009', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $dir->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dir->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-10&branch_id=1');
        $res->assertOk();
        $teachers = collect($res->json('teachers'));
        $this->assertNull($teachers->firstWhere('teacher_id', $aId), 'payroll must not credit contract teacher A');
        $this->assertSame(1, $teachers->firstWhere('teacher_id', $bId)['session_count'] ?? null,
            'payroll must credit substitute B');
    }

    private function postAttendance(string $token, ClassSession $session, StudentClass $sc): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/attendance', [
                'ClassSessionID' => $session->id,
                'StudentID' => (int) $sc->StudentID,
                'StudentClassID' => (int) $sc->ID,
                'Status' => 'present',
                'mark_mode' => 'arrival',
            ]);
    }

    private function seed2026Shape(bool $partTime = false): array
    {
        $mk = function (string $email, string $name, string $phone) use ($partTime) {
            $u = User::create([
                'LoginName' => $email, 'Name' => $name, 'PSW' => 'x', 'type' => 'T',
                'phone' => $phone, 'MustChangePassword' => false,
                'employment_type' => $partTime ? 'part_time' : 'full_time',
            ]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $u->id, 'Admin' => 0, 'Approved' => 1]);
            $t = bin2hex(random_bytes(16));
            AuthToken::create(['user_id' => $u->id, 'token' => $t, 'expires_at' => now()->addDay()]);

            return [(int) $u->id, $t];
        };
        [$aId, $aToken] = $mk('a-parity@example.com', '契約老師A', '0911005001');
        [$bId, $bToken] = $mk('b-parity@example.com', '代課老師B', '0911005002');

        $stu = Student::create([
            'name' => '雙鏈同時段生', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $stu->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $aId, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-09-01', 'TotalHours' => 20,
            'SessionCount' => 10, 'SessionDuration' => 120,
            'RemainingSessions' => 10, 'UsedSessions' => 0,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        $session = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => self::DATE,
            'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'scheduled',
        ]);

        $base = [
            'student_id' => $stu->id, 'day_of_week' => 5, 'type' => 'normal', 'deduction' => 1,
            'branch_id' => 1, 'student_course_id' => $sc->ID,
            'created_at' => now(), 'updated_at' => now(),
        ];
        // 12695: older reschedule anchor on ANOTHER date
        DB::table('schedules')->insert($base + [
            'id' => 12695, 'teacher_id' => $aId, 'status' => 'rescheduled',
            'schedule_date' => '2026-10-02', 'start_time' => '10:00:00', 'end_time' => '12:00:00',
            'original_schedule_id' => null,
        ]);
        DB::table('schedules')->insert($base + [
            'id' => 12696, 'teacher_id' => $aId, 'status' => 'scheduled',
            'schedule_date' => self::DATE, 'start_time' => '10:00:00', 'end_time' => '12:00:00',
            'original_schedule_id' => 12695,
        ]);
        DB::table('schedules')->insert($base + [
            'id' => 12697, 'teacher_id' => $aId, 'status' => 'rescheduled',
            'schedule_date' => self::DATE, 'start_time' => '11:00', 'end_time' => '13:00',
            'original_schedule_id' => null,
        ]);
        DB::table('schedules')->insert($base + [
            'id' => 12698, 'teacher_id' => $bId, 'status' => 'scheduled',
            'schedule_date' => self::DATE, 'start_time' => '10:00', 'end_time' => '12:00',
            'original_schedule_id' => 12697,
        ]);

        return [$aId, $bId, $aToken, $bToken, $sc, $session, $stu];
    }
}
