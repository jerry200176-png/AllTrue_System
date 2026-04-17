<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for 單堂時間費率自動計算 (per-session charge auto-calculation).
 *
 * Rules under test:
 *   session mode: session_charge = round(Rate × actual_minutes / SessionDuration)
 *   hour mode:    session_charge = round(Rate × actual_minutes / 60)
 *   StudentClass.Charge += (new_session_charge - baseline)
 *     baseline = existing session_charge, or standard charge when null
 *   SessionDuration=0 or Rate<=0 → no-op
 */
class ClassSessionChargeTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_mode_shrink_time_reduces_charge(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession(1500, 120, 'session');
        $originalCharge = (int) StudentClass::find($courseId)->Charge;

        $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '17:30',
        ])->assertOk();

        $session->refresh();
        $this->assertSame(1125, (int) $session->session_charge);

        $sc = StudentClass::find($courseId);
        $this->assertSame($originalCharge - 375, (int) $sc->Charge);
    }

    public function test_session_mode_extend_time_increases_charge(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession(1500, 120, 'session');
        $originalCharge = (int) StudentClass::find($courseId)->Charge;

        $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '19:00',
        ])->assertOk();

        $session->refresh();
        $this->assertSame(2250, (int) $session->session_charge);

        $sc = StudentClass::find($courseId);
        $this->assertSame($originalCharge + 750, (int) $sc->Charge);
    }

    public function test_hour_mode_shrink_time(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession(750, 120, 'hour');
        $originalCharge = (int) StudentClass::find($courseId)->Charge;

        $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '17:30',
        ])->assertOk();

        $session->refresh();
        $this->assertSame(1125, (int) $session->session_charge);

        $standardCharge = (int) round(750 * (120 / 60));
        $sc = StudentClass::find($courseId);
        $this->assertSame($originalCharge + (1125 - $standardCharge), (int) $sc->Charge);
    }

    public function test_zero_session_duration_is_noop(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession(1500, 120, 'session');

        StudentClass::where('ID', $courseId)->update(['SessionDuration' => 0]);
        $originalCharge = (int) StudentClass::find($courseId)->Charge;

        $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '17:30',
        ])->assertOk();

        $session->refresh();
        $this->assertNull($session->session_charge);
        $this->assertSame($originalCharge, (int) StudentClass::find($courseId)->Charge);
    }

    public function test_second_edit_uses_previous_session_charge_as_baseline(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession(1500, 120, 'session');
        $originalCharge = (int) StudentClass::find($courseId)->Charge;

        // First edit: 120 → 90 minutes. delta = 1125 - 1500 = -375
        $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '17:30',
        ])->assertOk();

        $afterFirst = (int) StudentClass::find($courseId)->Charge;
        $this->assertSame($originalCharge - 375, $afterFirst);

        // Second edit: 90 → 180 minutes. delta = 2250 - 1125 = +1125
        $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '19:00',
        ])->assertOk();

        $session->refresh();
        $this->assertSame(2250, (int) $session->session_charge);

        $afterSecond = (int) StudentClass::find($courseId)->Charge;
        $this->assertSame($afterFirst + 1125, $afterSecond);
    }

    public function test_note_only_update_does_not_touch_charge(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession(1500, 120, 'session');
        $originalCharge = (int) StudentClass::find($courseId)->Charge;

        $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'note' => '家長來電',
        ])->assertOk();

        $session->refresh();
        $this->assertNull($session->session_charge);
        $this->assertSame($originalCharge, (int) StudentClass::find($courseId)->Charge);
    }

    public function test_response_includes_session_charge(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession(1500, 120, 'session');

        $response = $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '17:30',
        ])->assertOk();

        $response->assertJsonPath('session.session_charge', 1125);
    }

    public function test_per_day_duration_is_used_when_set_on_session_weekday(): void
    {
        // 建一個課程預設 SessionDuration=120（week=3 Wed），
        // 手動加上 Friday 每週一堂、per-day duration=90：
        //   Mon session 改 75min → 以 120 為基準 → 1500 × 75/120 = 938
        //   Fri session 改 75min → 以 90  為基準 → 1500 × 75/90  = 1250
        // 驗證 duration1~duration6 對應星期會被優先採用。
        [$token, $courseId, $session] = $this->setupCourseWithSession(1500, 120, 'session');

        // 安排：week1=3(Wed)/duration1=120；week2=5(Fri)/duration2=90
        StudentClass::where('ID', $courseId)->update([
            'week1' => 3, 'duration1' => 120,
            'week2' => 5, 'duration2' => 90,
        ]);

        // 建立一個 Friday 的堂次（同課程，手動插入）
        $friday = Carbon::now()->next(Carbon::FRIDAY)->toDateString();
        $fridaySession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $friday,
            'StartTime' => '16:00',
            'EndTime' => '17:30',
            'Status' => 'scheduled',
        ]);

        $this->patchSession($token, $fridaySession->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '17:15',
        ])->assertOk();

        $fridaySession->refresh();
        // Friday 標準是 90 分鐘 → 1500 × (75/90) = 1250
        $this->assertSame(1250, (int) $fridaySession->session_charge);
    }

    public function test_course_rate_update_preserves_accumulated_session_charge_delta(): void
    {
        // 先建 Rate=1500 × SessionCount=4 的課程 → Charge=6000
        [$token, $courseId, $session] = $this->setupCourseWithSession(1500, 120, 'session');
        StudentClass::where('ID', $courseId)->update(['SessionCount' => 4, 'Charge' => 6000]);

        // 單堂 2hr → 1.5hr 累積 delta = −375
        $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '17:30',
        ])->assertOk();

        $this->assertSame(5625, (int) StudentClass::find($courseId)->Charge);

        // 主任把 Rate 調高到 2000
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$courseId}", [
            'rate_per_30min' => 2000,
            'sessions_purchased' => 4,
        ])->assertOk();

        // 業界慣例：累積的 −375 手動調整必須保留，不被整筆 Rate×SessionCount 洗掉
        // new_base = 2000 × 4 = 8000；preserved_delta = 5625 − 6000 = −375；new_Charge = 7625
        $this->assertSame(7625, (int) StudentClass::find($courseId)->Charge);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function setupCourseWithSession(int $rate, int $durationMinutes, string $rateUnit): array
    {
        $token = $this->createDirectorToken([1], 'dir-charge-' . uniqid() . '@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-charge-' . uniqid() . '@example.com');
        $student = $this->createStudent(1, '費率學生' . uniqid());

        $courseId = $this->createCourseWithSessions($token, $student->id, $teacherId, $rate, $durationMinutes, $rateUnit);

        // rate_unit isn't in the batch-store payload; enforce it directly
        StudentClass::where('ID', $courseId)->update(['rate_unit' => $rateUnit]);

        $session = ClassSession::where('StudentClassID', $courseId)->first();
        $session->Status = 'scheduled';
        $session->save();

        return [$token, $courseId, $session];
    }

    private function createCourseWithSessions(string $token, int $studentId, int $teacherId, int $rate, int $durationMinutes, string $rateUnit): int
    {
        $firstWednesday = Carbon::now()->next(Carbon::WEDNESDAY);
        $futureDates = [];
        for ($i = 0; $i < 4; $i++) {
            $futureDates[] = $firstWednesday->copy()->addWeeks($i)->toDateString();
        }

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id'         => 1,
            'student_id'        => $studentId,
            'teacher_id'        => $teacherId,
            'subject'           => 'Math',
            'class_type'        => 'one_on_one',
            'total_classes'     => 4,
            'confirmed_dates'   => [],
            'future_dates'      => $futureDates,
            'days_of_week'      => [3],
            'duration_minutes'  => $durationMinutes,
            'price_per_session' => $rate,
            'rate_unit'         => $rateUnit,
            'payment_type'      => 'session',
            'start_time'        => '16:00',
        ])->assertCreated();

        return (int) DB::table('StudentClass')
            ->where('StudentID', $studentId)
            ->where('TeacherID', $teacherId)
            ->max('ID');
    }

    private function patchSession(string $token, int $sessionId, array $data)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/class-sessions/{$sessionId}", $data);
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
            'MustChangePassword' => false,
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName,
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922000000',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        return (int) $teacher->id;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }
}
