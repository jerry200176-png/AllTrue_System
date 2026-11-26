<?php

namespace Tests\Feature;

use Tests\TestCase;

/** #4056: /api/v1/health reports the release the worker's config was built from; the post-deploy verifier compares it. */
class HealthBuildIdentityTest extends TestCase
{
    public function test_health_reports_the_build_from_the_loaded_config(): void
    {
        $sha = str_repeat('a', 40);
        config(['app.build_sha' => $sha]);

        $this->getJson('/api/v1/health')->assertOk()->assertJson(['status' => 'ok', 'build' => $sha]);
    }

    public function test_health_stays_ok_without_a_manifest(): void
    {
        config(['app.build_sha' => null]);

        $this->getJson('/api/v1/health')->assertOk()->assertExactJsonStructure(['status', 'timestamp', 'build'])
            ->assertJsonPath('build', null);
    }
}
