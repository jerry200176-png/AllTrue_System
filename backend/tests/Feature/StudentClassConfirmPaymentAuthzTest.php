<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Regression for #1504: confirmPayment() had no authorization check, letting
// any director/teacher mark another campus's course as paid by guessing the
// StudentClassID (cross-campus billing IDOR).
class StudentClassConfirmPaymentAuthzTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_payment_forbidden_for_director_outside_course_campus(): void
    {
        $token = $this->createDirectorToken([2]);
        $sc = $this->createStudentClassOnCampus(1);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/confirm-payment");

        $res->assertStatus(403);
        $this->assertSame(0, $sc->fresh()->Paid);
    }

    public function test_confirm_payment_forbidden_for_teacher_not_owning_course(): void
    {
        $token = $this->createTeacherToken([1]);
        // TeacherID deliberately does not match the authenticated teacher's real id.
        $sc = $this->createStudentClassOnCampus(1, ['TeacherID' => 999999]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/confirm-payment");

        $res->assertStatus(403);
        $this->assertSame(0, $sc->fresh()->Paid);
    }

    public function test_confirm_payment_succeeds_for_director_in_same_campus(): void
    {
        $token = $this->createDirectorToken([1]);
        $sc = $this->createStudentClassOnCampus(1);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/confirm-payment");

        $res->assertOk()->assertJsonPath('message', '已確認繳費');
        $this->assertSame(1, $sc->fresh()->Paid);
    }

    // ── helpers ──

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-payauthz-' . uniqid() . '@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function createTeacherToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'teach-payauthz-' . uniqid() . '@test.com',
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0912345679',
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function createStudentClassOnCampus(int $campusId, array $overrides = []): StudentClass
    {
        $student = Student::create([
            'name' => '繳費授權測試生-' . uniqid(),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }
}
