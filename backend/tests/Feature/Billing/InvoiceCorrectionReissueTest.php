<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Founder D3 / B1 (#4132): 登記收款 can settle a new bill that corrects a voided one. Both notes cross-reference each
 * other (bill number, operator, reason), two audit rows share one correlation id, and a double submit never issues a
 * second bill.
 */
class InvoiceCorrectionReissueTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = '6f1c2b7e-3a4d-4e5f-8a9b-0c1d2e3f4a5b';

    private int $userId = 0;

    public function test_correction_issues_a_linked_bill_and_confirm_settles_it(): void
    {
        $token = $this->token([1]);
        $course = $this->course($this->student());
        $oldId = $this->voidedInvoice($token, $course, 8800, '開錯金額，原約定 7200');
        $res = $this->record($token, $course, $oldId)->assertOk()->assertJsonPath('status', 'pending');
        $newId = (int) $res->json('invoice_id');
        $oldNo = 'INV-202609-' . str_pad((string) $oldId, 6, '0', STR_PAD_LEFT);
        $this->assertStringContainsString("[更正自 #{$oldNo}／操作者：user_id={$this->userId}／原因：原約定 7200]", $this->note($newId));
        $oldNote = $this->note($oldId);
        $this->assertStringContainsString('[void: 開錯金額，原約定 7200; user_id=' . $this->userId, $oldNote);
        $this->assertMatchesRegularExpression('/\[replaced_by: #INV-\d+-' . str_pad((string) $newId, 6, '0', STR_PAD_LEFT) . "; user_id={$this->userId}; at=/", $oldNote);
        $this->assertSame(7200, (int) DB::table('Invoice')->where('id', $newId)->value('TotalAmount'));
        $this->assertSame($newId, (int) DB::table('payment_reports')->where('id', $res->json('report_id'))->value('InvoiceID'));
        $events = DB::table('security_audit_events')->where('event_type', 'invoice.correction_reissue')->get();
        $this->assertCount(2, $events);
        $this->assertCount(1, $events->pluck('correlation_id')->unique());
        $this->assertSame([null, null], $events->pluck('retention_until')->all()); // replacement state: never purged
        $res->assertJsonPath('old_note_tagged', true);
        $this->withToken($token)->putJson('/api/v1/payment-reports/' . $res->json('report_id') . '/confirm')->assertOk();
        $this->assertSame('paid', DB::table('Invoice')->where('id', $newId)->value('Status'));
        $this->assertSame(2, DB::table('Invoice')->where('StudentClassID', $course->ID)->count());
    }

    public function test_a_double_submit_returns_the_same_bill_and_a_new_attempt_or_reason_gets_409(): void
    {
        $token = $this->token([1]);
        $course = $this->course($this->student());
        $oldId = $this->voidedInvoice($token, $course, 8800);
        $first = $this->record($token, $course, $oldId)->assertOk();
        $retry = $this->record($token, $course, $oldId)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($first->json('invoice_id'), $retry->json('invoice_id'));
        $this->assertSame($first->json('report_id'), $retry->json('report_id'));
        $this->record($token, $course, $oldId, ['correction_reason' => '另一個原因'])
            ->assertStatus(409)->assertJsonPath('code', 'replaces_invoice_already_replaced')->assertJsonPath('replacement_invoice_id', $first->json('invoice_id'));
        $this->record($token, $course, $oldId, ['idempotency_key' => '0b7d8c9e-1f2a-4b3c-9d4e-5f6a7b8c9d0e'])->assertStatus(409);
        $this->record($token, $course, $oldId, ['amount' => 7000])->assertStatus(409);
        $this->assertSame(2, DB::table('Invoice')->where('StudentClassID', $course->ID)->count());
        $this->assertSame(1, DB::table('payment_reports')->where('StudentClassID', $course->ID)->count());
        $this->assertSame(1, substr_count($this->note($oldId), 'replaced_by'));
        $this->assertSame(2, DB::table('security_audit_events')->where('event_type', 'invoice.correction_reissue')->count());
    }

    public function test_teacher_is_forbidden_and_other_campus_or_missing_bills_are_not_found(): void
    {
        $director = $this->token([1]);
        $course = $this->course($this->student());
        $oldId = $this->voidedInvoice($director, $course, 8800);
        $this->record($this->token([1], 'T'), $course, $oldId)->assertForbidden();
        $this->record($this->token([2]), $course, $oldId)->assertNotFound();
        $this->record($director, $course, 99999999)->assertNotFound()->assertJsonPath('code', 'replaces_invoice_not_found');
        $this->assertSame(1, DB::table('Invoice')->where('StudentClassID', $course->ID)->count());
        $this->assertSame(0, DB::table('payment_reports')->count());
    }

    public function test_a_bill_that_is_not_void_or_of_another_course_or_student_is_refused(): void
    {
        $token = $this->token([1]);
        $student = $this->student();
        $course = $this->course($student);
        $open = $this->invoice($this->course($student), 8800);
        $otherCourse = $this->voidedInvoice($token, $this->course($student), 8800);
        $otherStudent = $this->voidedInvoice($token, $this->course($this->student()), 8800);
        $this->record($token, $course, $open)->assertStatus(422)->assertJsonPath('code', 'replaces_invoice_mismatch');
        $this->record($token, $course, $otherCourse)->assertStatus(422)->assertJsonPath('code', 'replaces_invoice_mismatch');
        $this->record($token, $course, $otherStudent)->assertStatus(422)->assertJsonPath('code', 'replaces_invoice_mismatch');
        $sameCourseOpen = $this->invoice($course, 8800);
        $this->record($token, $course, $sameCourseOpen)->assertStatus(422)->assertJsonPath('code', 'replaces_invoice_not_void');
        $this->assertSame(0, DB::table('payment_reports')->count());
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'invoice.correction_reissue')->count());
    }

    public function test_reason_and_key_are_required(): void
    {
        $token = $this->token([1]);
        $course = $this->course($this->student());
        $oldId = $this->voidedInvoice($token, $course, 8800);
        $this->record($token, $course, $oldId, ['correction_reason' => null])->assertStatus(422)->assertJsonValidationErrors('correction_reason');
        $this->record($token, $course, $oldId, ['idempotency_key' => null])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
        $this->record($token, $course, $oldId, ['correction_reason' => '／／／'])->assertStatus(422)->assertJsonPath('code', 'correction_reason_invalid');
    }

    public function test_tags_typed_into_reasons_cannot_fake_a_replacement_or_the_operator(): void
    {
        $token = $this->token([1]);
        $course = $this->course($this->student());
        $decoy = $this->voidedInvoice($token, $course, 7200);
        $oldId = $this->voidedInvoice($token, $course, 8800, "開錯 [replaced_by: #INV-202609-{$decoy}; user_id=1; at=2026-01-01]");
        $this->assertStringNotContainsString('[replaced_by', $this->note($oldId));
        $newId = (int) $this->record($token, $course, $oldId, ['correction_reason' => '原約定／操作者：user_id=1] [更正自 #INV-1'])
            ->assertOk()->json('invoice_id');
        $note = $this->note($newId);
        $this->assertSame(1, substr_count($note, '操作者'));
        $this->assertSame(1, substr_count($note, '['));
        $this->assertStringContainsString("／操作者：user_id={$this->userId}／原因：", $note);
    }

    public function test_a_long_note_keeps_the_void_tag_whole_and_trims_older_text(): void
    {
        $token = $this->token([1]);
        $course = $this->course($this->student());
        $oldId = $this->invoice($course, 8800, str_repeat('家長約定說明', 25));
        $this->withToken($token)->postJson("/api/v1/invoices/{$oldId}/void", ['reason' => str_repeat('開錯金額', 10)])->assertOk();
        preg_match('/\[void: [^\]]+\]/u', $this->note($oldId), $voidTag);
        $newId = (int) $this->record($token, $course, $oldId)->assertOk()->json('invoice_id');
        $note = $this->note($oldId);
        $this->assertLessThanOrEqual(255, mb_strlen($note));
        $this->assertStringStartsWith('家長約定說明', $note);
        $this->assertStringContainsString($voidTag[0], $note);
        $this->assertMatchesRegularExpression('/\[replaced_by: #INV-\d+-' . str_pad((string) $newId, 6, '0', STR_PAD_LEFT) . '; user_id=\d+; at=[^\]]+\]$/u', $note);
    }

    /** A void tag written before brackets were stripped stays one whole tag; its pieces are never trimmed as free text. */
    public function test_an_older_void_reason_with_brackets_is_kept_whole(): void
    {
        $token = $this->token([1]);
        $course = $this->course($this->student());
        $legacyTag = '[void: 開錯 [註：家長] 金額 [見附件]; user_id=5; at=2026-09-01 10:00:00]';
        $oldId = $this->invoice($course, 8800, str_repeat('舊備註', 50) . ' ' . $legacyTag);
        DB::table('Invoice')->where('id', $oldId)->update(['Status' => 'void']);
        $this->record($token, $course, $oldId)->assertOk()->assertJsonPath('old_note_tagged', true);
        $note = $this->note($oldId);
        $this->assertLessThanOrEqual(255, mb_strlen($note));
        $this->assertStringContainsString($legacyTag, $note);
        $this->assertStringStartsWith('舊備註', $note);
        $this->assertStringContainsString('[replaced_by: #INV-', $note);
    }

    /** A long void reason never blocks a correction: a short tag with the audit id, or the audit rows alone. */
    public function test_a_long_void_reason_falls_back_to_a_short_tag_then_to_the_audit_rows(): void
    {
        $token = $this->token([1]);
        $course = $this->course($this->student());
        $oldId = $this->voidedInvoice($token, $course, 8800, str_repeat('開錯金額說明', 24) . '一');
        $before = $this->note($oldId);
        $newId = (int) $this->record($token, $course, $oldId)->assertOk()->assertJsonPath('old_note_tagged', true)->json('invoice_id');
        $correlation = (string) DB::table('security_audit_events')->where('event_type', 'invoice.correction_reissue')->value('correlation_id');
        $note = $this->note($oldId);
        $this->assertLessThanOrEqual(255, mb_strlen($note));
        $this->assertStringStartsWith($before, $note);
        $this->assertStringEndsWith("[更正為 #{$newId} audit={$correlation}]", $note);
        $other = $this->course($this->student());
        $fullId = $this->voidedInvoice($token, $other, 8800, str_repeat('開錯金額', 50));
        $fullBefore = $this->note($fullId);
        $otherNew = (int) $this->record($token, $other, $fullId)->assertOk()->assertJsonPath('old_note_tagged', false)->json('invoice_id');
        $this->assertSame($fullBefore, $this->note($fullId));
        $this->assertStringContainsString('更正自 #INV-', $this->note($otherNew));
        $this->assertSame(4, DB::table('security_audit_events')->where('event_type', 'invoice.correction_reissue')->count());
        $this->record($token, $other, $fullId)->assertOk()->assertJsonPath('invoice_id', $otherNew); // still replayable
    }

    public function test_guards_monthly_package_conflict_open_bill_pending_amount_and_paid_course(): void
    {
        $token = $this->token([1]);
        $student = $this->student();
        $monthly = $this->course($student, ['ScheduleMode' => 'date']);
        $this->record($token, $monthly, $this->voidedInvoice($token, $monthly, 8800))->assertStatus(422)->assertJsonPath('code', 'correction_reissue_count_only');
        $member = $this->course($student, ['PackageID' => 1]);
        $this->record($token, $member, $this->voidedInvoice($token, $member, 8800))->assertStatus(422)->assertJsonPath('code', 'correction_reissue_count_only');
        $course = $this->course($student);
        $oldId = $this->voidedInvoice($token, $course, 8800);
        $this->record($token, $course, $oldId, ['invoice_id' => $oldId])->assertStatus(422)->assertJsonPath('code', 'correction_reissue_conflict');
        $this->record($token, $course, $oldId, ['amount' => 0, 'free_gift' => true])->assertStatus(422)->assertJsonPath('code', 'correction_reissue_conflict');
        $this->record($token, $course, $oldId, ['amount' => 0])->assertStatus(422)->assertJsonPath('code', 'correction_amount_invalid');
        $open = $this->invoice($course, 8800);
        $this->record($token, $course, $oldId)->assertStatus(422)->assertJsonPath('code', 'open_invoice_exists');
        DB::table('Invoice')->where('id', $open)->update(['Status' => 'void']);
        $this->withToken($token)->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $course->ID, 'payment_date' => now()->toDateString(), 'payment_method' => 'cash', 'amount' => 7200,
        ])->assertOk();
        $this->record($token, $course, $oldId)->assertStatus(422)->assertJsonPath('code', 'pending_report_exists');
        $paid = $this->course($student, ['Paid' => 1]);
        $paidOld = $this->voidedInvoice($token, $paid, 8800);
        $paidBill = $this->invoice($paid, 7200);
        DB::table('Invoice')->where('id', $paidBill)->update(['Status' => 'paid', 'PaidAmount' => 7200]);
        DB::table('Payment')->insert(['InvoiceID' => $paidBill, 'Amount' => 7200, 'PaidAt' => now(), 'Method' => 'cash']);
        $this->record($token, $paid, $paidOld)->assertStatus(422)->assertJsonPath('code', 'course_already_paid');
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'invoice.correction_reissue')->count());
        $this->assertSame(0, DB::table('Invoice')->where('Note', 'like', '%更正自%')->count());
    }

    /** Two concurrent submits: the replaced bill is read under a row lock taken after the course lock, so the second waits. */
    public function test_the_replaced_bill_is_locked_after_the_course_so_concurrent_submits_serialize(): void
    {
        $token = $this->token([1]);
        $course = $this->course($this->student());
        $oldId = $this->voidedInvoice($token, $course, 8800);
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = [strtolower($q->sql), $q->bindings];
        });
        $first = $this->record($token, $course, $oldId)->assertOk();
        $courseLock = collect($queries)->search(fn ($q) => str_contains($q[0], 'from `studentclass`') && str_contains($q[0], 'for update'));
        $invoiceLock = collect($queries)->search(fn ($q) => str_contains($q[0], 'from `invoice`') && str_contains($q[0], 'for update')
            && in_array($oldId, array_map('intval', $q[1]), true));
        $this->assertNotFalse($courseLock);
        $this->assertNotFalse($invoiceLock);
        $this->assertGreaterThan($courseLock, $invoiceLock);
        // The submit that waited then sees the committed correction: the same bill, no second one.
        $this->record($token, $course, $oldId)->assertOk()->assertJsonPath('invoice_id', $first->json('invoice_id'));
        $this->assertSame(2, DB::table('Invoice')->where('StudentClassID', $course->ID)->count());
    }

    /** @param array<string, mixed> $over */
    private function record(string $token, StudentClass $course, int $oldId, array $over = [])
    {
        return $this->withToken($token)->postJson('/api/v1/payment-reports/director-record', array_filter(array_merge([
            'student_class_id' => $course->ID, 'payment_date' => now()->toDateString(), 'payment_method' => 'cash', 'amount' => 7200,
            'replaces_invoice_id' => $oldId, 'correction_reason' => '原約定 7200', 'idempotency_key' => self::KEY,
        ], $over), fn ($v) => $v !== null));
    }

    private function voidedInvoice(string $token, StudentClass $course, int $amount, string $reason = '開錯金額'): int
    {
        $id = $this->invoice($course, $amount);
        $this->withToken($token)->postJson("/api/v1/invoices/{$id}/void", ['reason' => $reason])->assertOk();
        return $id;
    }

    private function invoice(StudentClass $course, int $amount, string $note = ''): int
    {
        $id = (int) DB::table('Invoice')->insertGetId([
            'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'billing_period' => '2026-09', 'Note' => $note,
            'IssueDate' => '2026-09-01', 'DueDate' => '2026-09-10', 'TotalAmount' => $amount, 'PaidAmount' => 0, 'Status' => 'unpaid',
        ]);
        DB::table('InvoiceItem')->insert(['InvoiceID' => $id, 'StudentClassID' => $course->ID, 'Description' => 'tuition', 'Amount' => $amount]);
        return $id;
    }

    private function note(int $id): string
    {
        return (string) DB::table('Invoice')->where('id', $id)->value('Note');
    }

    /** @param list<int> $campusIds */
    private function token(array $campusIds, string $type = 'D'): string
    {
        $user = User::create(['LoginName' => 'd3-' . uniqid() . '@test.com', 'Name' => 'TestDirector', 'PSW' => 'secret', 'type' => $type, 'phone' => '0900000000']);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        if ($type === 'D' && $campusIds === [1]) {
            $this->userId = (int) $user->id;
        }
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);
        return $raw;
    }

    private function student(): Student
    {
        return Student::create(['name' => 'Student ' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0900000000', 'MDT' => now(), 'Notify_Token' => '']);
    }

    /** @param array<string, mixed> $over */
    private function course(Student $student, array $over = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-09-01', 'EndDate' => '2026-12-31', 'TotalHours' => 20, 'Charge' => 7200, 'Paid' => 0,
            'Rate' => 900, 'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8,
            'SessionDuration' => 120, 'RemainingSessions' => 8, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $over));
    }
}
