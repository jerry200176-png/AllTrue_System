<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-007：GET /api/v1/student-classes/{id}/invoices
 * 回傳月結課程的逐期帳單列表，按 billing_period DESC 排序。
 */
class MonthlyInvoiceListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-27 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_list_invoices_returns_invoices_sorted_by_period_desc(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $course = $this->createMonthlyCourse($student->id);

        Invoice::create([
            'StudentID'      => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate'      => '2026-03-01',
            'TotalAmount'    => 4800,
            'PaidAmount'     => 4800,
            'Status'         => 'paid',
            'Note'           => '',
            'billing_period' => '2026-03',
        ]);

        Invoice::create([
            'StudentID'      => $student->id,
            'StudentClassID' => $course->ID,
            'IssueDate'      => '2026-04-01',
            'TotalAmount'    => 4800,
            'PaidAmount'     => 0,
            'Status'         => 'unpaid',
            'Note'           => '',
            'billing_period' => '2026-04',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson("/api/v1/student-classes/{$course->ID}/invoices");

        $res->assertOk();

        $data = $res->json('invoices');
        $this->assertIsArray($data);
        $this->assertCount(2, $data);

        // 最新期在前
        $this->assertSame('2026-04', $data[0]['billing_period']);
        $this->assertSame('unpaid', $data[0]['status']);
        $this->assertSame('2026-03', $data[1]['billing_period']);
        $this->assertSame('paid', $data[1]['status']);
    }

    public function test_list_invoices_returns_empty_array_for_legacy_course_without_invoices(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $course = $this->createMonthlyCourse($student->id);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson("/api/v1/student-classes/{$course->ID}/invoices");

        $res->assertOk();
        $this->assertSame([], $res->json('invoices'));
    }

    public function test_list_invoices_is_campus_isolated(): void
    {
        $campus1Token = $this->createDirectorToken([1], 'dir-campus1@test.com');
        $campus2Token = $this->createDirectorToken([2], 'dir-campus2@test.com');

        $student = $this->createStudent();
        $course = $this->createMonthlyCourse($student->id);

        // campus2 主任不可存取 campus1 課程
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$campus2Token}",
            'Accept'        => 'application/json',
        ])->getJson("/api/v1/student-classes/{$course->ID}/invoices");

        $res->assertStatus(403);
    }

    private function createDirectorToken(array $campusIds, string $loginName = 'dir-invoice@test.com'): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name'      => '主任測試',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => '0912000000',
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID'   => $user->id,
                'Admin'    => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createStudent(): Student
    {
        return Student::create([
            'name'         => '帳單測試學生',
            'CampusID'     => 1,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createMonthlyCourse(int $studentId): StudentClass
    {
        return StudentClass::create([
            'StudentID'        => $studentId,
            'GradeID'          => 1,
            'SubjectID'        => 1,
            'TeacherID'        => 99,
            'by1'              => 1,
            'Period'           => 4,
            'StartDate'        => '2026-03-01',
            'EndDate'          => '2026-04-30',
            'TotalHours'       => 20,
            'Charge'           => 4800,
            'Paid'             => 0,
            'Rate'             => 600,
            'RoomID'           => 'R1',
            'MDate'            => now(),
            'Stop'             => 0,
            'ScheduleMode'     => 'date',
            'SessionCount'     => 0,
            'SessionDuration'  => 120,
            'RemainingSessions' => 0,
            'ClassType'        => 'one_on_one',
            'UsedSessions'     => 0,
            'settlement_day'   => 15,
            'monthly_sessions' => 8,
        ]);
    }
}
