<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentClassRateUnitUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_rate_unit_updates_db_put_response_index_and_hour_charge(): void
    {
        [$token, $course] = $this->makeDirectorAndCourse([
            'Rate' => 750,
            'rate_unit' => 'session',
            'SessionCount' => 8,
            'TotalHours' => 16,
            'Charge' => 6000,
        ]);

        $response = $this->authJson('PUT', "/api/v1/student-classes/{$course->ID}", $token, [
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'rate_per_30min' => 750,
            'rate_unit' => 'hour',
            'sessions_purchased' => 8,
            'payment_type' => 'session',
        ]);

        $response->assertOk()
            ->assertJsonPath('rate_unit', 'hour')
            ->assertJsonPath('Rate', 750);

        $course->refresh();
        $this->assertSame('hour', $course->rate_unit);
        $this->assertSame(12000, (int) $course->Charge);

        $index = $this->authJson('GET', '/api/v1/student-classes?branch_id=1&per_page=10', $token);
        $index->assertOk()
            ->assertJsonPath('data.0.rate_unit', 'hour')
            ->assertJsonPath('data.0.rate_per_30min', 750)
            ->assertJsonPath('data.0.total_hours', 16)
            ->assertJsonPath('data.0.charge', 12000)
            ->assertJsonPath('data.0.effective_charge', 12000);
    }

    public function test_session_rate_unit_keeps_per_session_charge_formula(): void
    {
        [$token, $course] = $this->makeDirectorAndCourse([
            'Rate' => 750,
            'rate_unit' => 'hour',
            'SessionCount' => 8,
            'TotalHours' => 16,
            'Charge' => 12000,
        ]);

        $response = $this->authJson('PUT', "/api/v1/student-classes/{$course->ID}", $token, [
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'rate_per_30min' => 750,
            'rate_unit' => 'session',
            'sessions_purchased' => 8,
            'payment_type' => 'session',
        ]);

        $response->assertOk()->assertJsonPath('rate_unit', 'session');
        $this->assertSame(6000, (int) $course->refresh()->Charge);
    }

    public function test_invalid_rate_unit_is_rejected(): void
    {
        [$token, $course] = $this->makeDirectorAndCourse();

        $response = $this->authJson('PUT', "/api/v1/student-classes/{$course->ID}", $token, [
            'rate_unit' => 'weekly',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['rate_unit']);
        $this->assertSame('session', $course->refresh()->rate_unit);
    }

    private function authJson(string $method, string $url, string $token, array $payload = [])
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->json($method, $url, $payload);
    }

    private function makeDirectorAndCourse(array $overrides = []): array
    {
        $director = User::create([
            'LoginName' => 'rate-unit-director-' . uniqid() . '@test.com',
            'Name' => '主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '09' . random_int(100000000, 999999999),
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
            'name' => '計價測試學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $course = StudentClass::create(array_merge([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-08-01',
            'TotalHours' => 16,
            'Rate' => 750,
            'rate_unit' => 'session',
            'Charge' => 6000,
            'Pay' => 0,
            'Paid' => 0,
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'SessionDuration' => 120,
            'UsedSessions' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'MDate' => now(),
        ], $overrides));

        return [$token, $course];
    }
}
