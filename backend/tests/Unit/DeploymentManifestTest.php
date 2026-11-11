<?php

namespace Tests\Unit;

use App\Operations\Deployment\DeploymentManifest;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** The one Laravel-side reader of deployment.json: where it looks, and that every unusable file yields no SHAs. */
final class DeploymentManifestTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/deploy-manifest-' . bin2hex(random_bytes(6));
        File::makeDirectory($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_default_location_is_the_file_the_deploy_script_writes(): void
    {
        $script = (string) file_get_contents(dirname(base_path()) . '/scripts/production-deploy/pi-remote-deploy.sh');
        self::assertSame(1, preg_match('#--output (/home/admin/backend/(\S+deployment\.json))#', $script, $written), 'deploy script output line not found');
        // /home/admin/backend is the Laravel app root on the Pi, so base_path() + the relative path is the written file.
        self::assertSame($written[2], DeploymentManifest::RELATIVE_PATH);
        config(['pop_inapp.deployment_manifest' => null]);
        self::assertSame(base_path($written[2]), (new DeploymentManifest())->path());
    }

    public function test_env_override_wins_over_the_default_and_an_explicit_path_wins_over_both(): void
    {
        config(['pop_inapp.deployment_manifest' => $this->dir . '/override.json']);
        self::assertSame($this->dir . '/override.json', (new DeploymentManifest())->path());
        self::assertSame($this->dir . '/explicit.json', (new DeploymentManifest($this->dir . '/explicit.json'))->path());
        config(['pop_inapp.deployment_manifest' => '']);
        self::assertSame(base_path(DeploymentManifest::RELATIVE_PATH), (new DeploymentManifest())->path());
    }

    public function test_reads_both_shas_lower_cased_and_distinct(): void
    {
        [$a, $b] = [str_repeat('a', 40), str_repeat('b', 40)];
        $this->write(['backend_sha' => strtoupper($a), 'frontend_build_sha' => $b]);
        self::assertSame(['state' => 'ok', 'shas' => [$a, $b], 'backend_sha' => $a], $this->read());

        $this->write(['backend_sha' => $a, 'frontend_build_sha' => $a]);
        self::assertSame([$a], $this->read()['shas']);
    }

    public function test_a_frontend_only_sha_is_readable_but_has_no_backend_sha(): void
    {
        $b = str_repeat('b', 40);
        $this->write(['backend_sha' => 'nope', 'frontend_build_sha' => $b]);
        self::assertSame(['state' => 'ok', 'shas' => [$b], 'backend_sha' => null], $this->read());
    }

    public function test_every_unusable_manifest_yields_a_state_and_no_shas(): void
    {
        self::assertSame(['state' => 'missing', 'shas' => [], 'backend_sha' => null], $this->read());

        file_put_contents($this->dir . '/m.json', '{"backend_sha":');
        self::assertSame('malformed', $this->read()['state']);
        file_put_contents($this->dir . '/m.json', '"just a string"');
        self::assertSame('malformed', $this->read()['state']);

        foreach ([[], ['backend_sha' => 'abc'], ['backend_sha' => str_repeat('a', 39)], ['backend_sha' => str_repeat('g', 40)],
            ['backend_sha' => 12, 'frontend_build_sha' => ['x']], ['backend_sha' => null]] as $manifest) {
            $this->write($manifest);
            self::assertSame(['state' => 'no_sha', 'shas' => [], 'backend_sha' => null], $this->read(), json_encode($manifest));
        }
    }

    public function test_a_sha_with_a_trailing_newline_is_not_well_formed(): void
    {
        $a = str_repeat('a', 40);
        $this->write(['backend_sha' => $a . "\n", 'frontend_build_sha' => $a . "\n"]);
        self::assertSame(['state' => 'no_sha', 'shas' => [], 'backend_sha' => null], $this->read());
    }

    public function test_source_names_the_rule_that_chose_the_path_without_the_path(): void
    {
        config(['pop_inapp.deployment_manifest' => null]);
        self::assertSame('default', (new DeploymentManifest())->source());
        self::assertSame('explicit', (new DeploymentManifest($this->dir . '/m.json'))->source());
        config(['pop_inapp.deployment_manifest' => $this->dir . '/o.json']);
        self::assertSame('override', (new DeploymentManifest())->source());
        self::assertSame('explicit', (new DeploymentManifest($this->dir . '/m.json'))->source());
    }

    public function test_a_directory_in_place_of_the_file_is_missing(): void
    {
        File::makeDirectory($this->dir . '/m.json');
        self::assertSame('missing', $this->read()['state']);
    }

    public function test_a_file_the_process_cannot_read_is_unreadable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores file permissions');
        }
        $this->write(['backend_sha' => str_repeat('a', 40)]);
        chmod($this->dir . '/m.json', 0000);
        self::assertSame(['state' => 'unreadable', 'shas' => [], 'backend_sha' => null], $this->read());
    }

    private function write(array $manifest): void
    {
        file_put_contents($this->dir . '/m.json', json_encode($manifest));
    }

    private function read(): array
    {
        return (new DeploymentManifest($this->dir . '/m.json'))->read();
    }
}
