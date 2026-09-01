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
}
