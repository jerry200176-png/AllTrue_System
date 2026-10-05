<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingWaiveCourseTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_waives_settled_pending_with_reason_and_it_leaves_the_pending_queue(): void
    {
        $token = $this->createToken([1]);
        $course = $this->createStudentClass($this->createStudent()->id, ['Charge' => 8800, 'Stop' => 1, 'closed_reason' => 'settled_pending']);
        $invoiceId = DB::table('Invoice')->insertGetId([
            'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01', 'DueDate' => '2026-09-10', 'TotalAmount' => 8800, 'PaidAmount' => 0, 'Status' => 'unpaid',
        ]);

        $this->postJson("/api/v1/accounting/courses/{$course->ID}/waive", ['reason' => '家長搬家失聯'], ['Authorization' => "Bearer {$token}"])
            ->assertOk()->assertJsonPath('outstanding_amount', 8800);

        $course->refresh();
        $this->assertSame('waived', $course->closed_reason);
        $this->assertSame(0, (int) $course->Paid);
        $this->assertSame(8800, (int) $course->Charge);
        $this->assertSame('void', Invoice::find($invoiceId)->Status);
        $this->assertSame('家長搬家失聯', json_decode($course->settlement_snapshot, true)['reason']);
        $audit = DB::table('security_audit_events')->where('event_type', 'accounting.course_waived')->first();
        $this->assertNotNull($audit);
        $this->assertSame(8800, json_decode($audit->metadata, true)['outstanding_amount']);

        $this->getJson('/api/v1/accounting/settled-courses?branch_id=1', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('summary.pending_reconciliation_count', 0)
            ->assertJsonPath('data.0.pending_reconciliation', false)
            ->assertJsonPath('data.0.outstanding_amount', 0)
            ->assertJsonPath('data.0.reconciliation_label', '歷史 · 確認不收');
        $this->getJson('/api/v1/alerts/tuition?branch_id=1', ['Authorization' => "Bearer {$token}"])
            ->assertOk()->assertJsonMissing(['id' => $course->ID]);
    }

    public function test_reason_is_required(): void
    {
        $token = $this->createToken([1]);
        $course = $this->createStudentClass($this->createStudent()->id, ['Charge' => 8800, 'Stop' => 1, 'closed_reason' => 'settled_pending']);

        foreach ([[], ['reason' => ' '], ['reason' => 'x'], ['reason' => str_repeat('字', 201)]] as $body) {
            $this->postJson("/api/v1/accounting/courses/{$course->ID}/waive", $body, ['Authorization' => "Bearer {$token}"])->assertStatus(422);
        }
        $this->assertSame('settled_pending', $course->refresh()->closed_reason);
    }

    public function test_teacher_and_other_campus_director_are_forbidden(): void
    {
        $course = $this->createStudentClass($this->createStudent()->id, ['Charge' => 8800, 'Stop' => 1, 'closed_reason' => 'settled_pending']);

        foreach ([$this->createToken([1], 'T'), $this->createToken([2])] as $token) {
            $this->postJson("/api/v1/accounting/courses/{$course->ID}/waive", ['reason' => '不收了'], ['Authorization' => "Bearer {$token}"])->assertForbidden();
        }
        $this->assertSame('settled_pending', $course->refresh()->closed_reason);
    }

    public function test_paid_or_not_closed_contracts_are_rejected(): void
    {
        $token = $this->createToken([1]);
        $student = $this->createStudent();
        $cases = [
            $this->createStudentClass($student->id, ['Charge' => 8800, 'Paid' => 1, 'Stop' => 1, 'closed_reason' => 'settled_pending']),
            $this->createStudentClass($student->id, ['Charge' => 8800, 'Stop' => 0]),
            $this->createStudentClass($student->id, ['Charge' => 8800, 'Stop' => 1, 'closed_reason' => 'settled']),
        ];

        foreach ($cases as $course) {
            $this->postJson("/api/v1/accounting/courses/{$course->ID}/waive", ['reason' => '不收了'], ['Authorization' => "Bearer {$token}"])->assertStatus(422);
        }
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'accounting.course_waived')->count());
    }

    private function createToken(array $campusIds, string $type = 'D'): string
    {
        $user = User::create([
            'LoginName' => 'waive-test-' . uniqid() . '@example.com',
            'Name' => '帳務測試',
            'PSW' => 'secret',
            'type' => $type,
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
            'name' => '周宏謙測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'SchoolName' => 'Test School',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-01',
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 60,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }

    public function test_package_members_are_rejected(): void
    {
        $token = $this->createToken([1]);
        $course = $this->createStudentClass($this->createStudent()->id, ['Charge' => 8800, 'Stop' => 1, 'closed_reason' => 'settled_pending', 'PackageID' => 1]);

        $this->postJson("/api/v1/accounting/courses/{$course->ID}/waive", ['reason' => '不收了'], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(422)->assertJsonPath('message', '套裝課程請到套裝處理');
        $this->assertSame('settled_pending', $course->refresh()->closed_reason);
    }

    public function test_waived_is_terminal_for_resume_and_void_invoice_rejects_payment(): void
    {
        $token = $this->createToken([1]);
        $course = $this->createStudentClass($this->createStudent()->id, ['Charge' => 8800, 'Stop' => 1, 'closed_reason' => 'waived']);
        $invoiceId = DB::table('Invoice')->insertGetId([
            'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 8800, 'PaidAmount' => 0, 'Status' => 'void',
        ]);

        $this->postJson("/api/v1/student-classes/{$course->ID}/pause", ['action' => 'resume'], ['Authorization' => "Bearer {$token}"])->assertStatus(422);
        $this->assertSame('waived', $course->refresh()->closed_reason);
        $this->assertSame(1, (int) $course->Stop);
        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['Amount' => 100], ['Authorization' => "Bearer {$token}"])->assertStatus(422);
    }
}
