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

/** #4100 option B: triage billing snapshot of ONE super-admin-linked contract behind an open report. CI only. */
final class TriageBillingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = [
        'student_class_id', 'bug_report_id', 'rate', 'rate_unit', 'session_count', 'total_hours',
        'standard_lesson_minutes', 'deduction_basis', 'charge', 'rate_times_count', 'charge_minus_base',
        'session_charge_rows', 'paid_flag', 'remaining_sessions', 'stop', 'invoices', 'payments',
    ];

    private int $seq = 0;

    private User $admin;

    private string $token;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();
        config(['triage_tokens.billing_snapshot_enabled' => true]);
        $this->admin = $this->user('S');
        $this->token = $this->createToken($this->admin);
        $this->campus = Campus::factory()->create();
    }

    public function test_linked_open_report_returns_ids_and_numbers_only_with_the_g009_delta(): void
    {
        $course = $this->course($this->campus, ['Charge' => 8800, 'Rate' => 900, 'SessionCount' => 8]);
        $invoice = Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-09-01', 'DueDate' => '2026-09-15', 'TotalAmount' => 8800, 'PaidAmount' => 5000, 'Status' => 'partial', 'Note' => 'SECRET-INVOICE-NOTE', 'billing_period' => '2026-09']);
        $payment = Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 5000, 'PaidAt' => '2026-09-02', 'Method' => 'cash', 'Note' => 'SECRET-PAYMENT-NOTE']);
        $bug = $this->bug($this->campus, 'triaged');
        $this->link($bug, $course)->assertCreated();

        $res = $this->getJson($this->url($course, $bug), $this->bearer())->assertOk();

        $this->assertSame(self::KEYS, array_keys($res->json()));
        $res->assertJsonPath('student_class_id', $course->ID)->assertJsonPath('bug_report_id', $bug->id)
            ->assertJsonPath('rate_unit', 'session')->assertJsonPath('session_count', 8)
            ->assertJsonPath('standard_lesson_minutes', 90)->assertJsonPath('deduction_basis', 'fixed_session')
            ->assertJsonPath('charge', 8800)->assertJsonPath('rate_times_count', 7200)
            ->assertJsonPath('charge_minus_base', 1600)->assertJsonPath('session_charge_rows', 0)
            ->assertJsonPath('paid_flag', 0)->assertJsonPath('remaining_sessions', 8)->assertJsonPath('stop', 0)
            ->assertJsonPath('invoices', [['id' => $invoice->id, 'status' => 'partial', 'total' => 8800, 'paid' => 5000, 'period' => '2026-09', 'issue_date' => '2026-09-01']])
            ->assertJsonPath('payments', [['id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => 5000]]);
        $this->assertEquals(900, $res->json('rate'));
        foreach (['SECRET-STUDENT', 'SECRET-MEMO', 'SECRET-INVOICE-NOTE', 'SECRET-PAYMENT-NOTE', '0912000000'] as $private) {
            $this->assertStringNotContainsString($private, $res->getContent());
        }
        $this->getJson("/api/v1/triage/student-classes/{$course->ID}/billing-snapshot", $this->bearer())->assertStatus(422);
    }

    public function test_unlinked_revoked_closed_and_mismatched_pairs_all_get_the_same_404(): void
    {
        $course = $this->course($this->campus);
        $otherCourse = $this->course($this->campus);
        $bug = $this->bug($this->campus, 'new');
        $otherBug = $this->bug($this->campus, 'new');

        $this->getJson($this->url($course, $bug), $this->bearer())->assertNotFound(); // no link
        $linkId = (int) $this->link($bug, $course)->assertCreated()->json('id');
        $this->getJson($this->url($course, $bug), $this->bearer())->assertOk();

        $this->getJson($this->url($otherCourse, $bug), $this->bearer())->assertNotFound()->assertExactJson(['message' => 'Not found']); // other contract, same report
        $this->getJson($this->url($course, $otherBug), $this->bearer())->assertNotFound()->assertExactJson(['message' => 'Not found']); // same contract, other report
        $this->getJson("/api/v1/triage/student-classes/999999/billing-snapshot?bug_report_id={$bug->id}", $this->bearer())->assertNotFound();
        $this->getJson("/api/v1/triage/student-classes/{$course->ID}/billing-snapshot?bug_report_id=999999", $this->bearer())->assertNotFound();

        $bug->forceFill(['status' => 'closed'])->save(); // report closed after linking: link revoked for good
        $this->getJson($this->url($course, $bug), $this->bearer())->assertNotFound()->assertExactJson(['message' => 'Not found']);
        $this->assertNotNull(DB::table('bug_report_contract_links')->where('id', $linkId)->value('revoked_at'));
        $bug->forceFill(['status' => 'triaged'])->save(); // reopening does NOT bring access back
        $this->getJson($this->url($course, $bug), $this->bearer())->assertNotFound()->assertExactJson(['message' => 'Not found']);
        $this->deleteJson("/api/v1/admin/triage-contract-links/{$linkId}", [], $this->as($this->admin))->assertNotFound();

        $relinkId = (int) $this->link($bug, $course)->assertCreated()->json('id'); // a super admin must re-link
        $this->getJson($this->url($course, $bug), $this->bearer())->assertOk();
        $this->deleteJson("/api/v1/admin/triage-contract-links/{$relinkId}", [], $this->as($this->admin))->assertOk();
        $this->getJson($this->url($course, $bug), $this->bearer())->assertNotFound()->assertExactJson(['message' => 'Not found']);
        $this->deleteJson("/api/v1/admin/triage-contract-links/{$relinkId}", [], $this->as($this->admin))->assertNotFound(); // second revoke: no-op
        $this->assertSame((int) $this->admin->id, (int) DB::table('bug_report_contract_links')->where('id', $relinkId)->value('revoked_by'));
        $this->assertSame(2, DB::table('bug_report_contract_links')->count()); // soft revoke keeps history
        $this->assertSame(2, DB::table('security_audit_events')->where('event_type', 'triage_contract_link.revoked')->count()); // one per real revoke
    }

    public function test_resolving_a_report_revokes_its_link(): void
    {
        $course = $this->course($this->campus);
        $bug = $this->bug($this->campus, 'in_progress');
        $linkId = (int) $this->link($bug, $course)->assertCreated()->json('id');

        $bug->forceFill(['status' => 'resolved'])->save();
        $bug->forceFill(['status' => 'in_progress'])->save();
        $this->getJson($this->url($course, $bug), $this->bearer())->assertNotFound();
        $row = DB::table('security_audit_events')->where('event_type', 'triage_contract_link.revoked')->first();
        $this->assertSame('report_closed', json_decode($row->metadata, true)['reason_code']);
        $this->assertNull(DB::table('bug_report_contract_links')->where('id', $linkId)->value('active'));
    }

    public function test_every_refused_snapshot_query_is_audited_past_the_denial_cap(): void
    {
        $course = $this->course($this->campus);
        $other = $this->course($this->campus);
        $bug = $this->bug($this->campus, 'new');
        $this->link($bug, $course)->assertCreated();

        foreach (range(1, 12) as $i) { // more than the 10/min per-IP cap that applies to other refusals
            $this->getJson("/api/v1/triage/student-classes/" . (900000 + $i) . "/billing-snapshot?bug_report_id={$bug->id}", $this->bearer())->assertNotFound();
        }
        $this->getJson($this->url($other, $bug), $this->bearer())->assertNotFound(); // cross-contract
        $this->getJson("/api/v1/triage/student-classes/{$course->ID}/billing-snapshot", $this->bearer())->assertStatus(422);

        $rows = DB::table('security_audit_events')->where('event_type', 'triage_token.read')->where('outcome', 'failure')->orderBy('id')->get();
        $this->assertCount(14, $rows);
        $this->assertSame(SecurityAuditEvent::ref('student_class', $other->ID), $rows[12]->subject_ref);
        $this->assertSame(404, json_decode($rows[12]->metadata, true)['http_status']);
        $this->assertSame(422, json_decode($rows[13]->metadata, true)['http_status']);
    }

    public function test_student_moved_to_another_campus_after_linking_is_404(): void
    {
        $course = $this->course($this->campus);
        $bug = $this->bug($this->campus, 'new');
        $this->link($bug, $course)->assertCreated();
        DB::table('Student')->where('id', $course->StudentID)->update(['CampusID' => Campus::factory()->create()->id]);

        $this->getJson($this->url($course, $bug), $this->bearer())->assertNotFound();
    }

    public function test_flag_off_answers_404_even_for_a_live_link(): void
    {
        $course = $this->course($this->campus);
        $bug = $this->bug($this->campus, 'new');
        $this->link($bug, $course)->assertCreated();

        config(['triage_tokens.billing_snapshot_enabled' => false]);
        $this->getJson($this->url($course, $bug), $this->bearer())->assertNotFound()->assertExactJson(['message' => 'Not found']);
    }

    public function test_kill_switch_defaults_off_when_env_is_unset_and_follows_the_env_when_set(): void
    {
        $name = 'TRIAGE_BILLING_SNAPSHOT_ENABLED';
        $saved = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        $load = fn (): array => require base_path('config/triage_tokens.php');
        try {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
            $this->assertFalse($load()['billing_snapshot_enabled'], 'default must stay OFF until the Founder approves');

            putenv("{$name}=true");
            $_ENV[$name] = $_SERVER[$name] = 'true';
            $this->assertTrue($load()['billing_snapshot_enabled'], 'the switch must read the env, not a literal');
        } finally {
            putenv($saved[0] === false ? $name : "{$name}={$saved[0]}");
            foreach (['_ENV' => $saved[1], '_SERVER' => $saved[2]] as $global => $value) {
                if ($value === null) {
                    unset($GLOBALS[$global][$name]);
                } else {
                    $GLOBALS[$global][$name] = $value;
                }
            }
        }
    }

    public function test_link_creation_validates_campus_open_report_existence_and_one_live_link_per_report(): void
    {
        $course = $this->course($this->campus);
        $foreignCourse = $this->course(Campus::factory()->create());
        $bug = $this->bug($this->campus, 'new');

        $this->link($bug, $foreignCourse)->assertStatus(422)->assertJsonPath('code', 'campus_mismatch');
        $this->link($this->bug($this->campus, 'closed'), $course)->assertStatus(422)->assertJsonPath('code', 'bug_report_not_open');
        $this->postJson('/api/v1/admin/triage-contract-links', ['bug_report_id' => 999999, 'student_class_id' => $course->ID], $this->as($this->admin))->assertStatus(422);
        $this->postJson('/api/v1/admin/triage-contract-links', ['bug_report_id' => $bug->id, 'student_class_id' => 999999], $this->as($this->admin))->assertStatus(422);
        $this->postJson('/api/v1/admin/triage-contract-links', ['bug_report_id' => $bug->id], $this->as($this->admin))->assertStatus(422);

        $this->link($bug, $course)->assertCreated();
        $this->link($bug, $this->course($this->campus))->assertStatus(409); // one live contract per report
        $this->assertSame(1, DB::table('bug_report_contract_links')->count());
    }

    public function test_triage_token_and_non_super_admin_sessions_cannot_manage_links(): void
    {
        $course = $this->course($this->campus);
        $bug = $this->bug($this->campus, 'new');
        $linkId = (int) $this->link($bug, $course)->assertCreated()->json('id');
        $payload = ['bug_report_id' => $bug->id, 'student_class_id' => $course->ID];

        $this->postJson('/api/v1/admin/triage-contract-links', $payload, $this->bearer())->assertForbidden();
        $this->deleteJson("/api/v1/admin/triage-contract-links/{$linkId}", [], $this->bearer())->assertForbidden();
        foreach (['A', 'T'] as $type) {
            $user = $this->user($type);
            $this->postJson('/api/v1/admin/triage-contract-links', $payload, $this->as($user))->assertForbidden();
            $this->deleteJson("/api/v1/admin/triage-contract-links/{$linkId}", [], $this->as($user))->assertForbidden();
        }
        $this->postJson('/api/v1/admin/triage-contract-links', $payload, ['Accept' => 'application/json'])->assertUnauthorized();
        $this->assertNull(DB::table('bug_report_contract_links')->where('id', $linkId)->value('revoked_at'));
    }

    public function test_snapshot_requires_a_triage_token_and_write_methods_change_nothing(): void
    {
        $course = $this->course($this->campus);
        $bug = $this->bug($this->campus, 'new');
        $this->link($bug, $course)->assertCreated();
        $url = $this->url($course, $bug);

        $this->getJson($url)->assertUnauthorized();
        $session = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $this->admin->id, 'token' => $session, 'expires_at' => now()->addDay()]);
        $this->getJson($url, ['Authorization' => "Bearer {$session}", 'Accept' => 'application/json'])->assertUnauthorized();
        $this->getJson($url, $this->as($this->admin))->assertUnauthorized();
        // Only GET is registered: the router answers 405 before any controller runs.
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, $url, ['Charge' => 1], $this->bearer())->assertStatus(405);
        }
        $this->assertSame(8800, (int) StudentClass::query()->whereKey($course->ID)->value('Charge'));
        $this->json('GET', '/api/v1/invoices', [], $this->bearer())->assertForbidden();
    }

    public function test_create_revoke_and_read_are_audited_with_hmac_refs_only(): void
    {
        $course = $this->course($this->campus);
        $bug = $this->bug($this->campus, 'new');
        $linkId = (int) $this->link($bug, $course)->assertCreated()->json('id');
        $this->getJson($this->url($course, $bug), $this->bearer())->assertOk();
        $this->deleteJson("/api/v1/admin/triage-contract-links/{$linkId}", [], $this->as($this->admin))->assertOk();

        $courseRef = SecurityAuditEvent::ref('student_class', $course->ID);
        $bugRef = SecurityAuditEvent::ref('bug_report', $bug->id);
        foreach (['triage_contract_link.created', 'triage_contract_link.revoked', 'triage_token.read'] as $event) {
            $row = DB::table('security_audit_events')->where('event_type', $event)->orderByDesc('id')->first();
            $this->assertNotNull($row, $event);
            $this->assertSame($courseRef, $row->subject_ref, $event);
            $this->assertSame($bugRef, json_decode($row->metadata, true)['bug_report_ref'], $event);
            $this->assertStringNotContainsString('SECRET', $row->metadata);
        }
        $this->assertSame(SecurityAuditEvent::ref('user', $this->admin->id), DB::table('security_audit_events')->where('event_type', 'triage_contract_link.created')->value('actor_ref'));
    }

    public function test_audit_rows_for_existing_bug_report_routes_are_unchanged(): void
    {
        $bug = $this->bug($this->campus, 'new');
        $other = $this->bug($this->campus, 'new');

        // A stray bug_report_id query param must not leak into bug-report route audit rows.
        $this->getJson("/api/v1/triage/bug-reports/{$bug->id}?bug_report_id={$other->id}", $this->bearer())->assertOk();
        $this->getJson('/api/v1/triage/bug-reports', $this->bearer())->assertOk();

        $rows = DB::table('security_audit_events')->where('event_type', 'triage_token.read')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $show = json_decode($rows[0]->metadata, true);
        $this->assertSame(SecurityAuditEvent::ref('bug_report', $bug->id), $rows[0]->subject_ref);
        $this->assertSame('triage.bug-reports.show', $show['route']);
        $this->assertArrayNotHasKey('bug_report_ref', $show);
        $list = json_decode($rows[1]->metadata, true);
        $this->assertNull($rows[1]->subject_ref);
        $this->assertSame('triage.bug-reports.index', $list['route']);
        $this->assertArrayNotHasKey('bug_report_ref', $list);
    }

    private function link(BugReport $bug, StudentClass $course): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/admin/triage-contract-links', ['bug_report_id' => $bug->id, 'student_class_id' => $course->ID], $this->as($this->admin));
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
        return (string) $this->postJson('/api/v1/admin/triage-tokens', ['name' => 'ci'], $this->as($admin))->assertCreated()->json('token');
    }

    private function user(string $type): User
    {
        $n = ++$this->seq;
        return User::create(['LoginName' => "billing-triage{$n}@test.com", 'Name' => "Triage {$n}", 'PSW' => 'secret', 'type' => $type, 'phone' => (string) (910000000 + $n)]);
    }

    /** @return array<string, string> */
    private function as(User $user): array
    {
        return ['X-User-Id' => (string) $user->id, 'Accept' => 'application/json'];
    }

    /** @return array<string, string> */
    private function bearer(): array
    {
        return ['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'];
    }
}
