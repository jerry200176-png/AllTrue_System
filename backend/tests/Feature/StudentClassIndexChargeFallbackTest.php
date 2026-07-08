<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentClassIndexChargeFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_count_course_with_zero_charge_returns_effective_charge_from_rate_and_session_count(): void
    {
        $director = User::create([
            'LoginName' => 'director-charge-fallback@example.com',
            'Name' => '主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912000000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $director->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $director->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        $student = Student::create([
            'name' => '黃郡晴',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        StudentClass::create([
            'StudentID' => $student->id,
            'TeacherID' => 99,
            'GradeID' => 1,
            'SubjectID' => 1,
            'by1' => 3,
            'Period' => 4,
            'StartDate' => '2026-05-01',
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'SessionDuration' => 120,
            'TotalHours' => 16,
            'ScheduleMode' => 'count',
            'ClassType' => 'one_on_three',
            'Rate' => 1000,
            'rate_unit' => 'session',
            'Charge' => 0,
            'UsedSessions' => 0,
            'Stop' => 0,
            'MDate' => now(),
            'week' => 1,
            'time' => '18:00:00',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/student-classes?branch_id=1');

        $res->assertOk()
            ->assertJsonPath('data.0.charge', 8000)
            ->assertJsonPath('data.0.effective_charge', 8000)
            ->assertJsonPath('data.0.charge_is_fallback', true);
    }
}
