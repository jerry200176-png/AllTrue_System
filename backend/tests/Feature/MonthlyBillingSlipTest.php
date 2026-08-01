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
use Tests\TestCase;

class MonthlyBillingSlipTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_monthly_slip_uses_five_billable_sessions_when_stored_charge_is_four(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 09:00:00', 'Asia/Taipei'));

        $token = $this->createDirectorToken();
        $student = Student::create([
            'name' => '月結回歸測試',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-07-01',
            'EndDate' => '2026-07-31',
            'TotalHours' => 10,
            'Charge' => 6000,
            'Paid' => 0,
            'Rate' => 1500,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'date',
            'SessionCount' => 0,
            'SessionDuration' => 120,
            'RemainingSessions' => 0,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
            'settlement_day' => 17,
            'monthly_sessions' => 4,
            'rate_unit' => 'session',
        ]);

        foreach (['2026-07-01', '2026-07-09', '2026-07-15', '2026-07-22', '2026-07-29'] as $date) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => $date,
                'StartTime' => '18:00',
                'EndTime' => '20:00',
                'Status' => 'attended',
            ]);
        }

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/alerts/tuition-slip/{$course->ID}");

        $response->assertOk();
        $response->assertJsonPath('charge', 7500);
        $response->assertJsonPath('period_sessions', 5);
        $response->assertJsonCount(5, 'sessions');

        $alerts = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $alerts->assertOk();
        $row = collect($alerts->json())->firstWhere('id', (int) $course->ID);
        $this->assertNotNull($row);
        $this->assertSame(7500, (int) $row['charge']);
    }

    public function test_monthly_slip_keeps_stored_charge_when_period_has_no_billable_sessions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 09:00:00', 'Asia/Taipei'));

        $token = $this->createDirectorToken('director-monthly-fallback@example.com');
        $student = Student::create([
            'name' => '月結無堂次回歸測試',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-07-01',
            'EndDate' => '2026-07-31',
            'TotalHours' => 8,
            'Charge' => 6000,
            'Paid' => 0,
            'Rate' => 1500,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'date',
            'SessionCount' => 0,
            'SessionDuration' => 120,
            'RemainingSessions' => 0,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
            'settlement_day' => 17,
            'monthly_sessions' => 4,
            'rate_unit' => 'session',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/alerts/tuition-slip/{$course->ID}");

        $response->assertOk();
        $response->assertJsonPath('charge', 6000);
        $response->assertJsonPath('period_sessions', 0);
        $response->assertJsonCount(0, 'sessions');
    }

    private function createDirectorToken(string $loginName = 'director-monthly@example.com'): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 912345678,
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $user->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}
