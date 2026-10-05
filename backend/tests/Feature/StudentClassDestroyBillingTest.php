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

class StudentClassDestroyBillingTest extends TestCase
{
    use RefreshDatabase;

    private function auth(string $type = 'D'): array
    {
        $user = User::create(['LoginName' => 'del-' . uniqid() . '@example.com', 'Name' => '帳務測試', 'PSW' => 'secret', 'type' => $type, 'phone' => '0900000000']);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function student(): Student
    {
        return Student::create(['name' => '刪除測試生', 'CampusID' => 1, 'ClassID' => 1, 'SchoolName' => 'T', 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function course(int $studentId): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-04-01', 'TotalHours' => 20, 'Charge' => 1000, 'Paid' => 0, 'Rate' => 0, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 60, 'RemainingSessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
    }

    private function invoice(StudentClass $c, int $paid = 0): int
    {
        return DB::table('Invoice')->insertGetId(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'IssueDate' => '2026-09-01',
            'TotalAmount' => 1000, 'PaidAmount' => $paid, 'Status' => $paid ? 'paid' : 'unpaid']);
    }

    private function assertUntouched(StudentClass $c, int $invoiceId, string $status): void
    {
        $this->assertNotNull(StudentClass::find($c->ID));
        $this->assertSame($status, Invoice::find($invoiceId)->Status);
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'student_class.deleted')->count());
    }

    public function test_unpaid_invoice_is_voided_and_audited_then_contract_deleted(): void
    {
        $c = $this->course($this->student()->id);
        $inv = $this->invoice($c);

        $this->deleteJson("/api/v1/student-classes/{$c->ID}", [], $this->auth())->assertOk();

        $this->assertNull(StudentClass::find($c->ID));
        $invoice = Invoice::find($inv);
        $this->assertSame('void', $invoice->Status);
        $this->assertStringContainsString("合約 #{$c->ID} 刪除", (string) $invoice->Note);
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'student_class.deleted')->count());
    }

    public function test_paid_flag_and_foreign_item_on_own_invoice_block_delete(): void
    {
        $student = $this->student();
        $legacyPaid = $this->course($student->id);
        $legacyPaid->forceFill(['Paid' => 1])->save();
        $this->deleteJson("/api/v1/student-classes/{$legacyPaid->ID}", [], $this->auth())->assertStatus(422);
        $this->assertNotNull(StudentClass::find($legacyPaid->ID));

        $anchor = $this->course($student->id);
        $other = $this->course($student->id);
        $inv = $this->invoice($anchor);
        DB::table('InvoiceItem')->insert(['InvoiceID' => $inv, 'StudentClassID' => $other->ID, 'Description' => 'x', 'Amount' => 500]);
        $this->deleteJson("/api/v1/student-classes/{$anchor->ID}", [], $this->auth())->assertStatus(422);
        $this->assertUntouched($anchor, $inv, 'unpaid');
    }

    public function test_long_note_is_bounded_and_actor_recorded(): void
    {
        $c = $this->course($this->student()->id);
        $inv = $this->invoice($c);
        DB::table('Invoice')->where('id', $inv)->update(['Note' => str_repeat('a', 255)]);
        $this->deleteJson("/api/v1/student-classes/{$c->ID}", [], $this->auth())->assertOk();
        $note = (string) Invoice::find($inv)->Note;
        $this->assertLessThanOrEqual(255, mb_strlen($note));
        $this->assertStringContainsString("合約 #{$c->ID} 刪除", $note);
        $this->assertStringNotContainsString('by -', $note);
    }

    public function test_teacher_cannot_void_invoices_by_deleting(): void
    {
        $c = $this->course($this->student()->id);
        $inv = $this->invoice($c);
        $this->deleteJson("/api/v1/student-classes/{$c->ID}", [], $this->auth('T'))->assertStatus(403);
        $this->assertUntouched($c, $inv, 'unpaid');
    }

    public function test_payment_blocks_delete(): void
    {
        $c = $this->course($this->student()->id);
        $inv = $this->invoice($c, 1000);
        DB::table('Payment')->insert(['InvoiceID' => $inv, 'Amount' => 1000, 'PaidAt' => now(), 'Method' => 'cash']);

        $this->deleteJson("/api/v1/student-classes/{$c->ID}", [], $this->auth())
            ->assertStatus(422)->assertJsonPath('message', '此合約已有收款或繳費回報，請先到帳務處理後再刪除');
        $this->assertUntouched($c, $inv, 'paid');
    }

    public function test_pending_report_blocks_delete(): void
    {
        $c = $this->course($this->student()->id);
        $inv = $this->invoice($c);
        DB::table('payment_reports')->insert(['StudentID' => $c->StudentID, 'StudentClassID' => $c->ID, 'reported_by_name' => '家長',
            'payment_date' => '2026-09-02', 'reported_amount' => 1000, 'status' => 'pending', 'report_token_hash' => 'x', 'token_expires_at' => now()->addDay()]);

        $this->deleteJson("/api/v1/student-classes/{$c->ID}", [], $this->auth())->assertStatus(422);
        $this->assertUntouched($c, $inv, 'unpaid');
    }

    public function test_shared_invoice_item_blocks_delete(): void
    {
        $s = $this->student();
        $c = $this->course($s->id);
        $other = $this->course($s->id);
        $inv = $this->invoice($c);
        $shared = $this->invoice($other);
        DB::table('InvoiceItem')->insert(['InvoiceID' => $shared, 'StudentClassID' => $c->ID, 'Description' => 'x', 'Amount' => 1000]);

        $this->deleteJson("/api/v1/student-classes/{$c->ID}", [], $this->auth())
            ->assertStatus(422)->assertJsonPath('message', '此合約在合併帳單中，請先到帳務處理該帳單');
        $this->assertUntouched($c, $inv, 'unpaid');
    }

    public function test_student_delete_with_payment_is_refused_single_and_bulk(): void
    {
        $h = $this->auth();
        $paidStudent = $this->student();
        $plain = $this->student();
        $c = $this->course($paidStudent->id);
        $inv = $this->invoice($c, 1000);
        DB::table('Payment')->insert(['InvoiceID' => $inv, 'Amount' => 1000, 'PaidAt' => now(), 'Method' => 'cash']);

        $this->deleteJson("/api/v1/students/{$paidStudent->id}", [], $h)->assertStatus(422);
        $this->postJson('/api/v1/students/bulk-delete', ['student_ids' => [$plain->id, $paidStudent->id]], $h)->assertStatus(422);

        $this->assertNotNull(Student::find($paidStudent->id));
        $this->assertNotNull(Student::find($plain->id));
        $this->assertNotNull(Invoice::find($inv));
    }
}
