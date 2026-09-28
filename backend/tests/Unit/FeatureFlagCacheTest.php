<?php

namespace Tests\Unit;

use App\Helpers\FeatureFlag;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Support\Env;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

/** @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class FeatureFlagCacheTest extends TestCase
{
    private const KEY = 'dynamic-test.flag';
    private const NAME = 'FEATURE_DYNAMIC_TEST_FLAG';
    private Container $previousContainer;
    private ?string $cacheRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->clearFixture();
        new Application(sys_get_temp_dir());
    }

    protected function tearDown(): void
    {
        $this->clearFixture();
        Container::setInstance($this->previousContainer);
        if ($this->cacheRoot !== null) {
            unlink($this->cacheRoot . '/bootstrap/cache/config.php');
            rmdir($this->cacheRoot . '/bootstrap/cache');
            rmdir($this->cacheRoot . '/bootstrap');
            rmdir($this->cacheRoot);
        }
        parent::tearDown();
    }

    /** @dataProvider flagCases */
    #[DataProvider('flagCases')]
    public function test_configured_values_survive_fresh_cached_bootstrap(?string $global, ?string $override, int $campus, bool $expected): void
    {
        $this->seed(self::NAME, $global);
        $this->seed(self::NAME . '_CAMPUS_' . $campus, $override);
        $flags = require dirname(__DIR__, 2) . '/config/feature_flags.php';
        $config = ['app' => ['env' => 'testing', 'timezone' => 'UTC'], 'feature_flags' => $flags];
        app()->instance('config', new Repository($config));
        self::assertSame($expected, FeatureFlag::enabled(self::KEY, $campus));
        self::assertSame(!$expected, FeatureFlag::disabled(self::KEY, $campus));

        $this->cacheRoot = sys_get_temp_dir() . '/feature-cache-' . bin2hex(random_bytes(8));
        mkdir($this->cacheRoot . '/bootstrap/cache', 0700, true);
        file_put_contents($this->cacheRoot . '/bootstrap/cache/config.php', '<?php return ' . var_export($config, true) . ';');
        $this->clearFixture();
        $app = new Application($this->cacheRoot);
        (new LoadEnvironmentVariables())->bootstrap($app);
        (new LoadConfiguration())->bootstrap($app);
        self::assertTrue($app->configurationIsCached());
        self::assertSame($config, $app['config']->all());
        self::assertSame($expected, FeatureFlag::enabled(self::KEY, $campus));
        self::assertSame(!$expected, FeatureFlag::disabled(self::KEY, $campus));
        self::assertFalse(FeatureFlag::enabled('missing-key', $campus));
    }

    public static function flagCases(): iterable
    {
        foreach ([0, 777, 2147483647] as $campus) {
            foreach ([
                'missing-default-off' => [null, null, false],
                'global-on' => ['true', null, true],
                'campus-false-overrides-on' => ['true', 'false', false],
                'campus-empty-overrides-on' => ['true', '', false],
                'campus-invalid-overrides-on' => ['true', 'invalid', false],
                'campus-on-overrides-off' => ['false', 'TRUE', true],
                'null-falls-back' => ['true', 'null', true],
                'parenthesized-null-falls-back' => ['true', '(null)', true],
                'quoted-null-is-invalid' => ['true', '"null"', false],
                'quoted-on' => ['false', '"true"', true],
                'parenthesized-off' => ['true', '(false)', false],
            ] as $label => [$global, $override, $expected]) {
                yield $label . '-' . $campus => [$global, $override, $campus, $expected];
            }
        }
    }

    public function test_adapter_precedence_is_preserved_without_caching_unrelated_values(): void
    {
        putenv(self::NAME . '=true');
        $_ENV[self::NAME] = 'true';
        $_SERVER[self::NAME] = 'false';
        $_SERVER['UNRELATED_SYNTHETIC_VALUE'] = 'must-not-be-cached';
        try {
            $flags = require dirname(__DIR__, 2) . '/config/feature_flags.php';
            app()->instance('config', new Repository(['feature_flags' => $flags]));
            self::assertFalse(FeatureFlag::enabled(self::KEY));
            self::assertArrayNotHasKey('UNRELATED_SYNTHETIC_VALUE', $flags['values']);
            foreach ($flags['values'] as $value) {
                self::assertTrue(is_bool($value) || $value === null);
            }
        } finally {
            unset($_SERVER['UNRELATED_SYNTHETIC_VALUE']);
        }
    }

    public function test_snapshot_changes_only_after_configuration_reload(): void
    {
        $this->seed(self::NAME, 'false');
        $flags = require dirname(__DIR__, 2) . '/config/feature_flags.php';
        app()->instance('config', new Repository(['feature_flags' => $flags]));
        self::assertFalse(FeatureFlag::enabled(self::KEY));
        $this->seed(self::NAME, 'true');
        self::assertFalse(FeatureFlag::enabled(self::KEY));
        app()['config']->set('feature_flags', require dirname(__DIR__, 2) . '/config/feature_flags.php');
        self::assertTrue(FeatureFlag::enabled(self::KEY));
    }

    public function test_getenv_only_name_is_discovered(): void
    {
        putenv(self::NAME . '=true');
        $flags = require dirname(__DIR__, 2) . '/config/feature_flags.php';
        app()->instance('config', new Repository(['feature_flags' => $flags]));
        self::assertTrue(FeatureFlag::enabled(self::KEY));
    }

    public function test_env_only_name_is_discovered(): void
    {
        $_ENV[self::NAME] = 'true';
        $flags = require dirname(__DIR__, 2) . '/config/feature_flags.php';
        app()->instance('config', new Repository(['feature_flags' => $flags]));
        self::assertTrue(FeatureFlag::enabled(self::KEY));
    }

    private function seed(string $name, ?string $value): void
    {
        if ($value !== null) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    private function clearFixture(): void
    {
        foreach (['', '_CAMPUS_0', '_CAMPUS_777', '_CAMPUS_2147483647'] as $suffix) {
            $name = self::NAME . $suffix;
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        Env::enablePutenv();
    }
}
