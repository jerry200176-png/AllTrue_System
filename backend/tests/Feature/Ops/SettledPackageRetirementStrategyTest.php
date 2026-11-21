<?php

namespace Tests\Feature\Ops;

use App\Models\AuthToken;
use App\Models\User;
use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\SettledPackageRetirementStrategy as Strategy;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

final class SettledPackageRetirementStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['Student', 'StudentClass', 'ClassSession', 'course_packages', 'Invoice', 'InvoiceItem',
        'Payment', 'payment_reports', 'package_session_ledger'];

    private Strategy $strategy;
    private array $parameters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new Strategy();
        $this->parameters = ['campus_id' => 9, 'package_id' => 61, 'course_id' => 1383, 'invoice_id' => 746,
            'decision_reference' => Strategy::OPERATION, 'expected_state_hash' => str_repeat('a', 64)];

        DB::table('Student')->insert(['id' => 40001, 'name' => 'Fixture', 'CampusID' => 9, 'ClassID' => 1, 'enable' => 1]);
        DB::table('course_packages')->insert(['id' => 61, 'student_id' => 40001, 'campus_id' => 9, 'name' => 'Fixture package',
            'billing_mode' => 'count', 'total_sessions' => 1, 'used_sessions' => 1, 'remaining_sessions' => 0, 'paid' => 1,
            'stop' => 0, 'paid_at' => '2026-06-01', 'updated_at' => '2026-07-01 10:00:00']);
        DB::table('StudentClass')->insert(['ID' => 1383, 'StudentID' => 40001, 'PackageID' => 61, 'Stop' => 1,
            'closed_reason' => 'settled', 'ScheduleMode' => 'count', 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'StartDate' => '2026-06-02', 'TotalHours' => 2, 'RemainingSessions' => 0, 'Paid' => 1]);
        DB::table('ClassSession')->insert(['id' => 40002, 'StudentClassID' => 1383, 'SessionDate' => '2026-06-02',
            'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended']);
        DB::table('package_session_ledger')->insert(['package_id' => 61, 'student_class_id' => 1383,
            'class_session_id' => 40002, 'delta' => -1, 'reason' => 'attendance']);
        DB::table('Invoice')->insert(['id' => 746, 'StudentID' => 40001, 'StudentClassID' => 1383, 'IssueDate' => '2026-06-01',
            'TotalAmount' => 1200, 'PaidAmount' => 1200, 'Status' => 'paid']);
        DB::table('InvoiceItem')->insert(['InvoiceID' => 746, 'StudentClassID' => 1383, 'Description' => 'Fixture', 'Amount' => 1200]);
        DB::table('Payment')->insert(['id' => 40003, 'InvoiceID' => 746, 'Amount' => 1200, 'Method' => 'transfer', 'PaidAt' => '2026-06-01']);
        DB::table('payment_reports')->insert(['id' => 40004, 'StudentID' => 40001, 'StudentClassID' => 1383, 'InvoiceID' => 746,
            'status' => 'confirmed', 'reported_amount' => '1200.00', 'payment_id' => 40003, 'payment_date' => '2026-06-01',
            'reported_by_name' => 'Fixture', 'report_token_hash' => str_repeat('b', 64), 'token_expires_at' => now()]);
        $state = (new ReflectionMethod($this->strategy, 'readState'))->invoke($this->strategy, $this->parameters, false);
        $this->parameters['expected_state_hash'] = hash('sha256', json_encode(PopOperationService::canonicalParameters($state), JSON_THROW_ON_ERROR));
    }

    public function test_only_the_package_stop_flag_and_audit_change_then_exact_rollback_restores_the_before_state(): void
    {
        $before = $this->businessRows();
        $plan = $this->strategy->plan($this->parameters);
        $this->assertTrue($plan['ok']);
        $context = $this->nativeContext();
        $result = DB::transaction(fn () => $this->strategy->execute($plan, $context));
        $this->assertTrue(DB::transaction(fn () => $this->strategy->verify($plan, $result))['ok']);
        $expected = $before;
        $expected['course_packages'][0]['stop'] = 1;
        $this->assertSame($expected, $this->businessRows());
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'course_package.settled_retirement', 'correlation_id' => $context['operation_id']]);
        $this->assertFalse($this->strategy->plan($this->parameters)['ok']);

        $this->assertTrue(DB::transaction(fn () => $this->strategy->rollback($result['snapshot'], $context))['ok']);
        $this->assertSame($before, $this->businessRows());
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'course_package.settled_retirement.rollback']);
    }

    #[DataProvider('ineligibleChanges')]
    public function test_live_scope_and_accounting_drift_block_apply(string $table, array $fields, string $expectedError): void
    {
        $plan = $this->strategy->plan($this->parameters);
        $this->assertTrue($plan['ok']);
        DB::table($table)->update($fields);
        $afterDrift = $this->businessRows();
        $this->assertSame([$expectedError], $this->strategy->plan($this->parameters)['errors'] ?? []);
        try {
            DB::transaction(fn () => $this->strategy->execute($plan, $this->nativeContext()));
            $this->fail('An older plan executed after the live data changed.');
        } catch (DomainException $error) {
            $this->assertSame($expectedError, $error->getMessage());
        }
        $this->assertSame($afterDrift, $this->businessRows());
    }

    public static function ineligibleChanges(): array
    {
        return [
            'moved campus' => ['Student', ['CampusID' => 1], 'package_student_campus_mismatch'],
            'covered by a different course' => ['InvoiceItem', ['StudentClassID' => 1384], 'package_invoice_has_other_coverage'],
            'ledger credit' => ['package_session_ledger', ['delta' => 1], 'package_ledger_not_exactly_exhausted'],
            'ledger row drift without net change' => ['package_session_ledger', ['updated_at' => '2026-06-03 00:00:00'], 'package_state_changed'],
            'remaining entitlement' => ['course_packages', ['remaining_sessions' => 1], 'package_not_exhausted_and_paid'],
            'closed member reopened' => ['StudentClass', ['Stop' => 0], 'sole_member_not_settled'],
            'new scheduled lesson' => ['ClassSession', ['Status' => 'scheduled'], 'package_has_open_lessons'],
            'unpaid invoice' => ['Invoice', ['Status' => 'unpaid'], 'package_invoice_not_paid'],
            'unresolved receipt' => ['payment_reports', ['status' => 'pending'], 'package_receipt_not_confirmed'],
            'different payment' => ['Payment', ['Amount' => 1], 'package_receipt_ledger_not_settled'],
            'changed date' => ['Invoice', ['DueDate' => '2026-08-01'], 'package_state_changed'],
        ];
    }

    #[DataProvider('additionalRows')]
    public function test_additional_members_documents_and_ledger_entries_refuse_a_prior_plan(
        string $table, string $idColumn, array $changes, string $expectedError,
    ): void {
        $plan = $this->strategy->plan($this->parameters);
        $row = (array) DB::table($table)->first();
        DB::table($table)->insert(array_replace($row, [$idColumn => 40100], $changes));
        $before = $this->businessRows();
        $this->assertSame([$expectedError], $this->strategy->plan($this->parameters)['errors'] ?? []);
        try {
            DB::transaction(fn () => $this->strategy->execute($plan, $this->nativeContext()));
            $this->fail('An old plan ignored an additional row.');
        } catch (DomainException $error) {
            $this->assertSame($expectedError, $error->getMessage());
        }
        $this->assertSame($before, $this->businessRows());
    }

    public static function additionalRows(): array
    {
        return [
            'second member' => ['StudentClass', 'ID', [], 'sole_member_not_settled'],
            'second invoice' => ['Invoice', 'id', ['Status' => 'unpaid'], 'package_invoice_not_paid'],
            'second receipt' => ['payment_reports', 'id', ['status' => 'pending', 'report_token_hash' => str_repeat('e', 64)], 'package_receipt_not_confirmed'],
            'negative payment' => ['Payment', 'id', ['Amount' => -1200, 'Method' => 'void'], 'package_receipt_ledger_not_settled'],
            'added neutral ledger row' => ['package_session_ledger', 'id', ['class_session_id' => null, 'delta' => 0], 'package_state_changed'],
        ];
    }

    public function test_preview_requires_a_super_admin_and_can_only_return_an_opaque_digest_for_the_case(): void
    {
        $url = '/api/v1/pop/operations/reviewed-settled-package-retirement/preview';
        $this->getJson($url)->assertUnauthorized();
        $directorToken = $this->tokenFor('A');
        $this->withToken($directorToken)->getJson($url)->assertForbidden();
        $lockedOwnerToken = $this->tokenFor('S', true);
        $this->withToken($lockedOwnerToken)->getJson($url)->assertStatus(428)->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $ownerToken = $this->tokenFor('S');
        $before = $this->businessRows();
        $this->withToken($ownerToken)->getJson($url.'?package_id=62')->assertOk()->assertExactJson([
            'data' => ['ok' => true, 'parameters' => $this->parameters],
        ]);
        $this->assertSame($before, $this->businessRows());
        $this->assertDatabaseCount('pop_operation_requests', 0);
        $this->assertDatabaseCount('pop_execution_records', 0);
        $this->assertDatabaseCount('pop_approval_events', 0);

        DB::table('package_session_ledger')->delete();
        $this->withToken($ownerToken)->getJson($url)->assertStatus(409)
            ->assertExactJson(['data' => ['ok' => false, 'errors' => ['package_ledger_not_exactly_exhausted']]]);
    }

    private function tokenFor(string $type, bool $mustChangePassword = false): string
    {
        $user = User::create(['LoginName' => 'package-preview-'.$type.'-'.(int) $mustChangePassword, 'Name' => 'Fixture',
            'PSW' => 'test', 'type' => $type, 'phone' => '', 'MustChangePassword' => $mustChangePassword]);
        $token = bin2hex(random_bytes(32));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addHour()]);

        return $token;
    }

    public function test_unapproved_drafts_and_non_native_execution_are_refused(): void
    {
        $plan = $this->strategy->plan($this->parameters);
        $this->assertSame(['retirement_target_not_reviewed'], $this->strategy->plan(
            array_replace($this->parameters, ['package_id' => 62]))['errors']);
        try {
            DB::transaction(fn () => $this->strategy->execute($plan, ['actor' => 'user:9', 'operation_id' => (string) Str::uuid()]));
            $this->fail('A non-native caller executed the repair.');
        } catch (DomainException $error) {
            $this->assertSame('native_atomic_operation_required', $error->getMessage());
        }

        $service = new PopOperationService(app(PopOperationCatalog::class), null, null,
            sys_get_temp_dir().'/missing-retirement-approval-'.Str::uuid());
        $this->expectExceptionMessage('no exact active Founder authorization');
        $service->createDraft(Strategy::OPERATION, $this->parameters, (string) Str::uuid(), 'user:9', 'super_admin', [9], 9);
    }

    public function test_rollback_refuses_later_history_changes(): void
    {
        $plan = $this->strategy->plan($this->parameters);
        $context = $this->nativeContext();
        $result = DB::transaction(fn () => $this->strategy->execute($plan, $context));
        DB::table('ClassSession')->where('StudentClassID', 1383)->update(['EndTime' => '17:30:00']);
        $this->assertFalse(DB::transaction(fn () => $this->strategy->verify($plan, $result))['ok']);
        $this->expectExceptionMessage('package_state_changed_after_retirement');
        DB::transaction(fn () => $this->strategy->rollback($result['snapshot'], $context));
    }

    public function test_native_round_trip_and_failed_execution_receipt_roll_back_the_flag_and_audit(): void
    {
        $owner = User::create(['LoginName' => 'settled-package-fixture', 'Name' => 'Fixture', 'PSW' => 'test', 'type' => 'S', 'phone' => '']);
        $actor = 'user:'.$owner->id;
        $key = (string) Str::uuid();
        $hash = hash('sha256', json_encode(PopOperationService::canonicalParameters($this->parameters), JSON_THROW_ON_ERROR));
        $reference = 'founder-go-'.Strategy::OPERATION.'-'.substr($hash, 0, 16);
        $sha = str_repeat('a', 40);
        $directory = sys_get_temp_dir().'/synthetic-package-retirement-'.Str::uuid();
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

        try {
            $service = new PopOperationService(app(PopOperationCatalog::class), null, $deployment, $authorization);
            $request = $service->createDraft(Strategy::OPERATION, $this->parameters, $key, $actor, 'super_admin', [9], $owner->id);
            $id = $request['id'];
            $this->assertTrue($service->runDryRun($id, $actor, $owner->id, 'super_admin', [9])['ok']);
            $approval = $service->approve($id, $reference, $actor, 'super_admin', $sha, $owner->id);
            $this->assertTrue($approval['ready']);
            $token = $approval['token'];
            $before = $this->businessRows();
            $fault = (object) ['active' => true];
            DB::listen(function ($query) use ($fault): void {
                if ($fault->active && str_starts_with($query->sql, 'insert ignore into ')
                    && str_contains($query->sql, 'pop_execution_records') && in_array('execute', $query->bindings, true)) {
                    $fault->active = false;
                    throw new \RuntimeException('synthetic_retirement_receipt_failure');
                }
            });
            $this->assertFalse($service->run($id, 'execute', $token, $sha, 'pop-pi-local')['ok']);
            $this->assertFalse($fault->active);
            $this->assertSame($before, $this->businessRows());
            $this->assertDatabaseMissing('security_audit_events', ['correlation_id' => $id]);

            $this->assertTrue($service->run($id, 'execute', $token, $sha, 'pop-pi-local')['ok']);
            $this->assertTrue($service->run($id, 'verify', $token, $sha, 'pop-pi-local')['ok']);
            $this->assertDatabaseHas('course_packages', ['id' => 61, 'stop' => 1]);
            $this->assertTrue($service->run($id, 'rollback', $token, $sha, 'pop-pi-local')['ok']);
            $this->assertSame($before, $this->businessRows());
        } finally {
            unlink($authorization);
            unlink($deployment);
            rmdir($directory);
        }
    }

    private function businessRows(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $table) => [
            $table => DB::table($table)->get()->map(fn (object $row) => (array) $row)->all(),
        ])->all();
    }

    private function nativeContext(): array
    {
        return ['actor' => 'pop-pi-local', 'operation_id' => (string) Str::uuid()];
    }
}
