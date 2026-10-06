<?php

namespace Tests\Feature;

use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class PopRetryTestStrategy
{
    /** @var array<int,bool> */
    public static array $planResults = [];
    public static int $planCalls = 0;
    public static int $executeCalls = 0;

    public static function reset(): void
    {
        self::$planResults = [];
        self::$planCalls = 0;
        self::$executeCalls = 0;
    }

    /** @param array<string,mixed> $parameters @return array<string,mixed> */
    public function plan(array $parameters): array
    {
        self::$planCalls++;
        $ok = array_shift(self::$planResults) ?? true;

        return ['ok' => $ok, 'errors' => $ok ? [] : ['temporary_plan_failure']];
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $context @return array<string,mixed> */
    public function execute(array $plan, array $context): array
    {
        self::$executeCalls++;

        return ['ok' => true, 'result' => 'succeeded'];
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $result @return array<string,mixed> */
    public function verify(array $plan, array $result): array
    {
        return ['ok' => true, 'result' => 'succeeded'];
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $context @return array<string,mixed> */
    public function rollback(array $snapshot, array $context): array
    {
        return ['ok' => true, 'result' => 'succeeded'];
    }
}

final class PopOperationServiceIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private PopOperationService $service;
    private string $catalogDir;

    protected function setUp(): void
    {
        parent::setUp();
        PopRetryTestStrategy::reset();

        $this->catalogDir = sys_get_temp_dir() . '/pop-retry-catalog-' . bin2hex(random_bytes(6));
        mkdir($this->catalogDir . '/policies', 0700, true);
        file_put_contents($this->catalogDir . '/catalog.yaml', implode(PHP_EOL, [
            'version: 7',
            '  course-contract-repair:',
            '    lifecycle: active',
            '    strategy_class: ' . PopRetryTestStrategy::class,
            "    parameter_keys: ['campus_id']",
            '    approval_policy: founder-explicit-single-repair',
            "    approver_roles: ['super_admin']",
            '    founder_approval_required: true',
            '    blast_radius: single_student_contract',
            '    reversible: true',
            '    snapshot_required: true',
            '    rollback_supported: true',
            '    verification_required: true',
            '',
        ]));
        file_put_contents($this->catalogDir . '/policies/default.yaml', "version: 1\n");
        $catalog = new PopOperationCatalog($this->catalogDir . '/catalog.yaml');
        $this->service = new PopOperationService($catalog);
    }

    protected function tearDown(): void
    {
        @unlink($this->catalogDir . '/catalog.yaml');
        @unlink($this->catalogDir . '/policies/default.yaml');
        @unlink($this->catalogDir . '/deployment.json');
        @rmdir($this->catalogDir . '/policies');
        @rmdir($this->catalogDir);
        parent::tearDown();
    }

    public function test_failed_dry_run_retries_after_strategy_recovery_and_preserves_the_failed_attempt(): void
    {
        PopRetryTestStrategy::$planResults = [false, true];
        [$requestId, $key, $context] = $this->draft();

        $failed = $this->dryRun($requestId, $context);
        $recovered = $this->dryRun($requestId, $context);

        self::assertSame('failed', $failed['result']);
        self::assertSame('succeeded', $recovered['result']);
        self::assertNotSame($failed['execution_id'], $recovered['execution_id']);
        self::assertSame(2, PopRetryTestStrategy::$planCalls);
        self::assertSame($key, DB::table('pop_operation_requests')->where('id', $requestId)->value('idempotency_key'));
        self::assertSame(2, DB::table('pop_execution_records')->where('operation_id', $requestId)->count());
        self::assertSame(['failed', 'succeeded'], DB::table('pop_execution_records')->where('operation_id', $requestId)->orderBy('attempt_no')->pluck('result')->all());
        self::assertSame([$key . ':dry-run', $key . ':dry-run'], DB::table('pop_execution_records')->where('operation_id', $requestId)->orderBy('attempt_no')->pluck('idempotency_key')->all());
        self::assertSame([$key . ':dry-run', $key . ':dry-run:attempt:2'], DB::table('pop_execution_records')->where('operation_id', $requestId)->orderBy('attempt_no')->pluck('attempt_key')->all());
    }

    public function test_successful_dry_run_replays_without_running_the_strategy_again(): void
    {
        PopRetryTestStrategy::$planResults = [true];
        [$requestId, , $context] = $this->draft();

        $first = $this->dryRun($requestId, $context);
        $replay = $this->dryRun($requestId, $context);

        self::assertSame('succeeded', $replay['result']);
        self::assertSame($first['execution_id'], $replay['execution_id']);
        self::assertSame(1, PopRetryTestStrategy::$planCalls);
        self::assertSame(1, DB::table('pop_execution_records')->where('operation_id', $requestId)->count());
    }

    public function test_retry_fails_closed_when_request_payload_hash_drifts(): void
    {
        PopRetryTestStrategy::$planResults = [false, true];
        [$requestId, , $context] = $this->draft();
        $this->dryRun($requestId, $context);
        DB::table('pop_operation_requests')->where('id', $requestId)->update(['parameters' => json_encode(['campus_id' => 9, 'drift' => true], JSON_THROW_ON_ERROR)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POP request parameters hash drifted; fail closed.');
        $this->dryRun($requestId, $context);
    }

    public function test_retry_fails_closed_when_catalog_version_drifts(): void
    {
        PopRetryTestStrategy::$planResults = [false, true];
        [$requestId, , $context] = $this->draft();
        $this->dryRun($requestId, $context);
        DB::table('pop_operation_requests')->where('id', $requestId)->update(['catalog_version' => 8]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POP request catalog version is stale; fail closed.');
        $this->dryRun($requestId, $context);
    }

    public function test_retry_fails_closed_when_production_context_drifts(): void
    {
        PopRetryTestStrategy::$planResults = [false, true];
        [$requestId, , $context] = $this->draft();
        $this->dryRun($requestId, $context);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POP retry context does not match request; fail closed.');
        $this->dryRun($requestId, ['production_sha' => str_repeat('b', 40), 'source' => 'github-actions:deploy.yml']);
    }

    public function test_execute_replays_exactly_and_does_not_run_mutation_twice(): void
    {
        PopRetryTestStrategy::$planResults = [true, true];
        [$requestId, , $context] = $this->draft();
        $this->dryRun($requestId, $context);
        $commitSha = str_repeat('c', 40);
        $approval = $this->service->approve($requestId, 'founder-go-pop-retry-test', 'user:2', 'super_admin', $commitSha, 2, [9], 15);

        $first = $this->service->run($requestId, 'execute', $approval['token'], $commitSha, 'pop-pi-local', null, null, $context);
        $replay = $this->service->run($requestId, 'execute', $approval['token'], $commitSha, 'pop-pi-local', null, null, $context);

        self::assertSame('succeeded', $first['result']);
        self::assertSame($first['execution_id'], $replay['execution_id']);
        self::assertSame(1, PopRetryTestStrategy::$executeCalls);
        self::assertSame(1, DB::table('pop_execution_records')->where('operation_id', $requestId)->where('phase', 'execute')->count());
    }

    public function test_verify_and_rollback_after_successful_execute_ignore_plan_drift(): void
    {
        PopRetryTestStrategy::$planResults = [true, true, false, false];
        [$requestId, , $context] = $this->draft();
        $this->dryRun($requestId, $context);
        [$sha, $token] = $this->approved($requestId);

        self::assertSame('succeeded', $this->service->run($requestId, 'execute', $token, $sha, 'pop-pi-local', null, null, $context)['result']);
        self::assertSame('succeeded', $this->service->run($requestId, 'verify', $token, $sha, 'pop-pi-local', null, null, $context)['result']);
        self::assertSame('succeeded', $this->service->run($requestId, 'rollback', $token, $sha, 'pop-pi-local', null, null, $context)['result']);
    }

    public function test_verify_and_rollback_keep_the_strict_gate_without_a_successful_execute(): void
    {
        // dry-run ok, execute plan fails (failed execute record), verify/rollback plans fail too.
        PopRetryTestStrategy::$planResults = [true, false, false, false];
        [$requestId, , $context] = $this->draft();
        $this->dryRun($requestId, $context);
        [$sha, $token] = $this->approved($requestId);

        $execute = $this->service->run($requestId, 'execute', $token, $sha, 'pop-pi-local', null, null, $context);
        self::assertSame('failed', $execute['result']);
        foreach (['verify', 'rollback'] as $phase) {
            $out = $this->service->run($requestId, $phase, $token, $sha, 'pop-pi-local', null, null, $context);
            self::assertNotSame('succeeded', $out['result'], $phase);
            self::assertSame(['temporary_plan_failure'], $out['errors'], $phase);
            self::assertSame('precondition_failed', $out['failure_reason'], $phase);
        }
        self::assertSame(0, PopRetryTestStrategy::$executeCalls);
    }

    /** @return array{0:string,1:string} */
    private function approved(string $requestId): array
    {
        $sha = str_repeat('c', 40);

        return [$sha, $this->service->approve($requestId, 'founder-go-pop-retry-test', 'user:2', 'super_admin', $sha, 2, [9], 15)['token']];
    }

    public function test_dual_approval_financial_repair_still_requires_founder_reference(): void
    {
        $path = $this->catalogDir . '/catalog.yaml';
        file_put_contents($path, str_replace(['founder-explicit-single-repair', "approver_roles: ['super_admin']"],
            ['critical-dual-approval', "approver_roles: ['director', 'super_admin']"], file_get_contents($path)));
        [$requestId, , $context] = $this->draft();
        $this->dryRun($requestId, $context);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Founder-scoped POP approval requires a founder-go reference');
        $this->service->approve($requestId, 'ordinary-director-reference', 'user:2', 'director', str_repeat('c', 40), 2, [9]);
    }

    public function test_local_poll_skips_expired_and_old_version_approvals_without_mutating_their_evidence(): void
    {
        $sha = str_repeat('c', 40);
        $expired = $this->localApprovedRequest($sha);
        DB::table('pop_approval_events')->where('operation_id', $expired)->update(['expires_at' => now()->subMinute()]);
        $old = $this->localApprovedRequest(str_repeat('b', 40));
        $live = $this->localApprovedRequest($sha);
        $engine = $this->localEngine($sha);

        $result = $engine->runApprovedLocally();

        self::assertTrue($result['ok']);
        self::assertSame($live, $result['request_id']);
        self::assertSame('succeeded', DB::table('pop_operation_requests')->where('id', $live)->value('status'));
        foreach ([$expired, $old] as $id) {
            self::assertSame('approved', DB::table('pop_operation_requests')->where('id', $id)->value('status'));
            self::assertSame(1, DB::table('pop_execution_records')->where('operation_id', $id)->count(), 'Only its original dry-run exists');
            self::assertSame(1, DB::table('pop_approval_events')->where('operation_id', $id)->count());
        }
        self::assertSame(1, PopRetryTestStrategy::$executeCalls);
        self::assertSame('idle', $engine->runApprovedLocally()['status']);
        self::assertSame(1, PopRetryTestStrategy::$executeCalls);
    }

    public function test_explicit_local_request_still_rejects_expired_and_wrong_version_approval(): void
    {
        $sha = str_repeat('c', 40);
        $old = $this->localApprovedRequest(str_repeat('b', 40));
        $engine = $this->localEngine($sha);
        try {
            $engine->runApprovedLocally($old);
            self::fail('Old-version approval must fail closed');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('deployment SHA does not match', $error->getMessage());
        }
        $expired = $this->localApprovedRequest($sha);
        DB::table('pop_approval_events')->where('operation_id', $expired)->update(['expires_at' => now()->subMinute()]);
        try {
            $engine->runApprovedLocally($expired);
            self::fail('Expired approval must fail closed');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('expired approval', $error->getMessage());
        }
        self::assertSame(0, PopRetryTestStrategy::$executeCalls);
    }

    public function test_db_claim_lock_still_prevents_a_competing_local_executor_without_file_cache(): void
    {
        $sha = str_repeat('c', 40);
        $id = $this->localApprovedRequest($sha);
        $engine = $this->localEngine($sha);
        $connection = (string) config('database.default');
        config(['database.connections.pop_competitor' => config('database.connections.' . $connection)]);
        $competitor = DB::connection('pop_competitor');
        $lock = 'alltrue:pop:' . $id;
        self::assertSame(1, (int) $competitor->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock])->acquired);
        try {
            $busy = $engine->runApprovedLocally($id);
            self::assertSame('busy', $busy['status']);
            self::assertSame(0, PopRetryTestStrategy::$executeCalls);
            self::assertSame('approved', DB::table('pop_operation_requests')->where('id', $id)->value('status'));
        } finally {
            $competitor->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            DB::purge('pop_competitor');
        }
        self::assertTrue($engine->runApprovedLocally($id)['ok']);
        self::assertSame(1, PopRetryTestStrategy::$executeCalls);
        self::assertSame('skipped', $engine->runApprovedLocally($id)['status']);
        self::assertSame(1, PopRetryTestStrategy::$executeCalls);
    }

    public function test_local_poll_does_not_execute_a_tampered_token_or_malformed_manifest(): void
    {
        $sha = str_repeat('c', 40);
        $id = $this->localApprovedRequest($sha);
        DB::table('pop_approval_events')->where('operation_id', $id)->update(['token_hash' => str_repeat('0', 64)]);
        try {
            $this->localEngine($sha)->runApprovedLocally();
            self::fail('Token mismatch must fail closed');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('token hash mismatch', $error->getMessage());
        }
        try {
            $this->localEngine('malformed')->runApprovedLocally();
            self::fail('Malformed deployment manifest must fail closed');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('deployment SHA', $error->getMessage());
        }
        self::assertSame(0, PopRetryTestStrategy::$executeCalls);
    }

    public function test_global_db_lock_serializes_distinct_requests_and_is_released_after_failure(): void
    {
        $sha = str_repeat('c', 40);
        $first = $this->localApprovedRequest($sha);
        $second = $this->localApprovedRequest($sha);
        $engine = $this->localEngine($sha);
        config(['database.connections.pop_competitor' => config('database.connections.' . config('database.default'))]);
        $competitor = DB::connection('pop_competitor');
        $lock = 'alltrue:pop:executor';
        self::assertSame(1, (int) $competitor->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock])->acquired);
        try {
            self::assertSame('busy', $engine->runApprovedLocally($first)['status']);
            self::assertSame('busy', $engine->runApprovedLocally($second)['status']);
            self::assertSame(0, PopRetryTestStrategy::$executeCalls);
        } finally {
            $competitor->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
        }
        DB::table('pop_approval_events')->where('operation_id', $first)->update(['token_hash' => str_repeat('0', 64)]);
        try {
            $engine->runApprovedLocally($first);
            self::fail('Tampered token must fail closed');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('token hash mismatch', $error->getMessage());
        }
        try {
            self::assertSame(1, (int) $competitor->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock])->acquired, 'Failure releases the global lock');
        } finally {
            $competitor->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            DB::purge('pop_competitor');
        }
        self::assertTrue($engine->runApprovedLocally($second)['ok']);
        self::assertSame(1, PopRetryTestStrategy::$executeCalls);
    }

    private function localApprovedRequest(string $sha): string
    {
        $key = 'local-poll-' . bin2hex(random_bytes(6));
        $draft = $this->service->createDraft('course-contract-repair', ['campus_id' => 9], $key, 'machine:test', 'pop_machine', [9], 1);
        $this->service->runDryRun($draft['id'], 'machine:test', 1, 'pop_machine', [9]);
        $this->service->approve($draft['id'], 'founder-go-local-poll', 'user:2', 'super_admin', $sha, 2, [9]);

        return $draft['id'];
    }

    private function localEngine(string $sha): PopOperationService
    {
        $path = $this->catalogDir . '/deployment.json';
        file_put_contents($path, json_encode(['backend_sha' => $sha], JSON_THROW_ON_ERROR));

        return new PopOperationService(new PopOperationCatalog($this->catalogDir . '/catalog.yaml'), null, $path);
    }

    /** @return array{0:string,1:string,2:array<string,string>} */
    private function draft(): array
    {
        $key = 'pop-retry-test-' . strtolower(bin2hex(random_bytes(4)));
        $context = ['production_sha' => str_repeat('a', 40), 'source' => 'github-actions:deploy.yml'];
        $draft = $this->service->createDraft('course-contract-repair', ['campus_id' => 9], $key, 'machine:test', 'pop_machine', [9], 1, $context);

        return [(string) $draft['id'], $key, $context];
    }

    /** @param array<string,string> $context @return array<string,mixed> */
    private function dryRun(string $requestId, array $context): array
    {
        return $this->service->runDryRun($requestId, 'machine:test', 1, 'pop_machine', [9], $context);
    }
}
