<?php

namespace Tests\Feature\Ops;

use App\Http\Controllers\AccountingController;
use App\Models\Notification;
use App\Models\StudentClass;
use App\Models\User;
use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\HistoricalOrphanInvoiceCloseoutStrategy as Strategy;
use App\Services\NotificationSyncService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HistoricalOrphanInvoiceCloseoutStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const BILL = 40001;
    private const COURSE = 40002;
    private const STUDENT = 40003;
    private const REPORT = 40004;
    private const CASH = 40005;
    private const REVERSAL = 40006;
    private const LEAVE = 40007;
    private const REMOVED_SESSION = 40008;

    private array $parameters;

    protected function setUp(): void
    {
        parent::setUp();
        $now = '2026-06-30 12:20:00';
        DB::table('Student')->insert(['id' => self::STUDENT, 'name' => 'fixture', 'CampusID' => 11, 'ClassID' => 1, 'enable' => 1]);
        DB::table('Invoice')->insert(['id' => self::BILL, 'StudentID' => self::STUDENT, 'StudentClassID' => self::COURSE,
            'IssueDate' => '2026-06-26', 'DueDate' => null, 'TotalAmount' => 9120, 'PaidAmount' => 0, 'Status' => 'unpaid',
            'Note' => 'original evidence', 'billing_period' => null, 'updated_at' => $now]);
        DB::table('payment_reports')->insert(['id' => self::REPORT, 'StudentID' => self::STUDENT, 'StudentClassID' => self::COURSE,
            'InvoiceID' => self::BILL, 'status' => 'voided', 'reported_amount' => '9120.00', 'payment_id' => self::CASH,
            'payment_date' => '2026-06-25', 'voided_at' => $now, 'updated_at' => $now,
            'reported_by_name' => 'fixture', 'report_token_hash' => str_repeat('a', 64), 'token_expires_at' => $now]);
        DB::table('Payment')->insert([
            ['id' => self::CASH, 'InvoiceID' => self::BILL, 'Amount' => 9120, 'Method' => 'cash',
                'PaidAt' => '2026-06-25 08:00:00', 'payment_report_id' => null, 'updated_at' => $now],
            ['id' => self::REVERSAL, 'InvoiceID' => self::BILL, 'Amount' => -9120, 'Method' => 'void',
                'PaidAt' => $now, 'payment_report_id' => self::REPORT, 'updated_at' => $now],
        ]);
        $leave = ['id' => self::LEAVE, 'StudentID' => self::STUDENT, 'StudentClassID' => self::COURSE,
            'CampusID' => 11, 'ClassSessionID' => self::REMOVED_SESSION, 'PersonType' => 'student', 'Status' => 'leave', 'SessionDeducted' => 0,
            'Hours' => null, 'SignInDT' => '2026-06-25 18:00:00', 'SignOutDT' => null, 'VoidedAt' => null];
        DB::table('StudentSingIn')->insert($leave);
        // Construct the safe-field digest independently as the private operator packet does.
        $state = [
            'student' => ['id' => self::STUDENT, 'CampusID' => 11],
            'leave' => $leave,
            'invoice' => ['id' => self::BILL, 'StudentID' => self::STUDENT, 'StudentClassID' => self::COURSE, 'IssueDate' => '2026-06-26',
                'DueDate' => null, 'TotalAmount' => 9120, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => null, 'updated_at' => $now],
            'report' => ['id' => self::REPORT, 'StudentID' => self::STUDENT, 'StudentClassID' => self::COURSE, 'InvoiceID' => self::BILL,
                'status' => 'voided', 'reported_amount' => '9120.00', 'payment_id' => self::CASH, 'payment_date' => '2026-06-25',
                'voided_at' => $now, 'updated_at' => $now],
            'payments' => [
                ['id' => self::CASH, 'InvoiceID' => self::BILL, 'Amount' => 9120, 'Method' => 'cash',
                    'PaidAt' => '2026-06-25 08:00:00', 'payment_report_id' => null, 'updated_at' => $now],
                ['id' => self::REVERSAL, 'InvoiceID' => self::BILL, 'Amount' => -9120, 'Method' => 'void',
                    'PaidAt' => $now, 'payment_report_id' => self::REPORT, 'updated_at' => $now],
            ],
        ];
        $this->parameters = ['campus_id' => 11, 'student_id' => self::STUDENT, 'course_id' => self::COURSE, 'invoice_id' => self::BILL,
            'report_id' => self::REPORT, 'expected_issue_date' => '2026-06-26', 'expected_invoice_total' => 9120,
            'decision_reference' => Strategy::OPERATION,
            'expected_state_hash' => hash('sha256', json_encode(PopOperationService::canonicalParameters($state), JSON_THROW_ON_ERROR))];
    }

    public function test_updates_only_the_uncollectible_invoice_and_restores_exactly(): void
    {
        $svc = new Strategy();
        $this->addFallInvoice();
        $this->assertSame(10720, $this->outstanding());
        $before = $this->ledger();
        $plan = $svc->plan($this->parameters);
        $this->assertTrue($plan['ok']);
        $this->assertSame($before, $this->ledger(), 'dry run is read-only');
        $context = ['actor' => 'pop-pi-local', 'operation_id' => (string) Str::uuid()];
        $applied = DB::transaction(fn () => $svc->execute($plan, $context));
        $after = $this->ledger();
        $expected = $before;
        $expected['invoice']['Status'] = 'void';
        $expected['invoice']['updated_at'] = $after['invoice']['updated_at'];
        $this->assertSame($expected, $after);
        $this->assertTrue($svc->verify([], $applied)['ok']);

        NotificationSyncService::sync([11]);
        $this->assertDatabaseMissing(Notification::class, ['CampusID' => 11, 'SourceKey' => 'invoice_overdue:11:'.self::BILL, 'ResolvedAt' => null]);

        $this->assertSame(1600, $this->outstanding(), 'October invoice remains outstanding');
        DB::transaction(fn () => $svc->rollback($applied['snapshot'], $context));
        $this->assertSame($before, $this->ledger());
        $this->assertSame(10720, $this->outstanding());
    }

    public function test_exact_scope_and_ledger_drift_fail_closed(): void
    {
        $svc = new Strategy();
        foreach ([
            ['campus_id' => 13], ['expected_issue_date' => '2026-10-01'], ['expected_state_hash' => str_repeat('a', 64)],
            ['report_id' => 5], ['expected_invoice_total' => 9121],
        ] as $change) {
            $this->assertFalse($svc->plan(array_replace($this->parameters, $change))['ok']);
        }

        $plan = $svc->plan($this->parameters);
        DB::table('Payment')->where('id', self::REVERSAL)->update(['Amount' => -9000]);
        $before = $this->ledger();
        try {
            $svc->execute($plan, ['actor' => 'pop-pi-local', 'operation_id' => (string) Str::uuid()]);
            $this->fail('Stale ledger accepted');
        } catch (DomainException $error) {
            $this->assertSame('receipt_not_fully_reversed', $error->getMessage());
        }
        $this->assertSame($before, $this->ledger());
    }

    public function test_course_activity_other_documents_and_due_invoices_are_ineligible(): void
    {
        $svc = new Strategy();
        $plan = $svc->plan($this->parameters);
        $context = ['actor' => 'pop-pi-local', 'operation_id' => (string) Str::uuid()];
        $changes = [
            'live_source' => fn () => $this->activeCourse(self::COURSE),
            'held_lesson' => fn () => DB::table('ClassSession')->insert(['StudentClassID' => self::COURSE,
                'SessionDate' => '2026-06-25', 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'completed']),
            'other_bill' => fn () => DB::table('Invoice')->insert(['StudentID' => self::STUDENT, 'StudentClassID' => self::COURSE,
                'IssueDate' => '2026-06-30', 'TotalAmount' => 200, 'PaidAmount' => 0, 'Status' => 'unpaid']),
            'item' => fn () => DB::table('InvoiceItem')->insert(['InvoiceID' => self::BILL,
                'StudentClassID' => self::COURSE, 'Description' => 'fixture', 'Amount' => 100]),
            'leave_other_person_type' => fn () => DB::table('StudentSingIn')->where('id', self::LEAVE)->update(['PersonType' => 'teacher']),
            'deduction_ledger' => fn () => DB::table('session_deduction_ledger')->insert([
                'student_class_id' => self::COURSE, 'event_type' => 'deduct', 'source' => 'manual']),
            'present' => fn () => DB::table('StudentSingIn')->where('id', self::LEAVE)->update(['Status' => 'present']),
            'deducted' => fn () => DB::table('StudentSingIn')->where('id', self::LEAVE)->update(['SessionDeducted' => 1]),
            'leave_other_campus' => fn () => DB::table('StudentSingIn')->where('id', self::LEAVE)->update(['CampusID' => 13]),
            'live_link' => fn () => DB::table('ClassSession')->insert(['id' => self::REMOVED_SESSION, 'StudentClassID' => self::COURSE + 100,
                'SessionDate' => '2026-06-25', 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'scheduled']),
            'due_date' => fn () => DB::table('Invoice')->where('id', self::BILL)->update(['DueDate' => '2026-06-30']),
            'known_period' => fn () => DB::table('Invoice')->where('id', self::BILL)->update(['billing_period' => '2026-06']),
            'extra_payment' => fn () => DB::table('Payment')->insert(['InvoiceID' => self::BILL,
                'Amount' => 100, 'PaidAt' => '2026-06-26 10:00:00', 'Method' => 'cash']),
            'extra_report' => fn () => DB::table('payment_reports')->insert(['StudentID' => self::STUDENT, 'StudentClassID' => self::COURSE,
                'InvoiceID' => self::BILL, 'status' => 'pending', 'reported_amount' => 100, 'payment_date' => '2026-06-29',
                'reported_by_name' => 'fixture', 'report_token_hash' => str_repeat('b', 64), 'token_expires_at' => '2026-06-30 12:00:00']),
        ];
        foreach ($changes as $name => $apply) {
            DB::beginTransaction();
            try {
                $apply();
                $before = $this->ledger();
                $this->assertFalse($svc->plan($this->parameters)['ok'], $name);
                try {
                    $svc->execute($plan, $context);
                    $this->fail("Applied despite {$name}");
                } catch (DomainException) {
                    $this->assertSame($before, $this->ledger(), $name);
                }
            } finally {
                DB::rollBack();
            }
        }
    }

    public function test_native_actor_required_and_rollback_does_not_overwrite_drift(): void
    {
        $svc = new Strategy();
        $plan = $svc->plan($this->parameters);
        $context = ['actor' => 'pop-pi-local', 'operation_id' => (string) Str::uuid()];
        try {
            $svc->execute($plan, array_replace($context, ['actor' => 'pop-runner']));
            $this->fail('Non-native actor accepted');
        } catch (DomainException $error) {
            $this->assertSame('native_atomic_operation_required', $error->getMessage());
        }
        $applied = DB::transaction(fn () => $svc->execute($plan, $context));
        DB::table('Invoice')->where('id', self::BILL)->update(['updated_at' => '2026-11-25 09:30:00']);
        $before = $this->ledger();
        $this->assertFalse($svc->verify([], $applied)['ok']);
        try {
            DB::transaction(fn () => $svc->rollback($applied['snapshot'], $context));
            $this->fail('Stale rollback accepted');
        } catch (DomainException $error) {
            $this->assertSame('state_changed_after_closeout', $error->getMessage());
        }
        $this->assertSame($before, $this->ledger());
    }

    public function test_an_unapproved_request_cannot_be_created(): void
    {
        $service = new PopOperationService(app(PopOperationCatalog::class), null, null,
            sys_get_temp_dir().'/nonexistent-alltrue-policy-'.Str::uuid());
        $this->expectExceptionMessage('no exact active Founder authorization');
        $service->createDraft(Strategy::OPERATION, $this->parameters, (string) Str::uuid(), 'user:3000', 'super_admin', [11], 3000);
    }

    public function test_native_receipt_failure_rolls_back_invoice_and_audit(): void
    {
        $owner = User::create(['LoginName' => 'orphan-closeout-test', 'Name' => 'Fixture', 'PSW' => 'test', 'type' => 'S', 'phone' => '']);
        $actor = 'user:'.$owner->id;
        $key = (string) Str::uuid();
        $hash = hash('sha256', json_encode(PopOperationService::canonicalParameters($this->parameters), JSON_THROW_ON_ERROR));
        $reference = 'founder-go-'.Strategy::OPERATION.'-'.substr($hash, 0, 16);
        $sha = str_repeat('a', 40);
        $directory = sys_get_temp_dir().'/synthetic-void-'.Str::uuid();
        mkdir($directory, 0700);
        $authorization = $directory.'/case.json';
        $deployment = $directory.'/deployment.json';
        file_put_contents($deployment, json_encode(['backend_sha' => $sha], JSON_THROW_ON_ERROR));
        file_put_contents($authorization, json_encode(['version' => 1, 'eligible_cases' => [[
            'operation_id' => Strategy::OPERATION, 'parameters_sha256' => $hash,
            'idempotency_sha256' => hash('sha256', $key), 'requester_sha256' => hash('sha256', $actor),
            'approver_sha256' => hash('sha256', $actor), 'approval_reference' => $reference,
            'valid_until' => now()->addHour()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]]], JSON_THROW_ON_ERROR));
        $service = new PopOperationService(app(PopOperationCatalog::class), null, $deployment, $authorization);
        try {
            $request = $service->createDraft(Strategy::OPERATION, $this->parameters, $key, $actor, 'super_admin', [11], $owner->id);
            $this->assertTrue($service->runDryRun($request['id'], $actor, $owner->id, 'super_admin', [11])['ok']);
            $approval = $service->approve($request['id'], $reference, $actor, 'super_admin', $sha, $owner->id);
            $this->assertTrue($approval['ready']);
            $before = $this->ledger();
            $fault = (object) ['active' => true];
            DB::listen(function ($query) use ($fault): void {
                if ($fault->active && str_starts_with($query->sql, 'insert ignore into `pop_execution_records`')
                    && in_array('execute', $query->bindings, true)) {
                    $fault->active = false;
                    throw new \RuntimeException('synthetic_receipt_failure');
                }
            });
            $this->assertFalse($service->run($request['id'], 'execute', $approval['token'], $sha, 'pop-pi-local')['ok']);
            $this->assertFalse($fault->active);
            $this->assertSame($before, $this->ledger());
            $this->assertDatabaseMissing('security_audit_events', ['correlation_id' => $request['id']]);
            $this->assertSame('idle', $service->runApprovedLocally()['status']);
            $this->assertTrue($service->run($request['id'], 'execute', $approval['token'], $sha, 'pop-pi-local')['ok']);
            $this->assertTrue($service->run($request['id'], 'verify', $approval['token'], $sha, 'pop-pi-local')['ok']);
            $this->assertSame('void', $this->ledger()['invoice']['Status']);
            $this->assertTrue($service->run($request['id'], 'rollback', $approval['token'], $sha, 'pop-pi-local')['ok']);
            $this->assertSame($before, $this->ledger());
        } finally {
            unlink($authorization);
            unlink($deployment);
            rmdir($directory);
        }
    }

    private function addFallInvoice(): void
    {
        $course = $this->activeCourse(41001);
        DB::table('Invoice')->insert(['StudentID' => self::STUDENT, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-10-01', 'TotalAmount' => 1600, 'PaidAmount' => 0, 'Status' => 'unpaid']);
    }

    private function activeCourse(int $id): StudentClass
    {
        return StudentClass::forceCreate(['ID' => $id, 'StudentID' => self::STUDENT, 'TeacherID' => 1, 'GradeID' => 1, 'SubjectID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-10-01', 'EndDate' => '2026-12-31', 'TotalHours' => 8,
            'Charge' => 1600, 'Rate' => 200, 'Paid' => 0, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionCount' => 4, 'RemainingSessions' => 4, 'UsedSessions' => 0, 'SessionDuration' => 120, 'ClassType' => 'trial']);
    }

    private function outstanding(): int
    {
        $request = Request::create('/api/v1/accounting/ledger', 'GET', ['student_id' => self::STUDENT]);
        $request->attributes->set('auth_role', 'super_admin');
        $response = app(AccountingController::class)->ledger($request);
        $this->assertSame(200, $response->getStatusCode());

        return $response->getData(true)['summary']['outstanding_total'];
    }

    private function ledger(): array
    {
        return [
            'invoice' => (array) DB::table('Invoice')->where('id', self::BILL)->first(),
            'payments' => DB::table('Payment')->where('InvoiceID', self::BILL)->orderBy('id')->get()->toJson(),
            'attendance' => (array) DB::table('StudentSingIn')->where('id', self::LEAVE)->first(),
            'report' => (array) DB::table('payment_reports')->where('id', self::REPORT)->first(),
        ];
    }
}
