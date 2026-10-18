<?php

namespace Tests\Feature\Billing;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgreedFeeTestSchema;
use Tests\TestCase;

/** Source #4247: extension DDL must refuse unsafe targets and open transactions. */
class AgreedFeeSchemaSafetyTest extends TestCase
{
    /** @dataProvider unsafeSchemaOperations */
    public function test_unsafe_targets_are_refused_before_schema_access(array $overrides, string $method): void
    {
        $config = array_replace(['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'AllTrue_test'], $overrides);
        $connection = \Mockery::mock(Connection::class);
        $schema = \Mockery::mock(\Illuminate\Database\Schema\Builder::class);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        $connection->shouldReceive('getConfig')->andReturn($config);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn($config['database']);
        DB::shouldReceive('connection')->andReturn($connection);
        $schema->shouldReceive('hasTable')->andThrow(new \LogicException('Schema access before target validation'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('isolated synthetic');
        AgreedFeeTestSchema::$method();
    }

    public static function unsafeSchemaOperations(): array
    {
        $cases = [];
        foreach (self::unsafeTargets() as [$overrides]) {
            foreach (['ensureExtensionColumns', 'restoreBaseSchemaAfterTest'] as $method) {
                $cases[] = [$overrides, $method];
            }
        }
        return $cases;
    }

    public static function unsafeTargets(): array
    {
        return [[['database' => 'AllTrue']], [['host' => 'remote.invalid']],
            [['url' => 'mysql://unused:unused@remote.invalid/AllTrue']],
            [['unix_socket' => '/tmp/unused.sock']], [['read' => ['host' => 'remote.invalid']]],
            [['write' => ['database' => 'AllTrue']]], [['options' => [1002 => 'USE AllTrue']]],
            [['host' => 'localhost']]];
    }

    /** @dataProvider schemaOperations */
    public function test_missing_extensions_cannot_implicit_commit_a_test_transaction(string $method): void
    {
        $connection = \Mockery::mock(Connection::class);
        $schema = \Mockery::mock(\Illuminate\Database\Schema\Builder::class);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        $connection->shouldReceive('getConfig')->andReturn(['host' => '127.0.0.1']);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn('AllTrue_test');
        $connection->shouldReceive('transactionLevel')->andReturn(1);
        DB::shouldReceive('connection')->andReturn($connection);
        $schema->shouldReceive('hasTable')->with('invoice_corrections')->andReturn(true);
        $schema->shouldReceive('hasColumns')->andReturn(false);
        DB::shouldReceive('statement')->andThrow(new \LogicException('DDL before transaction guard'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('open test transaction');
        AgreedFeeTestSchema::$method();
    }

    public static function schemaOperations(): array
    {
        return [['ensureExtensionColumns'], ['restoreBaseSchemaAfterTest']];
    }
}
