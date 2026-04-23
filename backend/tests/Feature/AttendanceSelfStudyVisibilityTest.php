<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-001 / FR-002：self_study 記錄（StudentClassID = null）
 * 在 LEFT JOIN 修復後應出現在 GET /api/v1/attendance 的回傳中，
 * 且現有課堂出勤記錄查詢不受影響（回歸）。
 */
class AttendanceSelfStudyVisibilityTest extends TestCase
{
    use RefreshDatabase;

    // ── FR-001: self_study 記錄出現在 API response ───────────────────────────

    public function test_self_study_record_appears_in_attendance_index(): void
    {
        [$token, $student] = $this->scaffold();

        StudentSignIn::create([
            'StudentID'       => $student->id,
            'StudentClassID'  => null,
            'TeacherID'       => null,
            'SubjectID'       => null,
            'Memo'            => 'self_study',
            'SignInDT'        => now()->toDateTimeString(),
            'SignOutDT'       => now()->addHours(2)->toDateTimeString(),
            'Status'          => 'present',
            'ClassSessionID'  => null,
            'CampusID'        => 1,
            'PersonType'      => 'student',
            'SessionDeducted' => false,
            'MDT'             => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/attendance?date=' . now()->toDateString() . '&branch_id=1');

        $res->assertOk();
        $data = collect($res->json('data'));
        $selfStudy = $data->first(fn($r) => ($r['Memo'] ?? null) === 'self_study');

        $this->assertNotNull($selfStudy, 'self_study 記錄應出現在 API 回傳');
        $this->assertSame('self_study', $selfStudy['Memo']);
        $this->assertNull($selfStudy['StudentClassID']);
    }

    // ── FR-002: API response 含 Memo 欄位 ────────────────────────────────────

    public function test_api_response_includes_memo_field(): void
    {
        [$token, $student] = $this->scaffold();

        StudentSignIn::create([
            'StudentID'       => $student->id,
            'StudentClassID'  => null,
            'Memo'            => 'self_study',
            'SignInDT'        => now()->toDateTimeString(),
            'Status'          => 'present',
            'CampusID'        => 1,
            'PersonType'      => 'student',
            'SessionDeducted' => false,
            'MDT'             => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/attendance?date=' . now()->toDateString() . '&branch_id=1');

        $res->assertOk();
        $row = collect($res->json('data'))->first();
        $this->assertNotNull($row);
        $this->assertArrayHasKey('Memo', $row, 'API response 應包含 Memo 欄位');
    }

    // ── Regression: 現有課堂出勤記錄不受影響 ─────────────────────────────────

    public function test_course_attendance_record_still_appears_with_left_join(): void
    {
        [$token, $student, $teacherId] = $this->scaffold();

        $course = StudentClass::create([
            'StudentID'         => $student->id,
            'GradeID'           => 1,
            'SubjectID'         => null,
            'TeacherID'         => $teacherId,
            'by1'               => 1,
            'Period'            => 4,
            'StartDate'         => '2026-01-01',
            'TotalHours'        => 20,
            'Memo'              => '',
            'Paid'              => 0,
            'Stop'              => 0,
            'ScheduleMode'      => 'count',
            'SessionCount'      => 8,
            'RemainingSessions' => 8,
            'UsedSessions'      => 0,
            'SessionDuration'   => 120,
            'ClassType'         => 'one_on_one',
            'RoomID'            => 'R1',
            'MDate'             => now(),
            'Rate'              => 500,
        ]);

        StudentSignIn::create([
            'StudentID'       => $student->id,
            'StudentClassID'  => $course->ID,
            'TeacherID'       => $teacherId,
            'Memo'            => 'swipe-rfid',
            'SignInDT'        => now()->toDateTimeString(),
            'Status'          => 'present',
            'CampusID'        => 1,
            'PersonType'      => 'student',
            'SessionDeducted' => true,
            'MDT'             => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/attendance?date=' . now()->toDateString() . '&branch_id=1');

        $res->assertOk();
        $courseRecord = collect($res->json('data'))
            ->first(fn($r) => ($r['Memo'] ?? null) === 'swipe-rfid');

        $this->assertNotNull($courseRecord, '課堂出勤記錄仍應出現（LEFT JOIN 回歸）');
        $this->assertSame($course->ID, $courseRecord['StudentClassID']);
    }

    // ── 同日混合：課堂 + 自修並存 ────────────────────────────────────────────

    public function test_course_and_self_study_coexist_in_same_response(): void
    {
        [$token, $student, $teacherId] = $this->scaffold();

        $course = StudentClass::create([
            'StudentID'         => $student->id,
            'GradeID'           => 1,
            'SubjectID'         => null,
            'TeacherID'         => $teacherId,
            'by1'               => 1,
            'Period'            => 4,
            'StartDate'         => '2026-01-01',
            'TotalHours'        => 20,
            'Memo'              => '',
            'Paid'              => 0,
            'Stop'              => 0,
            'ScheduleMode'      => 'count',
            'SessionCount'      => 8,
            'RemainingSessions' => 8,
            'UsedSessions'      => 0,
            'SessionDuration'   => 120,
            'ClassType'         => 'one_on_one',
            'RoomID'            => 'R1',
            'MDate'             => now(),
            'Rate'              => 500,
        ]);

        StudentSignIn::create([
            'StudentID'       => $student->id,
            'StudentClassID'  => $course->ID,
            'Memo'            => 'swipe-rfid',
            'SignInDT'        => now()->subHours(4)->toDateTimeString(),
            'Status'          => 'present',
            'CampusID'        => 1,
            'PersonType'      => 'student',
            'SessionDeducted' => true,
            'MDT'             => now(),
        ]);

        StudentSignIn::create([
            'StudentID'       => $student->id,
            'StudentClassID'  => null,
            'Memo'            => 'self_study',
            'SignInDT'        => now()->subHours(1)->toDateTimeString(),
            'Status'          => 'present',
            'CampusID'        => 1,
            'PersonType'      => 'student',
            'SessionDeducted' => false,
            'MDT'             => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/attendance?date=' . now()->toDateString() . '&branch_id=1');

        $res->assertOk();
        $data = collect($res->json('data'));

        $this->assertNotNull(
            $data->first(fn($r) => ($r['Memo'] ?? null) === 'swipe-rfid'),
            '課堂記錄應出現'
        );
        $this->assertNotNull(
            $data->first(fn($r) => ($r['Memo'] ?? null) === 'self_study'),
            '自修記錄應並存'
        );
    }

    // ── Scaffolding ───────────────────────────────────────────────────────────

    private function scaffold(): array
    {
        Campus::firstOrCreate(['id' => 1], ['name' => '測試分校', 'code' => 'T1', 'Current' => 1]);

        $user = User::create([
            'LoginName'          => 'dir-selfstudy@example.com',
            'Name'               => '主任',
            'PSW'                => 'secret',
            'type'               => 'A',
            'phone'              => '0900111000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);

        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        $teacher = User::create([
            'LoginName'          => 'tch-selfstudy@example.com',
            'Name'               => '老師',
            'PSW'                => 'secret',
            'type'               => 'T',
            'phone'              => '0911111000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name'         => '自修測試生',
            'CampusID'     => 1,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);

        return [$raw, $student, (int) $teacher->id];
    }
}
