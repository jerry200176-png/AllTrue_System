<?php

namespace Tests\Unit;

use App\Operations\PopOperationCatalog;
use PHPUnit\Framework\TestCase;

final class PopOperationCatalogTest extends TestCase
{
    public function test_catalog_version_is_readable(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml');

        self::assertSame(1, $catalog->version());
    }

    public function test_policy_version_is_required_from_the_default_policy(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 3) . '/operations/catalog.yaml');

        self::assertSame(1, $catalog->policyVersion());
    }
    public function test_execution_record_schema_and_runner_boundary_are_declared(): void
    {
        $root = dirname(__DIR__, 3);
        $schema = json_decode((string) file_get_contents($root . '/docs/pop/EXECUTION_RECORD_SCHEMA.json'), true, 512, JSON_THROW_ON_ERROR);
        $workflow = (string) file_get_contents($root . '/.github/workflows/pop-execute.yml');

        foreach (['schema_version', 'operation_id', 'execution_id', 'strategy', 'result', 'correlation_id', 'version_pins'] as $key) {
            self::assertArrayHasKey($key, $schema['properties']);
        }
        self::assertStringContainsString('backend/bin/pop execute', $workflow);
        self::assertStringNotContainsString('ssh ', $workflow);
        self::assertStringNotContainsString('artisan', $workflow);
    }
}
