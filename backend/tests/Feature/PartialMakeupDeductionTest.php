<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #613 A1 PR3：補課部分時數比例扣堂（HTTP E2E）。
 * 業務規則：補課（schedules.type='extra'）只覆蓋部分時數時，扣「實際分鐘」（上限每堂分鐘）；
 * 正常課堂、完整時長補課一律整堂（byte-identical）。
 */
class PartialMakeupDeductionTest extends TestCase
{
    use RefreshDatabase;

    /** 90 分補課（每堂 120）→ 扣 90 分；4×120−90=390；ROUND_HALF_UP(390/120)=3。 */
    public function test_partial_makeup_attendance_deducts_prorated_minutes(): void
    {
        [$token, $student, $scId] = $this->seedCourse(120, 4);
        $cs = $this->makeMakeupSession($scId, '23:00', '00:30'); // 90 min, crosses midnight

        $this->postAttendance($token, $student, $scId, $cs->id)->assertCreated();

        $sc = StudentClass::findOrFail($scId);
        $this->assertSame(390, (int) $sc->RemainingMinutes, '4×120 − 90 = 390（權威分鐘）');
        $this->assertSame(480, (int) $sc->PurchasedMinutes, '4×120');
        $this->assertSame(3, (int) $sc->RemainingSessions, 'ROUND_HALF_UP(390/120)=ROUND_HALF_UP(3.25)=3');
        $this->assertSame(1, (int) $sc->UsedSessions);

        $this->assertSame(90, (int) SessionDeductionLedger::where('student_class_id', $scId)
            ->where('event_type', 'deduct')->value('minutes'), 'ledger 應記錄 90 分');
    }

    /** 完整時長補課（120/120）→ 整堂，走 count 路徑、行為不變。 */
    public function test_full_length_makeup_deducts_whole_session(): void
    {
        [$token, $student, $scId] = $this->seedCourse(120, 4);
        $cs = $this->makeMakeupSession($scId, '23:00', '01:00'); // 120 min

        $this->postAttendance($token, $student, $scId, $cs->id)->assertCreated();

        $sc = StudentClass::findOrFail($scId);
        $this->assertSame(3, (int) $sc->RemainingSessions, '整堂扣 1');
        $this->assertSame(1, (int) $sc->UsedSessions);
        $this->assertNull(SessionDeductionLedger::where('student_class_id', $scId)
            ->where('event_type', 'deduct')->value('minutes'), '整堂 deduct 不記 minutes');
    }

    /** 正常課堂（非 type='extra'）即使時長較短也不比例扣，一律整堂。 */
    public function test_normal_short_session_not_prorated(): void
    {
        [$token, $student, $scId] = $this->seedCourse(120, 4);
        // 直接建立短時長的「正常」堂次（無 type='extra' schedule）
        $cs = ClassSession::create([
            'StudentClassID' => $scId,
            'SessionDate'    => Carbon::yesterday()->toDateString(),
            'StartTime'      => '23:00',
            'EndTime'        => '23:30', // 30 min
            'Status'         => 'scheduled',
        ]);

        $this->postAttendance($token, $student, $scId, $cs->id)->assertCreated();

        $sc = StudentClass::findOrFail($scId);
        $this->assertSame(3, (int) $sc->RemainingSessions, '正常課堂整堂扣 1');
        $this->assertSame(1, (int) $sc->UsedSessions);
    }

    /** 課程列表端點：部分扣堂後不可被 count-based 覆寫，且回傳精確 remaining_minutes。 */
    public function test_course_list_exposes_fractional_remaining_without_clobber(): void
    {
        [$token, $student, $scId] = $this->seedCourse(120, 4);
        $cs = $this->makeMakeupSession($scId, '23:00', '00:30'); // 90 min
        $this->postAttendance($token, $student, $scId, $cs->id)->assertCreated();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/student-classes?branch_id=1&per_page=100');
        $res->assertOk();

        $rows = collect($res->json('data') ?? $res->json());
        $row = $rows->first(fn ($r) => (int) ($r['ID'] ?? $r['id'] ?? 0) === $scId);
        $this->assertNotNull($row, '課程應出現在列表');
        $this->assertSame(390, (int) $row['remaining_minutes'], '精確剩餘分鐘');
        $this->assertSame(3, (int) $row['remaining_sessions'], 'ROUND_HALF_UP 衍生值未被 count-based 覆寫');
    }

    // ── helpers ──

    /** @return array{0:string,1:Student,2:int} [token, student, studentClassId] */
    private function seedCourse(int $durationMinutes, int $sessions): array
    {
        $teacherId = $this->mkUser('T', 'pm-t' . uniqid() . '@e.com');
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacherId, 'Admin' => 0, 'Approved' => 1]);

        $dirId = $this->mkUser('A', 'pm-d' . uniqid() . '@e.com');
        UserCampus::create(['CampusID' => 1, 'UserID' => $dirId, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dirId, 'token' => $token, 'expires_at' => now()->addDay()]);

        $student = Student::create([
            'name' => '補課' . uniqid(), 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);

        $sc = StudentClass::create([
            'StudentID' => $student->id, 'TeacherID' => $teacherId, 'GradeID' => 1, 'SubjectID' => 1,
            'ClassType' => 'one_on_one', 'ScheduleMode' => 'count',
            'SessionCount' => $sessions, 'SessionDuration' => $durationMinutes,
            'RemainingSessions' => $sessions, 'UsedSessions' => 0,
            'Rate' => 500, 'TotalHours' => 20, 'Charge' => 5000, 'Pay' => 5000, 'Paid' => 0,
            'Stop' => 0, 'by1' => 0, 'RoomID' => 'R1', 'MDate' => now(),
            'StartDate' => Carbon::now()->subMonth()->toDateString(),
            'EndDate' => Carbon::now()->addMonths(3)->toDateString(),
        ]);

        return [$token, $student, (int) $sc->ID];
    }

    private function mkUser(string $type, string $login): int
    {
        return (int) User::create([
            'LoginName' => $login, 'Name' => $type, 'PSW' => 'secret',
            'type' => $type, 'phone' => '0900000000', 'MustChangePassword' => false,
        ])->id;
    }

    private function makeMakeupSession(int $scId, string $start, string $end): ClassSession
    {
        $date = Carbon::yesterday()->toDateString();
        DB::table('schedules')->insert([
            'student_id'        => 0,
            'teacher_id'        => 0,
            'subject'           => 'Math',
            'day_of_week'       => Carbon::parse($date)->dayOfWeekIso,
            'start_time'        => $start . ':00',
            'end_time'          => $end . ':00',
            'class_type'        => 'one_on_one',
            'status'            => 'scheduled',
            'type'              => 'extra',
            'deduction'         => 1,
            'branch_id'         => 1,
            'schedule_date'     => $date,
            'student_course_id' => $scId,
            'original_schedule_id' => 0,
        ]);

        return ClassSession::create([
            'StudentClassID' => $scId,
            'SessionDate'    => $date,
            'StartTime'      => $start . ':00',
            'EndTime'        => $end . ':00',
            'Status'         => 'scheduled',
        ]);
    }

    private function postAttendance(string $token, Student $student, int $scId, int $csId)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID'      => $student->id,
            'StudentClassID' => $scId,
            'ClassSessionID' => $csId,
            'Status'         => 'present',
        ]);
    }
}
