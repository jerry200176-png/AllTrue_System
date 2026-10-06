<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentReportController;
use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\PaymentReportTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #3543: remaining 確認不收 gaps and invoice item scope checks. */
class WaiveFollowupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_approval_is_refused_on_a_waived_contract_before_any_write(): void
    {
        $h = ['Authorization' => 'Bearer ' . $this->createToken([1])];
        $course = $this->createStudentClass($this->createStudent(1)->id, ['Charge' => 5000, 'RemainingSessions' => 3]);
        $session = ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-09-01', 'StartTime' => '16:00',
            'EndTime' => '17:00', 'Status' => 'scheduled', 'Note' => '']);
        $record = LearningRecord::create(['StudentClassID' => $course->ID, 'ClassSessionID' => $session->id, 'TeacherID' => 99, 'Content' => 'x', 'Status' => 'approved',
            'SessionDate' => '2026-09-01', 'StartTime' => '16:00', 'EndTime' => '17:00']);

        DB::table('StudentClass')->where('ID', $course->ID)->update(['Stop' => 1, 'closed_reason' => 'waived']); // raw: model hooks guard waived

        $this->postJson("/api/v1/learning-records/{$record->id}/rollback-approval", [], $h)
            ->assertStatus(422)->assertJsonPath('code', 'course_waived');

        $this->assertSame('approved', $record->fresh()->Status);
        $this->assertSame(3, (int) $course->fresh()->RemainingSessions);
    }

    public function test_finance_summary_does_not_count_waived_contracts_as_unpaid(): void
    {
        $h = ['Authorization' => 'Bearer ' . $this->createToken([1])];
        $student = $this->createStudent(1);
        $this->createStudentClass($student->id, ['Charge' => 100]);
        $before = $this->getJson('/api/v1/finance/summary?branch_id=1', $h)->assertOk()->json();
        $this->createStudentClass($student->id, ['Charge' => 100, 'Stop' => 1, 'closed_reason' => 'waived']);

        // Delta-based: other fixtures may exist; the waived contract must not add to unpaid.
        $after = $this->getJson('/api/v1/finance/summary?branch_id=1', $h)->assertOk()->json();
        $this->assertSame($before['unpaid_courses'], $after['unpaid_courses']);
        $this->assertGreaterThanOrEqual(1, $before['unpaid_courses']);
    }

    public function test_payment_report_link_and_form_refuse_a_waived_contract(): void
    {
        $course = $this->createStudentClass($this->createStudent(1)->id, ['Charge' => 5000, 'Stop' => 1, 'closed_reason' => 'waived']);
        $controller = app(PaymentReportController::class);
        $tokens = app(PaymentReportTokenService::class);

        $link = $controller->generateLink(Request::create('/x', 'POST', ['student_class_id' => $course->ID]), $tokens);
        $this->assertSame(422, $link->getStatusCode());
        $this->assertSame('course_waived', $link->getData(true)['code']);

        $issued = $tokens->generate($course->ID, 1);
        $form = $controller->formData(Request::create('/x', 'GET', ['token' => $issued['token']]), $tokens);
        $this->assertSame(422, $form->getStatusCode());
        $this->assertSame('course_waived', $form->getData(true)['code']);
    }

    public function test_invoice_items_must_belong_to_the_requesters_campus(): void
    {
        $h = ['Authorization' => 'Bearer ' . $this->createToken([1])];
        $mine = $this->createStudentClass($this->createStudent(1)->id, ['Charge' => 100]);
        $foreign = $this->createStudentClass($this->createStudent(2)->id, ['Charge' => 100]);
        $body = fn (int $itemCourse) => ['StudentID' => $mine->StudentID, 'StudentClassID' => $mine->ID, 'IssueDate' => '2026-10-01',
            'TotalAmount' => 100, 'Items' => [['Description' => 'x', 'Amount' => 100, 'StudentClassID' => $itemCourse]]];

        $this->postJson('/api/v1/invoices', $body($foreign->ID), $h)->assertForbidden();
        $this->assertSame(0, DB::table('Invoice')->count());
        $this->postJson('/api/v1/invoices', ['StudentID' => $foreign->StudentID, 'IssueDate' => '2026-10-01', 'TotalAmount' => 100], $h)
            ->assertForbidden(); // top-level student of another campus, no contract
        $this->assertSame(0, DB::table('Invoice')->count());
        $this->postJson('/api/v1/invoices', $body($mine->ID), $h)->assertCreated();
    }

    public function test_tutoring_item_contract_cannot_be_billed(): void
    {
        $h = ['Authorization' => 'Bearer ' . $this->createToken([1])];
        $student = $this->createStudent(1);
        $paid = $this->createStudentClass($student->id, ['Charge' => 100]);
        $tutoring = $this->createStudentClass($student->id, ['ClassType' => 'tutoring']);

        $this->postJson('/api/v1/invoices', ['StudentID' => $student->id, 'StudentClassID' => $paid->ID, 'IssueDate' => '2026-10-01',
            'TotalAmount' => 100, 'Items' => [['Description' => 'x', 'Amount' => 100, 'StudentClassID' => $tutoring->ID]]], $h)
            ->assertStatus(422)->assertJsonPath('code', 'tutoring_no_payment_obligation');
        $this->assertSame(0, DB::table('Invoice')->count());
    }

    private function createToken(array $campusIds): string
    {
        $user = User::create(['LoginName' => 'waive-fu-' . uniqid() . '@example.com', 'Name' => '帳務測試', 'PSW' => 'secret',
            'type' => 'D', 'phone' => '0900000000']);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function createStudent(int $campusId): Student
    {
        return Student::create(['name' => '追蹤測試生', 'CampusID' => $campusId, 'ClassID' => 1, 'SchoolName' => 'Test School',
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-04-01', 'TotalHours' => 20, 'Charge' => 0, 'Paid' => 0, 'Rate' => 0, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 60, 'RemainingSessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $overrides));
    }
}
