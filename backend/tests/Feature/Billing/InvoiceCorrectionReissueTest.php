<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Founder D3 (#4128): a confirmed correction is void + reissue, and the two bills must be traceable. There is no
 * automatic link yet, so POST /invoices with replaces_invoice_id writes the cross-references into both notes
 * (bill number, reason, operator) and the audit log, in one transaction.
 */
class InvoiceCorrectionReissueTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;

    public function test_reissue_cross_references_both_notes_and_audits_once(): void
    {
        $token = $this->directorToken();
        $course = $this->course($this->student());
        $oldId = $this->unpaidInvoice($course, 8800);
        $this->withToken($token)->postJson("/api/v1/invoices/{$oldId}/void", ['reason' => '開錯金額，原約定 7200'])->assertOk();

        $newId = (int) $this->withToken($token)->postJson('/api/v1/invoices', $this->reissue($course, $oldId))
            ->assertCreated()->json('id');

        $oldNo = 'INV-202609-' . str_pad((string) $oldId, 6, '0', STR_PAD_LEFT);
        $newNote = (string) DB::table('Invoice')->where('id', $newId)->value('Note');
        $this->assertStringContainsString("[更正自 #{$oldNo}／原因：原約定 7200／操作者：user_id={$this->userId}]", $newNote);
        $oldNote = (string) DB::table('Invoice')->where('id', $oldId)->value('Note');
        $this->assertStringContainsString('[void: 開錯金額，原約定 7200; user_id=' . $this->userId, $oldNote);
        $this->assertMatchesRegularExpression('/\[replaced_by: #INV-\d+-' . str_pad((string) $newId, 6, '0', STR_PAD_LEFT)
            . "; invoice_id={$newId}; user_id={$this->userId}; at=/", $oldNote);
        $this->assertSame('void', DB::table('Invoice')->where('id', $oldId)->value('Status'));
        $this->assertSame(7200, (int) DB::table('Invoice')->where('id', $newId)->value('TotalAmount'));

        $events = DB::table('security_audit_events')->where('event_type', 'invoice.correction_reissue')->get();
        $this->assertCount(2, $events);
        $this->assertCount(1, $events->pluck('correlation_id')->unique());
    }

    public function test_a_bill_that_is_not_void_cannot_be_replaced(): void
    {
        $token = $this->directorToken();
        $course = $this->course($this->student());
        $oldId = $this->unpaidInvoice($course, 8800);

        $this->withToken($token)->postJson('/api/v1/invoices', $this->reissue($course, $oldId))
            ->assertStatus(422)->assertJsonPath('code', 'replaces_invoice_not_void');

        $this->assertSame(1, DB::table('Invoice')->where('StudentClassID', $course->ID)->count());
        $this->assertSame('', (string) DB::table('Invoice')->where('id', $oldId)->value('Note'));
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'invoice.correction_reissue')->count());
    }

    public function test_a_bill_of_another_course_cannot_be_replaced(): void
    {
        $token = $this->directorToken();
        $student = $this->student();
        $course = $this->course($student);
        $other = $this->course($student);
        $oldId = $this->unpaidInvoice($other, 8800);
        $this->withToken($token)->postJson("/api/v1/invoices/{$oldId}/void", ['reason' => '開錯金額'])->assertOk();

        $this->withToken($token)->postJson('/api/v1/invoices', $this->reissue($course, $oldId))
            ->assertStatus(422)->assertJsonPath('code', 'replaces_invoice_mismatch');

        $this->assertSame(0, DB::table('Invoice')->where('StudentClassID', $course->ID)->count());
        $this->assertStringNotContainsString('replaced_by', (string) DB::table('Invoice')->where('id', $oldId)->value('Note'));
    }

    public function test_a_retry_returns_the_same_bill_and_a_different_second_reissue_is_refused(): void
    {
        $token = $this->directorToken();
        $course = $this->course($this->student());
        $oldId = $this->unpaidInvoice($course, 8800);
        $this->withToken($token)->postJson("/api/v1/invoices/{$oldId}/void", ['reason' => '開錯金額'])->assertOk();

        $first = (int) $this->withToken($token)->postJson('/api/v1/invoices', $this->reissue($course, $oldId))->assertCreated()->json('id');
        $retry = (int) $this->withToken($token)->postJson('/api/v1/invoices', $this->reissue($course, $oldId))->assertOk()->json('id');

        $this->assertSame($first, $retry);
        $this->assertSame(2, DB::table('Invoice')->where('StudentClassID', $course->ID)->count());
        $this->assertSame(1, substr_count((string) DB::table('Invoice')->where('id', $oldId)->value('Note'), 'replaced_by'));
        $this->assertSame(1, substr_count((string) DB::table('Invoice')->where('id', $first)->value('Note'), '更正自'));
        $this->assertSame(2, DB::table('security_audit_events')->where('event_type', 'invoice.correction_reissue')->count());

        $this->withToken($token)->postJson('/api/v1/invoices', ['TotalAmount' => 7000] + $this->reissue($course, $oldId))
            ->assertStatus(409)->assertJsonPath('code', 'replaces_invoice_already_replaced')->assertJsonPath('replacement_invoice_id', $first);
        $this->assertSame(2, DB::table('Invoice')->where('StudentClassID', $course->ID)->count());
    }

    public function test_a_reason_is_required(): void
    {
        $token = $this->directorToken();
        $course = $this->course($this->student());
        $payload = $this->reissue($course, $this->unpaidInvoice($course, 8800));
        unset($payload['correction_reason']);

        $this->withToken($token)->postJson('/api/v1/invoices', $payload)->assertStatus(422)->assertJsonValidationErrors('correction_reason');
    }

    /** @return array<string, mixed> */
    private function reissue(StudentClass $course, int $oldId): array
    {
        return [
            'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-09-15', 'TotalAmount' => 7200,
            'Items' => [['Description' => 'tuition', 'Amount' => 7200, 'StudentClassID' => $course->ID]],
            'replaces_invoice_id' => $oldId, 'correction_reason' => '原約定 7200',
        ];
    }

    private function directorToken(): string
    {
        $user = User::create(['LoginName' => 'director_' . uniqid() . '@test.com', 'Name' => 'TestDirector', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $this->userId = (int) $user->id;
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => Carbon::now()->addDay()]);

        return $raw;
    }

    private function student(): Student
    {
        return Student::create(['name' => 'Student ' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0900000000', 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function course(Student $student): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-09-01', 'EndDate' => '2026-12-31', 'TotalHours' => 20, 'Charge' => 7200, 'Paid' => 0,
            'Rate' => 900, 'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8,
            'SessionDuration' => 120, 'RemainingSessions' => 8, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
    }

    private function unpaidInvoice(StudentClass $course, int $amount): int
    {
        $id = (int) DB::table('Invoice')->insertGetId([
            'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'billing_period' => '2026-09', 'Note' => '',
            'IssueDate' => '2026-09-01', 'DueDate' => '2026-09-10', 'TotalAmount' => $amount, 'PaidAmount' => 0, 'Status' => 'unpaid',
        ]);
        DB::table('InvoiceItem')->insert(['InvoiceID' => $id, 'StudentClassID' => $course->ID, 'Description' => 'tuition', 'Amount' => $amount]);

        return $id;
    }
}
