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
 * Rules under test (must stay aligned with SessionEditModal / SmartCalendar copy):
 *   session mode: session_charge = round(Rate)  — fixed; time change does NOT alter Charge
 *   hour mode:    session_charge = round(Rate × actual_minutes / 60)
 *                 StudentClass.Charge += (new_session_charge - baseline)
 *     baseline = existing session_charge, or standard charge when null
 *   SessionDuration=0 or Rate<=0 → no-op
 */
class ClassSessionChargeTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_mode_time_change_keeps_fixed_charge(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession(1500, 120, 'session');
        $originalCharge = (int) StudentClass::find($courseId)->Charge;

        $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '17:30',
        ])->assertOk();

        $session->refresh();
        $this->assertSame(1500, (int) $session->session_charge);

        $sc = StudentClass::find($courseId);
        $this->assertSame($originalCharge, (int) $sc->Charge);
    }

    public function test_session_mode_extend_time_does_not_increase_charge(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession(1500, 120, 'session');
        $originalCharge = (int) StudentClass::find($courseId)->Charge;

        $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '19:00',
        ])->assertOk();

        $session->refresh();
        $this->assertSame(1500, (int) $session->session_charge);

        $sc = StudentClass::find($courseId);
        $this->assertSame($originalCharge, (int) $sc->Charge);
    }

    public function test_hour_mode_shrink_time_updates_course_charge(): void
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

    public function test_hour_mode_extend_time_updates_course_charge(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession(750, 120, 'hour');
        $originalCharge = (int) StudentClass::find($courseId)->Charge;

        // 120 → 180 minutes：session_charge = 750 × 3 = 2250；baseline = 1500；delta = +750
        $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '19:00',
        ])->assertOk();

        $session->refresh();
        $this->assertSame(2250, (int) $session->session_charge);

        $standardCharge = (int) round(750 * (120 / 60));
        $sc = StudentClass::find($courseId);
        $this->assertSame($originalCharge + (2250 - $standardCharge), (int) $sc->Charge);
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

    public function test_session_mode_second_edit_stays_fixed_at_rate(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession(1500, 120, 'session');
        $originalCharge = (int) StudentClass::find($courseId)->Charge;

        $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '17:30',
        ])->assertOk();

        $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '19:00',
        ])->assertOk();

        $session->refresh();
        $this->assertSame(1500, (int) $session->session_charge);
        $this->assertSame($originalCharge, (int) StudentClass::find($courseId)->Charge);
    }

    public function test_hour_mode_second_edit_uses_previous_session_charge_as_baseline(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession(750, 120, 'hour');
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

    public function test_response_includes_fixed_session_charge_for_session_mode(): void
    {
        [$token, $courseId, $session] = $this->setupCourseWithSession(1500, 120, 'session');

        $response = $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '17:30',
        ])->assertOk();

        $response->assertJsonPath('session.session_charge', 1500);
    }

    public function test_session_mode_ignores_per_day_duration_for_charge(): void
    {
        // 按堂計費：即使週五契約時長 90 分，改時段後 session_charge 仍固定 = Rate。
        [$token, $courseId, $session] = $this->setupCourseWithSession(1500, 120, 'session');

        StudentClass::where('ID', $courseId)->update([
            'week1' => 3, 'duration1' => 120,
            'week2' => 5, 'duration2' => 90,
        ]);

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
        $this->assertSame(1500, (int) $fridaySession->session_charge);
    }

    public function test_course_rate_update_preserves_hour_mode_session_charge_delta(): void
    {
        // hour mode：Rate=750/hr × TotalHours=8 → Charge=6000
        [$token, $courseId, $session] = $this->setupCourseWithSession(750, 120, 'hour');
        StudentClass::where('ID', $courseId)->update([
            'SessionCount' => 4,
            'TotalHours' => 8,
            'Charge' => 6000,
        ]);

        // 單堂 2hr → 1.5hr：session_charge 1125，baseline 1500，delta = −375
        $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '17:30',
        ])->assertOk();

        $this->assertSame(5625, (int) StudentClass::find($courseId)->Charge);

        // 主任把時薪調高到 1000
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$courseId}", [
            'rate_per_30min' => 1000,
            'sessions_purchased' => 4,
            'rate_unit' => 'hour',
        ])->assertOk();

        // new_base = 1000 × 8 = 8000；preserved_delta = 5625 − 6000 = −375；new_Charge = 7625
        $this->assertSame(7625, (int) StudentClass::find($courseId)->Charge);
    }

    public function test_session_mode_corrects_stale_scaled_session_charge_without_new_duration_delta(): void
    {
        // 歷史錯誤：session mode 曾被寫成比例縮放值；再改時段時應拉回 Rate，並修正 Charge 偏差。
        [$token, $courseId, $session] = $this->setupCourseWithSession(1500, 120, 'session');
        StudentClass::where('ID', $courseId)->update(['Charge' => 6000]);
        $session->session_charge = 2250; // 舊的 180min 縮放值
        $session->save();

        $this->patchSession($token, $session->id, [
            'status' => 'scheduled',
            'start_time' => '16:00',
            'end_time' => '19:00',
        ])->assertOk();

        $session->refresh();
        $this->assertSame(1500, (int) $session->session_charge);
        // delta = 1500 - 2250 = -750
        $this->assertSame(5250, (int) StudentClass::find($courseId)->Charge);
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
