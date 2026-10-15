<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\BugReport;
use App\Models\Campus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
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
        $this->token = (string) $this->postJson('/api/v1/admin/triage-tokens', ['name' => 'ci'], $this->as($this->admin))->assertCreated()->json('token');
        $this->campus = Campus::factory()->create();
    }

    public function test_linked_open_report_returns_ids_and_numbers_only_with_the_g009_delta(): void
    {
        $course = $this->course($this->campus, ['Charge' => 8800, 'Rate' => 900, 'SessionCount' => 8]);
        $invoice = Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-09-01', 'DueDate' => '2026-09-15', 'TotalAmount' => 8800, 'PaidAmount' => 5000, 'Status' => 'partial', 'Note' => 'SECRET-INVOICE-NOTE', 'billing_period' => '2026-09']);
        $payment = Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 5000, 'PaidAt' => '2026-09-02', 'Method' => 'cash', 'Note' => 'SECRET-PAYMENT-NOTE']);
        $reversal = Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 700, 'PaidAt' => '2026-09-03', 'Method' => 'void']); // positive-amount void = reversal
        $merged = Invoice::create(['StudentID' => $course->StudentID, 'StudentClassID' => $this->course($this->campus)->ID, 'IssueDate' => '2026-10-01', 'DueDate' => '2026-10-15', 'TotalAmount' => 9999, 'PaidAmount' => 9999, 'Status' => 'paid', 'billing_period' => '2026-10']);
        InvoiceItem::create(['InvoiceID' => $merged->id, 'StudentClassID' => $course->ID, 'Description' => 'x', 'Amount' => 4000]); // merged bill anchored elsewhere: no money shown
        Payment::create(['InvoiceID' => $merged->id, 'Amount' => 9999, 'PaidAt' => '2026-10-02', 'Method' => 'cash']);
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
            ->assertJsonPath('invoices', [['id' => $invoice->id, 'status' => 'partial', 'shared' => false, 'total' => 8800, 'paid' => 5000, 'period' => '2026-09', 'issue_date' => '2026-09-01'],
                ['id' => $merged->id, 'status' => 'paid', 'shared' => true, 'total' => null, 'paid' => null, 'period' => '2026-10', 'issue_date' => '2026-10-01']])
            ->assertJsonPath('payments', [['id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => 5000], ['id' => $reversal->id, 'invoice_id' => $invoice->id, 'amount' => -700]]);
        $this->assertEquals(900, $res->json('rate'));
        foreach (['SECRET-STUDENT', 'SECRET-MEMO', 'SECRET-INVOICE-NOTE', 'SECRET-PAYMENT-NOTE', '0912000000', 'void', '9999'] as $private) {
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

        $bug->forceFill(['status' => 'resolved'])->save(); // report resolved after linking: link revoked for good
        $this->getJson($this->url($course, $bug), $this->bearer())->assertNotFound()->assertExactJson(['message' => 'Not found']);
        $this->assertNotNull(DB::table('bug_report_contract_links')->where('id', $linkId)->value('revoked_at'));
        $this->assertNull(DB::table('bug_report_contract_links')->where('id', $linkId)->value('active'));
        $this->assertSame('report_closed', json_decode(DB::table('security_audit_events')->where('event_type', 'triage_contract_link.revoked')->orderBy('id')->value('metadata'), true)['reason_code']);
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

        DB::listen(function ($query): void { // a failing snapshot (500) is still audited past the cap
            if (str_contains($query->sql, 'from `Invoice`')) { throw new \RuntimeException('db down'); }
        });
        $this->getJson($this->url($course, $bug), $this->bearer())->assertStatus(500);

        $rows = DB::table('security_audit_events')->where('event_type', 'triage_token.read')->where('outcome', 'failure')->orderBy('id')->get();
        $this->assertCount(15, $rows);
        $this->assertSame(500, json_decode($rows[14]->metadata, true)['http_status']);
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

        // Load the shipped config with the env unset: the default itself must be OFF (no Founder approval yet).
        $this->assertFalse(getenv('TRIAGE_BILLING_SNAPSHOT_ENABLED'));
        config(['triage_tokens' => require base_path('config/triage_tokens.php')]);
        $this->getJson($this->url($course, $bug), $this->bearer())->assertNotFound()->assertExactJson(['message' => 'Not found']);
        putenv('TRIAGE_BILLING_SNAPSHOT_ENABLED=true'); // and it follows the env, not a literal
        $fromEnv = (require base_path('config/triage_tokens.php'))['billing_snapshot_enabled'];
        putenv('TRIAGE_BILLING_SNAPSHOT_ENABLED');
        $this->assertTrue($fromEnv);
    }

    public function test_a_close_landing_while_a_link_is_created_leaves_no_link_after_reopen(): void
    {
        $course = $this->course($this->campus);
        $bug = $this->bug($this->campus, 'new');
        $sql = [];
        DB::listen(function ($query) use ($bug, &$sql): void {
            $sql[] = $query->sql;
            // The concurrent close commits after store()'s unlocked checks and before its locked re-check.
            if (str_contains($query->sql, 'inner join `Student`')) {
                BugReport::query()->whereKey($bug->id)->first()->forceFill(['status' => 'closed'])->save();
            }
        });
        $this->link($bug, $course)->assertStatus(422)->assertJsonPath('code', 'bug_report_not_open');
        $this->assertNotEmpty(preg_grep('/from `bug_reports` where .* for update$/', $sql));
        $bug->refresh()->forceFill(['status' => 'triaged'])->save(); // reopen
        $this->assertSame(0, DB::table('bug_report_contract_links')->count());
        $this->getJson($this->url($course, $bug), $this->bearer())->assertNotFound();
    }

    public function test_migration_refuses_an_existing_table_missing_its_unique_index(): void
    {
        $migration = require base_path('database/migrations/2026_10_14_200000_create_bug_report_contract_links_table.php');
        $migration->up(); // the complete table passes
        // A session TEMPORARY table shadows the real one without an implicit commit: no unique index, like a half-failed run.
        DB::statement('CREATE TEMPORARY TABLE bug_report_contract_links (id bigint unsigned PRIMARY KEY, bug_report_id bigint unsigned NOT NULL, active tinyint(1) NULL)');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing: unique index uniq_bug_contract_link_active');
        try {
            $migration->up();
        } finally {
            DB::statement('DROP TEMPORARY TABLE bug_report_contract_links');
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

        $this->assertFalse(StudentClass::hasOperationalHistory([$course->ID]));
        $this->link($bug, $course)->assertCreated();
        $this->assertTrue(StudentClass::hasOperationalHistory([$course->ID])); // a linked contract can no longer be hard-deleted
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
        foreach ([[SecurityAuditEvent::ref('bug_report', $bug->id), 'triage.bug-reports.show'], [null, 'triage.bug-reports.index']] as $i => [$ref, $route]) {
            $meta = json_decode($rows[$i]->metadata, true);
            $this->assertSame([$ref, $route, false], [$rows[$i]->subject_ref, $meta['route'], array_key_exists('bug_report_ref', $meta)]);
        }
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

    private function user(string $type): User
    {
        $n = ++$this->seq;
        return User::create(['LoginName' => "billing-triage{$n}@test.com", 'Name' => "Triage {$n}", 'PSW' => 'secret', 'type' => $type, 'phone' => (string) (910000000 + $n)]);
    }

    private function as(User $user): array { return ['X-User-Id' => (string) $user->id, 'Accept' => 'application/json']; }

    private function bearer(): array { return ['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json']; }
}
