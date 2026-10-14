<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\BugReport;
use App\Models\Campus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SecurityAuditEvent;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #4100: triage-token billing snapshot (IDs + numbers, open-report campus only). CI only. */
final class TriageBillingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = [
        'student_class_id', 'bug_report_id', 'rate', 'rate_unit', 'session_count', 'total_hours',
        'standard_lesson_minutes', 'deduction_basis', 'charge', 'rate_times_count', 'charge_minus_base',
        'session_charge_rows', 'paid_flag', 'remaining_sessions', 'stop', 'invoices', 'payments',
    ];

    private int $seq = 0;

    public function test_snapshot_returns_ids_and_numbers_only_with_the_g009_delta(): void
    {
        $campus = Campus::factory()->create();
        $token = $this->createToken($this->user('S'));
        $course = $this->course($campus, ['Charge' => 8800, 'Rate' => 900, 'SessionCount' => 8]);
        $invoice = Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-09-01', 'DueDate' => '2026-09-15', 'TotalAmount' => 8800, 'PaidAmount' => 5000, 'Status' => 'partial', 'Note' => 'SECRET-INVOICE-NOTE', 'billing_period' => '2026-09']);
        $payment = Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 5000, 'PaidAt' => '2026-09-02', 'Method' => 'cash', 'Note' => 'SECRET-PAYMENT-NOTE']);
        $bug = $this->bug($campus, 'triaged');

        $res = $this->getJson($this->url($course, $bug), $this->bearer($token))->assertOk();

        $this->assertSame(self::KEYS, array_keys($res->json()));
        $res->assertJsonPath('student_class_id', $course->ID)
            ->assertJsonPath('bug_report_id', $bug->id)
            ->assertJsonPath('rate_unit', 'session')
            ->assertJsonPath('session_count', 8)
            ->assertJsonPath('standard_lesson_minutes', 90)
            ->assertJsonPath('deduction_basis', 'fixed_session')
            ->assertJsonPath('charge', 8800)
            ->assertJsonPath('rate_times_count', 7200)
            ->assertJsonPath('charge_minus_base', 1600)
            ->assertJsonPath('session_charge_rows', 0)
            ->assertJsonPath('paid_flag', 0)
            ->assertJsonPath('remaining_sessions', 8)
            ->assertJsonPath('stop', 0)
            ->assertJsonPath('invoices', [['id' => $invoice->id, 'status' => 'partial', 'total' => 8800, 'paid' => 5000, 'period' => '2026-09', 'issue_date' => '2026-09-01']])
            ->assertJsonPath('payments', [['id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => 5000]]);
        $this->assertEquals(900, $res->json('rate'));
        foreach (['SECRET-STUDENT', 'SECRET-MEMO', 'SECRET-INVOICE-NOTE', 'SECRET-PAYMENT-NOTE', '0912000000'] as $private) {
            $this->assertStringNotContainsString($private, $res->getContent());
        }

        $row = DB::table('security_audit_events')->where('event_type', 'triage_token.read')->orderByDesc('id')->first();
        $meta = json_decode($row->metadata, true);
        $this->assertSame(SecurityAuditEvent::ref('student_class', $course->ID), $row->subject_ref);
        $this->assertSame(SecurityAuditEvent::ref('bug_report', $bug->id), $meta['bug_report_ref']);
        $this->assertSame('triage.student-classes.billing-snapshot', $meta['route']);
        $this->assertSame(200, $meta['http_status']);
    }

    public function test_hour_rate_unit_uses_total_hours_as_the_base(): void
    {
        $campus = Campus::factory()->create();
        $token = $this->createToken($this->user('S'));
        $course = $this->course($campus, ['Charge' => 10000, 'Rate' => 450, 'rate_unit' => 'hour', 'TotalHours' => 20]);

        $this->getJson($this->url($course, $this->bug($campus, 'new')), $this->bearer($token))->assertOk()
            ->assertJsonPath('rate_times_count', 9000)
            ->assertJsonPath('charge_minus_base', 1000)
            ->assertJsonPath('invoices', [])
            ->assertJsonPath('payments', []);
    }

    public function test_bug_report_id_is_required(): void
    {
        $campus = Campus::factory()->create();
        $token = $this->createToken($this->user('S'));
        $course = $this->course($campus);

        $this->getJson("/api/v1/triage/student-classes/{$course->ID}/billing-snapshot", $this->bearer($token))->assertStatus(422);
        $this->getJson("/api/v1/triage/student-classes/{$course->ID}/billing-snapshot?bug_report_id=abc", $this->bearer($token))->assertStatus(422);
    }

    public function test_only_an_open_report_in_the_same_campus_unlocks_the_contract(): void
    {
        $campus = Campus::factory()->create();
        $other = Campus::factory()->create();
        $token = $this->createToken($this->user('S'));
        $course = $this->course($campus);

        foreach (['new', 'triaged', 'in_progress'] as $status) {
            $this->getJson($this->url($course, $this->bug($campus, $status)), $this->bearer($token))->assertOk();
        }
        foreach (['resolved', 'closed'] as $status) {
            $this->getJson($this->url($course, $this->bug($campus, $status)), $this->bearer($token))
                ->assertNotFound()->assertExactJson(['message' => 'Not found']);
        }
        $foreign = $this->bug($other, 'new');
        $this->getJson($this->url($course, $foreign), $this->bearer($token))->assertNotFound()->assertExactJson(['message' => 'Not found']);
        $open = $this->bug($campus, 'new');
        $this->getJson("/api/v1/triage/student-classes/{$course->ID}/billing-snapshot?bug_report_id=999999", $this->bearer($token))->assertNotFound();
        $this->getJson("/api/v1/triage/student-classes/999999/billing-snapshot?bug_report_id={$open->id}", $this->bearer($token))
            ->assertNotFound()->assertExactJson(['message' => 'Not found']);
        $this->assertSame(3, DB::table('security_audit_events')->where('event_type', 'triage_token.read')->where('outcome', 'success')->count());
    }

    public function test_request_without_a_valid_triage_token_is_401(): void
    {
        $campus = Campus::factory()->create();
        $admin = $this->user('S');
        $course = $this->course($campus);
        $url = $this->url($course, $this->bug($campus, 'new'));

        $this->getJson($url)->assertUnauthorized();
        $session = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $admin->id, 'token' => $session, 'expires_at' => now()->addDay()]);
        $this->getJson($url, $this->bearer($session))->assertUnauthorized();
        $this->getJson($url, ['X-User-Id' => (string) $admin->id, 'Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_token_still_cannot_reach_non_triage_billing_routes(): void
    {
        $campus = Campus::factory()->create();
        $token = $this->createToken($this->user('S'));
        $course = $this->course($campus);
        $this->bug($campus, 'new');

        foreach ([
            ['GET', '/api/v1/invoices'], ['GET', '/api/v1/finance/summary'], ['GET', '/api/v1/student-classes/export'],
            ['POST', "/api/v1/student-classes/{$course->ID}/billing-correction"], ['GET', '/api/v1/me'],
        ] as [$method, $uri]) {
            $this->json($method, $uri, [], $this->bearer($token))->assertForbidden();
        }
        $this->assertSame(8800, (int) StudentClass::query()->whereKey($course->ID)->value('Charge'));
    }

    private function url(StudentClass $course, BugReport $bug): string
    {
        return "/api/v1/triage/student-classes/{$course->ID}/billing-snapshot?bug_report_id={$bug->id}";
    }

    /** @param array<string, mixed> $overrides */
    private function course(Campus $campus, array $overrides = []): StudentClass
    {
        $student = Student::create(['name' => 'SECRET-STUDENT', 'CampusID' => $campus->id, 'ClassID' => 1, 'SchoolName' => 'School', 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '', 'Phone' => '0912000000']);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1,
            'Period' => 4, 'StartDate' => '2026-09-01', 'TotalHours' => 16, 'Memo' => 'SECRET-MEMO',
            'Charge' => 8800, 'Paid' => 0, 'Rate' => 900, 'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 90, 'RemainingSessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0, 'standard_lesson_minutes' => 90,
        ], $overrides));
    }

    private function bug(Campus $campus, string $status): BugReport
    {
        return BugReport::create(['CampusID' => $campus->id, 'reporter_user_id' => $this->user('T')->id, 'title' => 'billing', 'description' => 'desc', 'severity' => 'medium', 'status' => $status]);
    }

    private function createToken(User $admin): string
    {
        return (string) $this->postJson('/api/v1/admin/triage-tokens', ['name' => 'ci'], ['X-User-Id' => (string) $admin->id, 'Accept' => 'application/json'])
            ->assertCreated()->json('token');
    }

    private function user(string $type): User
    {
        $n = ++$this->seq;
        return User::create(['LoginName' => "billing-triage{$n}@test.com", 'Name' => "Triage {$n}", 'PSW' => 'secret', 'type' => $type, 'phone' => (string) (910000000 + $n)]);
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
