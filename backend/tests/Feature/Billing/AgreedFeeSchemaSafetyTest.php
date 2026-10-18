<?php

namespace Tests\Feature\Billing;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AgreedFeeTestSchema;
use Tests\TestCase;

/** Source #4247: extension DDL must refuse unsafe targets and open transactions. */
class AgreedFeeSchemaSafetyTest extends TestCase
{
    /** @dataProvider unsafeTargets */
    public function test_unsafe_targets_are_refused_before_schema_access(array $overrides): void
    {
        Schema::swap(\Mockery::mock(\Illuminate\Database\Schema\Builder::class));
        $config = array_replace(['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'AllTrue_test'], $overrides);
        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getConfig')->andReturn($config);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn($config['database']);
        DB::shouldReceive('connection')->andReturn($connection);
        Schema::shouldReceive('hasTable')->never();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('isolated synthetic');
        AgreedFeeTestSchema::ensureExtensionColumns();
    }

    public static function unsafeTargets(): array
    {
        return [[['database' => 'AllTrue']], [['host' => 'remote.invalid']],
            [['url' => 'mysql://unused:unused@remote.invalid/AllTrue']],
            [['unix_socket' => '/tmp/unused.sock']], [['read' => ['host' => 'remote.invalid']]],
            [['write' => ['database' => 'AllTrue']]]];
    }

    public function test_missing_extensions_cannot_implicit_commit_a_test_transaction(): void
    {
        Schema::swap(\Mockery::mock(\Illuminate\Database\Schema\Builder::class));
        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getConfig')->andReturn(['host' => '127.0.0.1']);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn('AllTrue_test');
        $connection->shouldReceive('transactionLevel')->andReturn(1);
        DB::shouldReceive('connection')->andReturn($connection);
        Schema::shouldReceive('hasTable')->with('invoice_corrections')->andReturn(true);
        Schema::shouldReceive('hasColumns')->andReturn(false);
        DB::shouldReceive('statement')->never();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('open test transaction');
        AgreedFeeTestSchema::ensureExtensionColumns();
    }
}
