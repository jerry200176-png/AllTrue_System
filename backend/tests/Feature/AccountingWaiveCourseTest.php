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

        $this->postJson("/api/v1/accounting/courses/{$course->ID}/waive", ['reason' => '家長搬家失聯', 'expected_amount' => 8800], ['Authorization' => "Bearer {$token}"])
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

    public function test_waived_course_cannot_be_rebilled_marked_paid_or_deleted(): void
    {
        $token = $this->createToken([1]);
        $h = ['Authorization' => "Bearer {$token}"];
        $course = $this->createStudentClass($this->createStudent()->id, ['Charge' => 5000, 'Stop' => 1, 'closed_reason' => 'waived']);

        $this->postJson('/api/v1/invoices', ['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-10-01', 'TotalAmount' => 5000], $h)->assertStatus(422)->assertJsonPath('code', 'course_waived');
        $this->postJson("/api/v1/student-classes/{$course->ID}/confirm-payment", [], $h)->assertStatus(422);
        $this->deleteJson("/api/v1/student-classes/{$course->ID}", [], $h)->assertStatus(422);

        // Any other writer is blocked at the model: general edit, renewal, and a billing line on another invoice.
        $this->putJson("/api/v1/student-classes/{$course->ID}", ['status' => 'active', 'paid_at' => '2026-10-01'], $h)
            ->assertStatus(422)->assertJsonPath('message', '此合約已確認不收，不能再變更繳費或結案狀態');
        $this->postJson("/api/v1/student-classes/{$course->ID}/renew-monthly", ['end_date' => '2026-11-30'], $h)->assertStatus(422);
        $other = $this->createStudentClass($course->StudentID, ['Charge' => 100]);
        $this->postJson('/api/v1/invoices', ['StudentID' => $course->StudentID, 'StudentClassID' => $other->ID, 'IssueDate' => '2026-10-01',
            'TotalAmount' => 100, 'Items' => [['Description' => 'x', 'Amount' => 100, 'StudentClassID' => $course->ID]]], $h)
            ->assertStatus(422)->assertJsonPath('message', '此合約已確認不收，不能再建立帳單');

        $course->refresh();
        $this->assertSame(0, (int) $course->Paid);
        $this->assertSame(1, (int) $course->Stop);
        $this->assertSame('waived', $course->closed_reason);
        $this->assertSame(0, Invoice::query()->where('StudentClassID', $course->ID)->count());
        $this->assertSame(0, Invoice::query()->where('StudentClassID', $other->ID)->count());

        // Purging the student would strand the waived contract's void invoices and audit trail.
        $this->deleteJson("/api/v1/students/{$course->StudentID}", [], $h)->assertStatus(422);
        $plain = $this->createStudent();
        $this->postJson('/api/v1/students/bulk-delete', ['student_ids' => [$plain->id, $course->StudentID]], $h)->assertStatus(422);
        $this->assertNotNull(Student::query()->find($plain->id)); // nothing deleted before the refusal
        $this->assertSame('waived', $course->fresh()->closed_reason);
    }

    public function test_course_billed_on_another_courses_open_invoice_cannot_be_waived(): void
    {
        $token = $this->createToken([1]);
        $student = $this->createStudent();
        $a = $this->createStudentClass($student->id, ['Charge' => 1000]);
        $b = $this->createStudentClass($student->id, ['Charge' => 2000, 'Stop' => 1, 'closed_reason' => 'settled_pending']);
        $invoiceId = DB::table('Invoice')->insertGetId(['StudentID' => $student->id, 'StudentClassID' => $a->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 3000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        DB::table('InvoiceItem')->insert(['InvoiceID' => $invoiceId, 'StudentClassID' => $b->ID, 'Description' => 'b', 'Amount' => 2000]);

        $this->postJson("/api/v1/accounting/courses/{$b->ID}/waive", ['reason' => '不收了', 'expected_amount' => 2000], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(422)->assertJsonPath('message', '此合約在合併帳單中，請先到帳務處理該帳單');
        $this->assertSame('settled_pending', $b->fresh()->closed_reason);

        // A paid shared invoice still references the course: also a conflict.
        DB::table('Invoice')->where('id', $invoiceId)->update(['Status' => 'paid', 'PaidAmount' => 3000]);
        $this->postJson("/api/v1/accounting/courses/{$b->ID}/waive", ['reason' => '不收了', 'expected_amount' => 2000], ['Authorization' => "Bearer {$token}"])
            ->assertStatus(422);
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
        return Student::create(['name' => '周宏謙測試生', 'CampusID' => 1, 'ClassID' => 1, 'SchoolName' => 'Test School',
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

    public function test_invoice_with_collected_money_or_pending_report_is_refused_and_nothing_changes(): void
    {
        $token = $this->createToken([1]);
        $student = $this->createStudent();
        $withPayment = $this->createStudentClass($student->id, ['Charge' => 8800, 'Stop' => 1, 'closed_reason' => 'settled_pending']);
        $partial = DB::table('Invoice')->insertGetId([
            'StudentID' => $student->id, 'StudentClassID' => $withPayment->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 8800, 'PaidAmount' => 3000, 'Status' => 'partial',
        ]);
        DB::table('Payment')->insert(['InvoiceID' => $partial, 'Amount' => 3000, 'PaidAt' => now(), 'Method' => 'cash']);
        $withReport = $this->createStudentClass($student->id, ['Charge' => 5000, 'Stop' => 1, 'closed_reason' => 'settled_pending']);
        DB::table('payment_reports')->insert([
            'StudentID' => $student->id, 'StudentClassID' => $withReport->ID, 'reported_by_name' => 'p', 'payment_date' => '2026-09-02',
            'payment_method' => 'cash', 'reported_amount' => 5000, 'status' => 'pending',
            'report_token_hash' => hash('sha256', 'waive-pending'), 'token_expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([$withPayment, $withReport] as $course) {
            $this->postJson("/api/v1/accounting/courses/{$course->ID}/waive", ['reason' => '不收了'], ['Authorization' => "Bearer {$token}"])->assertStatus(422);
            $this->assertSame('settled_pending', $course->refresh()->closed_reason);
        }
        $this->assertSame('partial', Invoice::find($partial)->Status);
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'accounting.course_waived')->count());
    }

    public function test_confirming_a_report_on_a_waived_course_is_rejected(): void
    {
        $token = $this->createToken([1]);
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, ['Charge' => 5000, 'Stop' => 1, 'closed_reason' => 'waived']);
        $reportId = DB::table('payment_reports')->insertGetId([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID, 'reported_by_name' => 'p', 'payment_date' => '2026-09-02',
            'payment_method' => 'cash', 'reported_amount' => 5000, 'status' => 'pending',
            'report_token_hash' => hash('sha256', 'waive-confirm'), 'token_expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->putJson("/api/v1/payment-reports/{$reportId}/confirm", [], ['Authorization' => "Bearer {$token}"])->assertStatus(422);
        $this->assertSame(0, (int) $course->refresh()->Paid);
    }

    public function test_blank_status_legacy_invoice_is_voided_and_package_id_zero_is_allowed(): void
    {
        $token = $this->createToken([1]);
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, ['Charge' => 4000, 'Stop' => 1, 'closed_reason' => 'settled_pending', 'PackageID' => 0]);
        $invoiceId = DB::table('Invoice')->insertGetId([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 4000, 'PaidAmount' => 0, 'Status' => '',
        ]);

        $this->postJson("/api/v1/accounting/courses/{$course->ID}/waive", ['reason' => '不收了', 'expected_amount' => 4000], ['Authorization' => "Bearer {$token}"])
            ->assertOk()->assertJsonPath('outstanding_amount', 4000);
        $this->assertSame('void', Invoice::find($invoiceId)->Status);
        $this->assertSame('waived', $course->refresh()->closed_reason);
    }

    public function test_waived_course_is_terminal_for_director_record_and_split(): void
    {
        $token = $this->createToken([1]);
        $course = $this->createStudentClass($this->createStudent()->id, ['Charge' => 8800, 'Stop' => 1, 'closed_reason' => 'waived']);
        $h = ['Authorization' => "Bearer {$token}"];

        $this->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $course->ID, 'payment_date' => '2026-09-02', 'payment_method' => 'cash', 'amount' => 8800,
        ], $h)->assertStatus(422);
        $this->assertSame(0, DB::table('payment_reports')->where('StudentClassID', $course->ID)->count());

        $this->postJson("/api/v1/student-classes/{$course->ID}/split-contract/preview", ['session_ids' => [1], 'start_date' => '2026-09-10'], $h)
            ->assertStatus(422)->assertJsonPath('code', 'split_contract_usage_settled');
        $this->assertTrue($course->isUsageSettlementLocked());
    }

    public function test_ledger_void_invoice_keeps_total_but_owes_nothing(): void
    {
        $token = $this->createToken([1]);
        $student = $this->createStudent();
        $course = $this->createStudentClass($student->id, ['Charge' => 8800, 'Stop' => 1, 'closed_reason' => 'waived']);
        DB::table('Invoice')->insert([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 8800, 'PaidAmount' => 0, 'Status' => 'void',
        ]);

        $this->getJson("/api/v1/accounting/ledger?student_class_id={$course->ID}", ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('invoices.0.total_amount', 8800)
            ->assertJsonPath('invoices.0.outstanding_amount', 0)
            ->assertJsonPath('summary.outstanding_total', 0);
    }

    public function test_parent_dashboard_does_not_show_waived_course_as_unpaid(): void
    {
        $student = Student::create([
            'name' => '家長不收測試', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '', 'Phone' => '0911555666',
        ]);
        $this->createStudentClass($student->id, ['Charge' => 8800, 'Stop' => 1, 'closed_reason' => 'waived']);
        $parentToken = (string) $this->postJson('/api/v1/parent/login', ['Name' => $student->name, 'Phone' => '0911555666'])->assertOk()->json('token');

        $this->getJson('/api/v1/parent/dashboard', ['Authorization' => "Bearer {$parentToken}"])
            ->assertOk()
            ->assertJsonFragment(['payment_status' => 'waived', 'payment_status_label' => '已確認不收'])
            ->assertJsonMissing(['payment_status' => 'unpaid']);
    }

    public function test_expected_amount_mismatch_returns_409_and_matching_amount_succeeds(): void
    {
        $token = $this->createToken([1]);
        $course = $this->createStudentClass($this->createStudent()->id, ['Charge' => 8800, 'Stop' => 1, 'closed_reason' => 'settled_pending']);
        $h = ['Authorization' => "Bearer {$token}"];

        $this->getJson("/api/v1/accounting/settled-courses?course_id={$course->ID}", $h)
            ->assertOk()->assertJsonPath('data.0.waivable_amount', 8800);
        $this->postJson("/api/v1/accounting/courses/{$course->ID}/waive", ['reason' => '不收了', 'expected_amount' => 100], $h)->assertStatus(409);
        $this->assertSame('settled_pending', $course->refresh()->closed_reason);
        $this->postJson("/api/v1/accounting/courses/{$course->ID}/waive", ['reason' => '不收了', 'expected_amount' => 8800], $h)->assertOk();
    }
}
