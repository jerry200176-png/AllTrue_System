<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TuitionAlertsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_count_mode_includes_zero_remaining_when_paid(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '零堂已繳',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = $this->createCountModeClass($student->id, [
            'Paid' => 1,
            'RemainingSessions' => 0,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $course->ID, $ids);
    }

    public function test_monthly_unpaid_excluded_when_five_or_more_days_before_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-10', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '月結五天外',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = $this->createMonthlyClass($student->id, [
            'settlement_day' => 15,
            'Paid' => 0,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $course->ID, $ids);
    }

    public function test_monthly_unpaid_included_when_less_than_five_days_before_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-12', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '月結三天內',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = $this->createMonthlyClass($student->id, [
            'settlement_day' => 15,
            'Paid' => 0,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('monthly_due_soon', $row['alert_type']);
        $this->assertSame(3, (int) $row['days_until_settlement']);
        $this->assertSame('2026-04-15', $row['due_date']);
    }

    public function test_monthly_unpaid_included_when_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-18', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '月結逾期',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = $this->createMonthlyClass($student->id, [
            'settlement_day' => 15,
            'Paid' => 0,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('monthly_due_soon', $row['alert_type']);
        $this->assertSame(-3, (int) $row['days_until_settlement']);
    }

    public function test_monthly_paid_included_when_next_due_within_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-12', 'Asia/Taipei'));

        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '月結已繳下次近',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = $this->createMonthlyClass($student->id, [
            'settlement_day' => 15,
            'Paid' => 1,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $course->ID);
        $this->assertNotNull($row);
        $this->assertSame('monthly_due_soon', $row['alert_type']);
        $this->assertSame(1, (int) $row['paid']);
        $this->assertSame('2026-05-15', $row['due_date']);
        $this->assertSame(3, (int) $row['days_until_settlement']);
    }

    private function createDirectorToken(array $campusIds, string $loginName = 'director-tuition@example.com'): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 912345678,
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

    private function createCountModeClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'EndDate' => null,
            'TotalHours' => 20,
            'Memo' => null,
            'Charge' => null,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 0,
            'Disconunt' => null,
            'Rate' => null,
            'LearnTimeID' => null,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 10,
            'SessionDuration' => 120,
            'RemainingSessions' => 5,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }

    private function createMonthlyClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'EndDate' => null,
            'TotalHours' => 20,
            'Memo' => null,
            'Charge' => null,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 0,
            'Disconunt' => null,
            'Rate' => null,
            'LearnTimeID' => null,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'date',
            'SessionCount' => 0,
            'SessionDuration' => 120,
            'RemainingSessions' => 0,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
            'settlement_day' => 1,
            'monthly_sessions' => null,
        ], $overrides));
    }
}
