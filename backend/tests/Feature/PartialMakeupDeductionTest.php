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
 * #613 A1 PR3：補課非標準時長按實際分鐘扣堂（HTTP E2E）。
 * 業務規則：補課（schedules.type='extra'）時長 ≠ 契約每堂分鐘時，扣實際分鐘
 * （可短於或長於 perSession）；剛好完整時長與正常課堂一律整堂。
 */
class PartialMakeupDeductionTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\DataProvider('standaloneMakeupRowStatuses')]
    public function test_retry_preserves_the_original_standalone_makeup(string $existingStatus, string $retryStatus, string $firstStart, string $retryStart, string $storedType, string $retryType): void
    {
        [$token, $student, $courseId] = $this->seedCourse(120, 4);
        $date = Carbon::tomorrow();
        $slot = [
            'student_id' => $student->id, 'student_course_id' => $courseId,
            'schedule_date' => $date->toDateString(), 'day_of_week' => $date->dayOfWeekIso,
            'branch_id' => 1, 'start_time' => $firstStart, 'end_time' => '10:05',
            'type' => $storedType, 'status' => $existingStatus,
        ];
        $this->withToken($token)->postJson('/api/v1/schedules', $slot)->assertCreated();
        foreach (['10:05', '10:35'] as $end) {
            $this->withToken($token)->postJson('/api/v1/schedules', [
                ...$slot, 'start_time' => $retryStart, 'end_time' => $end, 'status' => $retryStatus, 'type' => $retryType,
            ])->assertConflict()->assertJsonPath('code', 'makeup_already_scheduled');
        }
        $this->assertDatabaseCount('schedules', 1);
        $this->assertDatabaseHas('schedules', ['student_course_id' => $courseId, 'status' => $existingStatus, 'end_time' => '10:05']);
        $this->assertDatabaseCount('ClassSession', 1);
        $this->assertDatabaseHas('ClassSession', ['StudentClassID' => $courseId, 'EndTime' => '10:05:00']);

        $this->withToken($token)->postJson('/api/v1/schedules', [
            ...$slot, 'start_time' => '16:00', 'end_time' => '17:00', 'status' => 'scheduled',
        ])->assertCreated();
        $this->assertDatabaseCount('schedules', 2);
        $this->assertSame(4, (int) StudentClass::findOrFail($courseId)->RemainingSessions);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('makeupSourceTypes')]
    public function test_an_atomic_move_frees_the_original_makeup_slot(string $storedType): void
    {
        [$token, $student, $courseId] = $this->seedCourse(120, 4);
        $date = Carbon::tomorrow();
        $newDate = $date->copy()->addDay()->toDateString();
        $slot = [
            'student_id' => $student->id, 'student_course_id' => $courseId,
            'schedule_date' => $date->toDateString(), 'day_of_week' => $date->dayOfWeekIso,
            'branch_id' => 1, 'start_time' => '14:00', 'end_time' => '15:00',
            'type' => $storedType, 'status' => 'scheduled',
        ];
        $sourceId = $this->withToken($token)->postJson('/api/v1/schedules', $slot)->assertCreated()->json('id');
        $this->withToken($token)->postJson('/api/v1/learning-records/reschedule-session', [
            'student_class_id' => $courseId, 'old_date' => $date->toDateString(), 'old_start_time' => '14:00',
            'new_date' => $newDate, 'start_time' => '16:00', 'end_time' => '17:00', 'ensure_schedule_exception' => true,
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/schedules', $slot)->assertCreated();
        $destination = [...$slot, 'schedule_date' => $newDate, 'day_of_week' => $date->copy()->addDay()->dayOfWeekIso,
            'start_time' => '16:00', 'end_time' => '18:00'];
        $this->withToken($token)->postJson('/api/v1/schedules', $destination)
            ->assertConflict()->assertJsonPath('code', 'makeup_already_scheduled');
        $this->assertDatabaseHas('schedules', ['id' => $sourceId, 'status' => 'rescheduled']);
        $this->assertDatabaseHas('schedules', ['original_schedule_id' => $sourceId, 'status' => 'scheduled', 'schedule_date' => $newDate]);
        $this->assertDatabaseCount('schedules', 3);
        $this->assertDatabaseHas('ClassSession', ['StudentClassID' => $courseId, 'SessionDate' => $date->toDateString(), 'Status' => 'scheduled']);
        $this->assertDatabaseHas('ClassSession', ['StudentClassID' => $courseId, 'SessionDate' => $newDate, 'EndTime' => '17:00:00']);

        $thirdDate = $date->copy()->addDays(2);
        $this->withToken($token)->postJson('/api/v1/learning-records/reschedule-session', [
            'student_class_id' => $courseId, 'old_date' => $newDate, 'old_start_time' => '16:00',
            'new_date' => $thirdDate->toDateString(), 'start_time' => '12:00', 'end_time' => '13:00', 'ensure_schedule_exception' => true,
        ])->assertOk();
        $this->withToken($token)->postJson('/api/v1/schedules', [
            ...$slot, 'schedule_date' => $thirdDate->toDateString(), 'day_of_week' => $thirdDate->dayOfWeekIso,
            'start_time' => '12:00', 'end_time' => '14:00',
        ])->assertConflict()->assertJsonPath('code', 'makeup_already_scheduled');
        $this->withToken($token)->postJson('/api/v1/schedules', $destination)->assertCreated();
        $this->assertDatabaseHas('ClassSession', ['StudentClassID' => $courseId, 'SessionDate' => $thirdDate->toDateString(), 'EndTime' => '13:00:00']);
    }

    public function test_retired_makeup_does_not_block_a_new_booking(): void
    {
        [$token, $student, $courseId] = $this->seedCourse(120, 4);
        $date = Carbon::tomorrow();
        $slot = [
            'student_id' => $student->id, 'student_course_id' => $courseId,
            'schedule_date' => $date->toDateString(), 'day_of_week' => $date->dayOfWeekIso,
            'branch_id' => 1, 'start_time' => '14:00', 'end_time' => '15:00',
            'type' => 'extra', 'status' => 'scheduled',
        ];
        DB::table('schedules')->insert([...$slot, 'status' => 'superseded']);

        $this->withToken($token)->postJson('/api/v1/schedules', $slot)->assertCreated();
        $this->assertDatabaseCount('schedules', 2);
    }

    public static function makeupSourceTypes(): array
    {
        return [['extra'], ['EXTRA']];
    }

    public static function standaloneMakeupRowStatuses(): array
    {
        return [
            ['scheduled', 'scheduled', '9:05', '09:05:00', 'extra', 'EXTRA'],
            ['rescheduled', 'scheduled', '09:05:00', '9:05', 'EXTRA', 'extra'],
            ['scheduled', 'rescheduled', '9:05:10', '09:05', 'EXTRA', 'Extra'],
            ['rescheduled', 'rescheduled', '09:05', '9:05:15', 'eXtRa', 'EXTRA'],
        ];
    }

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

    /** 加長補課（180/120）→ 扣 180 分，不可仍固定整堂 1。 */
    public function test_longer_makeup_attendance_deducts_actual_minutes(): void
    {
        [$token, $student, $scId] = $this->seedCourse(120, 4);
        $cs = $this->makeMakeupSession($scId, '14:00', '17:00'); // 180 min

        $this->postAttendance($token, $student, $scId, $cs->id)->assertCreated();

        $sc = StudentClass::findOrFail($scId);
        $this->assertSame(300, (int) $sc->RemainingMinutes, '4×120 − 180 = 300');
        $this->assertSame(480, (int) $sc->PurchasedMinutes);
        $this->assertSame(3, (int) $sc->RemainingSessions, 'ROUND_HALF_UP(300/120)=3');
        $this->assertSame(1, (int) $sc->UsedSessions);
        $this->assertSame(180, (int) SessionDeductionLedger::where('student_class_id', $scId)
            ->where('event_type', 'deduct')->value('minutes'));
    }

    /** 加長補課：重試不重複扣；reverse 沖回 180；再扣仍只一筆有效淨扣除。 */
    public function test_longer_makeup_deduct_reverse_is_idempotent_and_restores_minutes(): void
    {
        [, , $scId] = $this->seedCourse(120, 4);
        $cs = $this->makeMakeupSession($scId, '14:00', '17:00');
        $sc = StudentClass::findOrFail($scId);

        $this->assertTrue(\App\Services\SessionDeductionService::deductOnAttendance($sc, null, (int) $cs->id));
        $sc->refresh();
        $this->assertFalse(\App\Services\SessionDeductionService::deductOnAttendance($sc, null, (int) $cs->id));
        $this->assertSame(180, (int) SessionDeductionLedger::where('student_class_id', $scId)
            ->where('event_type', 'deduct')->value('minutes'));
        $this->assertSame(300, (int) StudentClass::findOrFail($scId)->RemainingMinutes);

        $this->assertTrue(\App\Services\SessionDeductionService::reverseForSession($scId, (int) $cs->id, 'status_adjust'));
        $this->assertFalse(\App\Services\SessionDeductionService::reverseForSession($scId, (int) $cs->id, 'status_adjust'));
        \App\Services\SessionDeductionService::recomputeCounters($scId);
        $this->assertSame(480, (int) StudentClass::findOrFail($scId)->RemainingMinutes);
        $this->assertSame(180, (int) SessionDeductionLedger::where('student_class_id', $scId)
            ->where('event_type', 'reverse')->value('minutes'));

        $sc = StudentClass::findOrFail($scId);
        $this->assertTrue(\App\Services\SessionDeductionService::deductOnAttendance($sc, null, (int) $cs->id));
        $this->assertSame(2, (int) SessionDeductionLedger::where('student_class_id', $scId)
            ->where('event_type', 'deduct')->count());
        $this->assertSame(300, (int) StudentClass::findOrFail($scId)->RemainingMinutes);
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

    /** 正常課堂加長（非 type='extra'）亦不自動多扣，一律整堂。 */
    public function test_normal_longer_session_not_prorated(): void
    {
        [$token, $student, $scId] = $this->seedCourse(120, 4);
        $cs = ClassSession::create([
            'StudentClassID' => $scId,
            'SessionDate'    => Carbon::yesterday()->toDateString(),
            'StartTime'      => '14:00',
            'EndTime'        => '17:00',
            'Status'         => 'scheduled',
        ]);

        $this->postAttendance($token, $student, $scId, $cs->id)->assertCreated();

        $sc = StudentClass::findOrFail($scId);
        $this->assertSame(3, (int) $sc->RemainingSessions, '正常課堂加長仍整堂扣 1');
        $this->assertNull(SessionDeductionLedger::where('student_class_id', $scId)
            ->where('event_type', 'deduct')->value('minutes'));
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
            'Stop' => 0, 'by1' => 0, 'MDate' => now(),
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
