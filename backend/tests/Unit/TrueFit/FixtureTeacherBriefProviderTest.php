<?php

namespace Tests\Unit\TrueFit;

use App\Services\TrueFit\FixtureTeacherBriefProvider;
use App\Services\TrueFit\TrueFitMaterialCatalog;
use App\Services\TrueFit\TrueFitTeacherBriefContract;
use Tests\TestCase;

class FixtureTeacherBriefProviderTest extends TestCase
{
    public function test_fixture_brief_covers_required_contract_keys(): void
    {
        $catalog = new TrueFitMaterialCatalog();
        $material = $catalog->find('syn.math.fractions.v1');
        $this->assertNotNull($material);

        $brief = (new FixtureTeacherBriefProvider(new TrueFitTeacherBriefContract()))
            ->generate($material, '數學');

        foreach (TrueFitTeacherBriefContract::REQUIRED_KEYS as $key) {
            $this->assertArrayHasKey($key, $brief);
        }
        $this->assertSame('fixture', $brief['provider']);
        $this->assertSame('syn.math.fractions.v1', $brief['material_unit_key']);
    }
}
