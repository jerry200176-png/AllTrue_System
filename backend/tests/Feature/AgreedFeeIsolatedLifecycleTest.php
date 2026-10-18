<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** #4253: reject redirects before providers, then migrate only the guarded test connection. */
class AgreedFeeIsolatedLifecycleTest extends TestCase
{
    private array $roots = [];
    private array $probeTables = [];

    protected function tearDown(): void
    {
        foreach ($this->probeTables as [$table, $migration]) {
            Schema::dropIfExists($table);
            if (Schema::hasTable('migrations')) {
                DB::table('migrations')->where('migration', $migration)->delete();
            }
        }
        foreach ($this->roots as $root) {
            (new Filesystem)->deleteDirectory($root);
        }
        parent::tearDown();
    }

    /** @dataProvider rejectedEnvironments */
    public function test_unsafe_environment_stops_before_any_fixture_provider(array $override): void
    {
        [$process, $root] = $this->runFixture($override);
        $this->assertSame(90, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($root.'/provider.json');
        $this->assertStringContainsString('db-guard: refusing', $process->getErrorOutput());
        $this->assertStringNotContainsString('synthetic-secret', $process->getErrorOutput());
    }

    public static function rejectedEnvironments(): array
    {
        return [
            [['APP_ENV' => 'production']], [['DB_DATABASE' => 'AllTrue']],
            [['DB_DATABASE' => "AllTrue_test_bad\n"]], [['DB_HOST' => 'remote.invalid']],
            [['DATABASE_URL' => 'mysql://synthetic-secret@remote.invalid/AllTrue']],
            [['DB_URL' => 'mysql://synthetic-secret@remote.invalid/AllTrue']],
            [['DB_SOCKET' => '/tmp/synthetic.sock']], [['DB_READ_HOST' => 'remote.invalid']],
            [['DB_WRITE_HOST' => 'remote.invalid']],
        ];
    }

    /** @dataProvider rejectedConnections */
    public function test_loaded_redirect_stops_before_providers_and_migrator(array $override): void
    {
        [$process, $root] = $this->runFixture([], $override, true);
        $this->assertSame(90, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($root.'/provider.json');
        $this->assertStringContainsString('db-guard: refusing', $process->getErrorOutput());
        $this->assertStringNotContainsString('synthetic-secret', $process->getErrorOutput());
    }

    public static function rejectedConnections(): array
    {
        return [
            [['driver' => 'sqlite']], [['host' => 'remote.invalid']], [['database' => 'AllTrue']],
            [['database' => 'AllTrue_test_other_target']], [['port' => 0]],
            [['url' => 'mysql://synthetic-secret@remote.invalid/AllTrue']],
            [['unix_socket' => '/tmp/synthetic.sock']], [['options' => [1 => 'synthetic-option']]],
            [['read' => ['host' => 'remote.invalid']]], [['write' => ['database' => 'AllTrue']]],
            [['read' => 'malformed']], [['write' => 'malformed']],
        ];
    }

    public function test_cached_config_is_refused_before_bootstrap(): void
    {
        [$process, $root] = $this->runFixture([], [], true, true);
        $this->assertSame(90, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($root.'/provider.json');
        $this->assertStringContainsString('config.php present', $process->getErrorOutput());
    }

    public function test_guarded_boot_preserves_target_and_installs_only_synthetic_runtime(): void
    {
        [$process, $root, $db] = $this->runFixture();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $observed = json_decode(file_get_contents($root.'/provider.json'), true);
        $this->assertSame('127.0.0.1', $observed['host']);
        $this->assertSame($db['database'], $observed['database']);
        $this->assertSame((int) $db['port'], $observed['port']);
        $this->assertSame('testing', $observed['environment']);
        $this->assertTrue($observed['enabled']);
        $this->assertSame(['http://127.0.0.1:5179', 'http://localhost:5179',
            'http://127.0.0.1:8000', 'http://localhost:8000'], $observed['origins']);
        foreach (['url', 'unix_socket', 'options', 'read', 'write'] as $redirect) {
            $this->assertNotContains($redirect, $observed['keys']);
        }
    }

    public function test_alternate_config_cache_is_refused_before_providers(): void
    {
        [$process, $root] = $this->runFixture([], [], false, 'alternate');
        $this->assertSame(90, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertFileDoesNotExist($root.'/provider.json');
    }

    public function test_dotenv_cache_without_native_putenv_is_refused_before_providers(): void
    {
        [$process, $root] = $this->runFixture([], [], false, 'dotenv');
        $this->assertSame(90, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertFileDoesNotExist($root.'/provider.json');
    }

    public function test_same_process_entry_runs_one_real_migration_and_replay_runs_none(): void
    {
        [$process, $root, , $environment] = $this->runFixture([], [], true);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertFileExists($root.'/provider.json');
        $this->assertStringContainsString('migrate: OK', $process->getOutput());
        [$table, $migration] = end($this->probeTables);
        $this->assertTrue(Schema::hasTable($table));
        $this->assertSame(1, DB::table($table)->count());
        $this->assertSame(1, DB::table('migrations')->where('migration', $migration)->count());
        $again = new Process([PHP_BINARY, '-d', 'zend.exception_ignore_args=1',
            $root.'/tests/Support/agreed-fee-isolated-migrate.php'], $root, $environment);
        $again->setTimeout(20);
        $again->run();
        $this->assertSame(0, $again->getExitCode(), $again->getErrorOutput());
        $this->assertSame(1, DB::table($table)->count());
        $this->assertSame(1, DB::table('migrations')->where('migration', $migration)->count());
    }

    private function runFixture(array $envOverride = [], array $configOverride = [], bool $migrate = false, bool|string $cached = false): array
    {
        $root = sys_get_temp_dir().'/alltrue-fee-lifecycle-'.bin2hex(random_bytes(8));
        $this->roots[] = $root;
        foreach (['vendor', 'bootstrap/cache', 'config', 'database/migrations', 'tests/Support'] as $directory) {
            mkdir($root.'/'.$directory, 0700, true);
        }
        $db = config('database.connections.mysql');
        require_once base_path('tests/Support/alltrueTestDatabase.php');
        $db = alltrue_race_database_config($db);
        config(['database.connections.mysql' => $db]);
        DB::purge('mysql');
        $environment = array_replace(['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => false,
            'DB_HOST' => $db['host'], 'DB_DATABASE' => $db['database'], 'DB_PORT' => (string) $db['port'],
            'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'],
            'DATABASE_URL' => false, 'DB_URL' => false, 'DB_SOCKET' => false,
            'DB_READ_HOST' => false, 'DB_WRITE_HOST' => false,
            'LIFECYCLE_PROVIDER_MARKER' => $root.'/provider.json'], $envOverride);
        $loaded = array_replace($db, ['read' => [], 'write' => []], $configOverride);
        $writePhp = static fn ($path, $value) => file_put_contents($path, '<?php return '.var_export($value, true).';');
        $writePhp($root.'/config/database.php', ['default' => 'mysql', 'migrations' => 'migrations',
            'connections' => ['mysql' => $loaded]]);
        $writePhp($root.'/config/app.php', ['providers' => [
            \AgreedFeeLifecycleCanary::class,
            \Illuminate\Filesystem\FilesystemServiceProvider::class,
            \Illuminate\Database\DatabaseServiceProvider::class,
            \Illuminate\Database\MigrationServiceProvider::class,
        ]]);
        file_put_contents($root.'/vendor/autoload.php', '<?php require '.var_export(base_path('vendor/autoload.php'), true).';');
        file_put_contents($root.'/bootstrap/app.php', <<<'PHP'
<?php
class AgreedFeeLifecycleCanary extends \Illuminate\Support\ServiceProvider {
    public function register() {
        file_put_contents(getenv('LIFECYCLE_PROVIDER_MARKER'), json_encode([
            'host' => config('database.connections.mysql.host'),
            'database' => config('database.connections.mysql.database'),
            'port' => config('database.connections.mysql.port'),
            'keys' => array_keys(config('database.connections.mysql')),
            'environment' => config('app.env'), 'enabled' => config('billing.agreed_fee_correction_enabled'),
            'origins' => config('cors.allowed_origins'),
        ], JSON_THROW_ON_ERROR));
    }
}
$app = new \Illuminate\Foundation\Application(dirname(__DIR__));
$app->singleton(\Illuminate\Contracts\Console\Kernel::class, \Illuminate\Foundation\Console\Kernel::class);
$app->singleton(\Illuminate\Contracts\Debug\ExceptionHandler::class, \Illuminate\Foundation\Exceptions\Handler::class);
return $app;
PHP);
        $guard = base_path('tests/Support/agreed-fee-isolated-db-guard.php');
        file_put_contents($root.'/tests/Support/agreed-fee-isolated-db-guard.php', '<?php require '.var_export($guard, true).';');
        copy(base_path('tests/Support/agreed-fee-isolated-migrate.php'), $root.'/tests/Support/agreed-fee-isolated-migrate.php');
        if ($cached === true) $writePhp($root.'/bootstrap/cache/config.php', []);
        if ($cached === 'alternate' || $cached === 'dotenv') {
            if ($cached === 'alternate') {
                $environment['APP_CONFIG_CACHE'] = $root.'/alternate.php';
            } else {
                file_put_contents($root.'/.env', 'APP_CONFIG_CACHE='.$root.'/alternate.php');
                $bootstrap = $root.'/bootstrap/app.php';
                file_put_contents($bootstrap, str_replace('$app = new', '\Illuminate\Support\Env::disablePutenv();'."\n".'$app = new',
                    file_get_contents($bootstrap)));
            }
            $writePhp($root.'/alternate.php', ['database' => require $root.'/config/database.php',
                'app' => require $root.'/config/app.php']);
        }
        if ($migrate) {
            $table = 'fee_lifecycle_'.bin2hex(random_bytes(6));
            $migration = '2026_10_18_000001_create_'.$table;
            $this->probeTables[] = [$table, $migration];
            file_put_contents($root.'/database/migrations/'.$migration.'.php', '<?php return new class extends \Illuminate\Database\Migrations\Migration {
                public function up() {
                    \Illuminate\Support\Facades\Schema::create("'.$table.'", function ($table) { $table->integer("marker"); });
                    \Illuminate\Support\Facades\DB::table("'.$table.'")->insert(["marker" => 1]);
                }
            };');
        }
        $command = $migrate ? [PHP_BINARY, '-d', 'zend.exception_ignore_args=1', $root.'/tests/Support/agreed-fee-isolated-migrate.php']
            : [PHP_BINARY, '-d', 'zend.exception_ignore_args=1', '-r',
                'require $argv[1]; agreed_fee_isolated_boot_application($argv[2]); echo "boot: OK\n";', $guard, $root];
        $process = new Process($command, $root, $environment);
        $process->setTimeout(20);
        $process->run();
        return [$process, $root, $db, $environment];
    }
}
