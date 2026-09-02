<?php

namespace Tests\Feature;

use App\Operations\PopOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class PopOperationServiceScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_dry_run_rejects_a_request_outside_the_actor_campus_scope(): void
    {
        $parameters = ['campus_id' => 9];
        $requestId = (string) Str::uuid();
        DB::table('pop_operation_requests')->insert([
            'id' => $requestId,
            'operation_id' => 'course-contract-repair',
            'strategy_id' => 'App\\Operations\\Strategies\\CourseContractRepairStrategy',
            'catalog_version' => 1,
            'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'parameters_hash' => hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR)),
            'idempotency_key' => 'scope-test-' . Str::lower(Str::random(12)),
            'status' => 'draft',
            'actor' => 'user:42',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POP campus is outside the actor scope.');

        app(PopOperationService::class)->runDryRun($requestId, 'user:42', 42, 'director', [16]);
    }
}
