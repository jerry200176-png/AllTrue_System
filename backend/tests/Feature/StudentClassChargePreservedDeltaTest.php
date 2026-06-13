<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bug #798 regression guard (in-app #159 張圉庭 SC#422)：
 * update() 的 preservedDelta 只能保留「真的來自單堂時間調整（session_charge）」的差額。
 * 當 Charge 與 Rate×SessionCount 的差額來自錯誤存量資料（無任何 session_charge 紀錄）時，
 * 主任在 UI 重存費率必須能把 Charge 重算回 Rate×數量，不可永遠被 delta 拉回錯誤金額。
 */
class StudentClassChargePreservedDeltaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 錯誤存量資料：Charge=8000 但 Rate×SessionCount=8800，且課程沒有任何
     * session_charge 調整 → 重存費率後 Charge 必須重算為 8800（修復前永遠回到 8000）。
     */
    public function test_bogus_charge_delta_without_session_adjustments_is_recalculated(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Rate' => 1100,
            'rate_unit' => 'session',
            'SessionCount' => 8,
            'Charge' => 8000, // 錯誤存量資料（應為 8800）
        ]);

        $res = $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['rate_per_30min' => 1100, 'sessions_purchased' => 8],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $sc->refresh();
        $this->assertSame(8800, (int) $sc->Charge,
            '無 session_charge 調整時，重存費率必須以 Rate×SessionCount 重算 Charge');
    }

    /**
     * 合法差額：課程存在 session_charge 單堂調整 → 調整費率時 delta 仍須保留
     * （原 preservedDelta 設計，不可因 #798 修復而回歸）。
     */
    public function test_legit_session_charge_delta_is_still_preserved_on_rate_change(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Rate' => 1100,
            'rate_unit' => 'session',
            'SessionCount' => 8,
            'Charge' => 8500, // 8800 − 300，差額來自下面的單堂時間調整
        ]);

        DB::table('ClassSession')->insert([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-06-01',
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => 'attended',
            'session_charge' => 800, // 單堂時間調整紀錄（非 NULL 即代表有合法微調）
        ]);

        $res = $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['rate_per_30min' => 1200, 'sessions_purchased' => 8],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $sc->refresh();
        // newBase 9600 + preservedDelta(8500−8800=−300) = 9300
        $this->assertSame(9300, (int) $sc->Charge,
            '有 session_charge 調整時，費率異動仍須保留原差額');
    }

    /**
     * 無差額的正常課程：重存費率行為不變（Rate×SessionCount）。
     */
    public function test_clean_course_rate_change_recalculates_normally(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, [
            'Rate' => 1100,
            'rate_unit' => 'session',
            'SessionCount' => 8,
            'Charge' => 8800,
        ]);

        $res = $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['rate_per_30min' => 1200, 'sessions_purchased' => 8],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $sc->refresh();
        $this->assertSame(9600, (int) $sc->Charge);
    }

    // ── Helpers（對齊 StudentClassPaidStatusTest，避免 NOT NULL 地雷）──

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'charge-delta-test-' . uniqid() . '@example.com',
            'Name' => '測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0900000000',
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

    private function createStudent(): Student
    {
        return Student::create([
            'name' => '費用測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        $defaults = [
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'TotalHours' => 16,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ];

        return StudentClass::create(array_merge($defaults, $overrides));
    }
}
