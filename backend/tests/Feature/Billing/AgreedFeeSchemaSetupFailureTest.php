<?php

namespace Tests\Feature\Billing;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgreedFeeRefreshDatabase;
use Tests\TestCase;

/** Exercise real Laravel setup/destruction with a mocked post-ALTER failure. */
class AgreedFeeSchemaSetupFailureTest extends TestCase
{
    use AgreedFeeRefreshDatabase { refreshTestDatabase as private agreedFeeRefreshTestDatabase; }

    private bool $extensionAltered = false;
    private bool $setupFailureCaught = false;

    protected function refreshTestDatabase()
    {
        RefreshDatabaseState::$migrated = true;
        $connection = \Mockery::mock(Connection::class);
        $schema = \Mockery::mock(Builder::class);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        $connection->shouldReceive('getConfig')->andReturn(['host' => '127.0.0.1']);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn('AllTrue_test');
        $connection->shouldReceive('transactionLevel')->andReturn(0);
        DB::shouldReceive('connection')->andReturn($connection);
        $schema->shouldReceive('hasTable')->with('invoice_corrections')->andReturn(true);
        $schema->shouldReceive('hasTable')->with('Subject')->andReturn(false);
        $schema->shouldReceive('hasColumns')->andReturn(false);
        $schema->shouldReceive('getColumnListing')->andReturn([]);
        DB::shouldReceive('statement')->once()->andReturnUsing(function () {
            $this->extensionAltered = true;
            return true; // No actual schema write; model the committed ALTER boundary.
        });
        DB::shouldReceive('select')->andThrow(new \RuntimeException('Synthetic post-ALTER setup failure'));
        try {
            $this->agreedFeeRefreshTestDatabase();
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'Synthetic post-ALTER setup failure') {
                throw $e;
            }
            $this->setupFailureCaught = true;
        }
    }

    public function test_setup_failure_invalidates_generation_when_application_is_destroyed(): void
    {
        $this->assertTrue($this->extensionAltered);
        $this->assertTrue($this->setupFailureCaught);
        $this->beforeApplicationDestroyed(function () {
            $this->assertFalse(RefreshDatabaseState::$migrated);
        });
    }
}
