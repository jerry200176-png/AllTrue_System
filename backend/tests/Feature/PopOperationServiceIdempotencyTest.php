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
