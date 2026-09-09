<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentClassTutoringPaymentPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_tutoring_course_projects_zero_charge_without_payment_obligation(): void
    {
        $token = $this->createDirectorToken();
        $course = $this->createCourse([
            'ClassType' => 'tutoring',
            'Charge' => 0,
            'Rate' => 1200,
            'SessionCount' => 8,
        ]);

        $row = $this->courseRow($token, $course);

        $this->assertSame('tutoring', $row['class_type']);
        $this->assertSame(0, (int) $row['Charge']);
        $this->assertSame(0, (int) $row['charge']);
        $this->assertFalse($row['tutoring_billing_anomaly']);
        $this->assertSame([], $row['tutoring_billing_anomaly_reasons']);
    }

    public function test_tutoring_course_with_active_payable_state_fails_closed(): void
    {
        $token = $this->createDirectorToken();
        $course = $this->createCourse([
            'ClassType' => 'tutoring',
            'Charge' => 9600,
            'Rate' => 1200,
            'SessionCount' => 8,
        ]);
        Invoice::create([
            'StudentID' => $course->StudentID,
            'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01',
            'TotalAmount' => 9600,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
        ]);

        $row = $this->courseRow($token, $course);

        $this->assertSame(0, (int) $row['Charge']);
        $this->assertSame(0, (int) $row['charge']);
        $this->assertTrue($row['tutoring_billing_anomaly']);
        $this->assertContains('positive_course_charge', $row['tutoring_billing_anomaly_reasons']);
        $this->assertContains('active_invoice', $row['tutoring_billing_anomaly_reasons']);
        $this->assertContains('positive_outstanding', $row['tutoring_billing_anomaly_reasons']);
    }

    public function test_normal_paid_course_keeps_existing_payment_projection(): void
    {
        $token = $this->createDirectorToken();
        $course = $this->createCourse(['Charge' => 8000, 'Paid' => 1, 'PayDate' => '2026-09-02']);

        $row = $this->courseRow($token, $course);

        $this->assertSame('one_on_one', $row['class_type']);
        $this->assertSame(8000, (int) $row['charge']);
        $this->assertSame('paid', $row['payment_status']);
        $this->assertFalse($row['tutoring_billing_anomaly']);
    }

    public function test_normal_unpaid_course_keeps_existing_payment_projection(): void
    {
        $token = $this->createDirectorToken();
        $course = $this->createCourse(['Charge' => 8000, 'Paid' => 0]);

        $row = $this->courseRow($token, $course);

        $this->assertSame('one_on_one', $row['class_type']);
        $this->assertSame(8000, (int) $row['charge']);
        $this->assertSame('unpaid', $row['payment_status']);
        $this->assertFalse($row['tutoring_billing_anomaly']);
    }

    public function test_tutoring_payment_entry_is_rejected_without_creating_obligation(): void
    {
        $token = $this->createDirectorToken();
        $course = $this->createCourse(['ClassType' => 'tutoring', 'Charge' => 0]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $course->ID,
            'payment_date' => '2026-09-09',
            'payment_method' => 'cash',
            'amount' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'tutoring_no_payment_obligation');
        $this->assertDatabaseCount('payment_reports', 0);
        $this->assertDatabaseCount('Invoice', 0);
    }

    private function courseRow(string $token, StudentClass $course): array
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/student-classes?branch_id=1&per_page=100');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('ID', $course->ID);
        $this->assertIsArray($row);

        return $row;
    }

    private function createDirectorToken(): string
    {
        $director = User::create([
            'LoginName' => 'tutoring-policy-' . uniqid() . '@example.com',
            'Name' => '測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0900000000',
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

        return $token;
    }

    private function createCourse(array $overrides = []): StudentClass
    {
        $student = Student::create([
            'name' => '輔導付款政策測試生',
            'CampusID' => 1,
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
            'StartDate' => '2026-09-01',
            'TotalHours' => 16,
            'Charge' => 8000,
            'Paid' => 0,
            'Rate' => 1000,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
            'week' => 1,
            'time' => '14:00:00',
        ], $overrides));
    }
}
