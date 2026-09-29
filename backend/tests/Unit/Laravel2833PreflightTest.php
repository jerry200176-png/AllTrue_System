<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Laravel2833PreflightTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(base_path()) . '/scripts/laravel-2833-preflight.php';
    }

    public function test_revision_mismatch_is_rejected_before_bootstrap(): void
    {
        $this->expectException(\RuntimeException::class);
        \Laravel2833Preflight::assertRevision(str_repeat('a', 40), str_repeat('b', 40));
    }

    public function test_missing_revision_is_not_accepted(): void
    {
        $this->expectException(\RuntimeException::class);
        \Laravel2833Preflight::assertRevision(str_repeat('a', 40), '');
    }

    public function test_application_paths_are_bound_before_configuration_loading(): void
    {
        $root = base_path();
        $cache = $_ENV['APP_CONFIG_CACHE'] ?? null;
        $serverCache = $_SERVER['APP_CONFIG_CACHE'] ?? null;
        $fixture = tempnam(sys_get_temp_dir(), 'framework-path-');
        try {
            $app = new \Illuminate\Foundation\Application($root);
            \Laravel2833Preflight::assertApplicationPaths($app, $root);
            foreach (['base', 'environment', 'cache'] as $override) {
                $app->setBasePath($override === 'base' ? sys_get_temp_dir() : $root);
                $app->useEnvironmentPath($override === 'environment' ? sys_get_temp_dir() : $root);
                $_ENV['APP_CONFIG_CACHE'] = $override === 'cache' ? '/nonexistent/foreign-config.php' : 'bootstrap/cache/config.php';
                if ($override === 'cache') {
                    unset($_ENV['APP_CONFIG_CACHE']);
                    file_put_contents($fixture, 'APP_CONFIG_CACHE=/nonexistent/foreign-config.php' . "\n");
                    \Dotenv\Dotenv::createMutable(dirname($fixture), basename($fixture))->safeLoad();
                }
                try {
                    \Laravel2833Preflight::assertApplicationPaths($app, $root);
                    $this->fail('An application path override was accepted: ' . $override);
                } catch (\RuntimeException $error) {
                    $this->assertSame('unapproved_application_paths', $error->getMessage());
                }
            }
        } finally {
            unlink($fixture);
            if ($cache === null) {
                unset($_ENV['APP_CONFIG_CACHE']);
            } else {
                $_ENV['APP_CONFIG_CACHE'] = $cache;
            }
            if ($serverCache === null) {
                unset($_SERVER['APP_CONFIG_CACHE']);
            } else {
                $_SERVER['APP_CONFIG_CACHE'] = $serverCache;
            }
            \Illuminate\Container\Container::setInstance($this->app);
        }
    }

    public function test_selected_dotenv_values_are_classified_without_exporting_other_values(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'framework-flags-');
        try {
            file_put_contents($path, "DB_PASSWORD=private-sentinel\nFEATURE_SCHEDULE_OCCURRENCE_V2=true\nFEATURE_SCHEDULE_OCCURRENCE_V2_CAMPUS_2=null\nFEATURE_ENSURE_SESSION_HORIZON_CAMPUS_9=unknown-sentinel\nFEATURE_UNRELATED=true\n");
            $values = \Laravel2833Preflight::readFlagFile($path, ['FEATURE_SCHEDULE_OCCURRENCE_V2', 'FEATURE_ENSURE_SESSION_HORIZON']);
            $this->assertSame(['kind' => 'boolean', 'enabled' => true], $values['FEATURE_SCHEDULE_OCCURRENCE_V2']);
            $this->assertSame(['kind' => 'null', 'enabled' => null], $values['FEATURE_SCHEDULE_OCCURRENCE_V2_CAMPUS_2']);
            $this->assertSame(['kind' => 'invalid', 'enabled' => false], $values['FEATURE_ENSURE_SESSION_HORIZON_CAMPUS_9']);
            $this->assertArrayNotHasKey('FEATURE_ENSURE_SESSION_HORIZON', $values);
            $this->assertCount(3, $values);
            $this->assertStringNotContainsString('sentinel', json_encode($values));
        } finally {
            unlink($path);
        }
    }

    public function test_unreadable_flag_source_is_not_defaulted_to_empty(): void
    {
        $this->expectException(\RuntimeException::class);
        \Laravel2833Preflight::readFlagFile('/nonexistent/flags.fixture', ['FEATURE_SCHEDULE_OCCURRENCE_V2']);
    }

    public function test_real_mysql_read_only_transaction_rejects_writes_and_rolls_back(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE framework_preflight_write_guard (id INT PRIMARY KEY) ENGINE=InnoDB');
        try {
            try {
                \Laravel2833Preflight::readOnly($pdo, function () use ($pdo) {
                    $this->assertTrue($pdo->inTransaction());
                    $pdo->exec('INSERT INTO framework_preflight_write_guard VALUES (1)');
                });
                $this->fail('A read-only transaction accepted a write');
            } catch (\PDOException $error) {
                $this->assertSame('25006', $error->getCode());
                $this->assertSame(1792, $error->errorInfo[1]);
            }
            $this->assertFalse($pdo->inTransaction());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM framework_preflight_write_guard')->fetchColumn());
        } finally {
            $pdo->exec('DROP TABLE framework_preflight_write_guard');
        }
    }

    public function test_interpolation_is_rejected_instead_of_guessing_a_missing_reference(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'framework-flags-');
        try {
            file_put_contents($path, 'FEATURE_SCHEDULE_OCCURRENCE_V2=${PRIVATE_SETTING}' . "\n");
            $this->expectException(\RuntimeException::class);
            \Laravel2833Preflight::readFlagFile($path, ['FEATURE_SCHEDULE_OCCURRENCE_V2']);
        } finally {
            unlink($path);
        }
    }

    public function test_parenthesized_literal_normalization(): void
    {
        $this->assertSame(['kind' => 'boolean', 'enabled' => true], \Laravel2833Preflight::classify('(true)'));
        $this->assertSame(['kind' => 'boolean', 'enabled' => false], \Laravel2833Preflight::classify('(false)'));
        $this->assertSame(['kind' => 'null', 'enabled' => null], \Laravel2833Preflight::classify('(null)'));
        $this->assertSame(['kind' => 'boolean', 'enabled' => false], \Laravel2833Preflight::classify('(empty)'));
    }

    public function test_actual_cli_rejects_fixed_production_target_before_autoload_or_database(): void
    {
        $script = dirname(base_path()) . '/scripts/laravel-2833-preflight.php';
        $request = dirname(base_path()) . '/operations/closeout/laravel-2833-preflight.request.json';
        $process = new \Symfony\Component\Process\Process(['php', '-r',
            'require $argv[1]; Laravel2833Preflight::run(json_decode(file_get_contents($argv[2]), true));', $script, $request], base_path());
        $process->run();
        $result = json_decode($process->getOutput(), true);
        $this->assertSame(1, $process->getExitCode());
        $this->assertFalse($result['ok']);
        $this->assertSame('revision', $result['stage']);
        $this->assertSame('RuntimeException', $result['failure_type']);
    }

    public function test_complete_metadata_collects_real_isolated_ledger_and_native_flag_reads(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE framework_preflight_migrations (migration VARCHAR(255)) ENGINE=InnoDB');
        $path = tempnam(sys_get_temp_dir(), 'framework-flags-');
        try {
            $request = json_decode(file_get_contents(dirname(base_path()) . '/operations/closeout/laravel-2833-preflight.request.json'), true);
            $migration = basename(glob(database_path('migrations/*_*.php'))[0], '.php');
            $pdo->prepare('INSERT INTO framework_preflight_migrations VALUES (?)')->execute([$migration]);
            config(['database.migrations' => 'framework_preflight_migrations', 'feature_flags.values' => [
                'FEATURE_SCHEDULE_OCCURRENCE_V2' => false, 'FEATURE_SCHEDULE_OCCURRENCE_V2_CAMPUS_2' => true,
                'FEATURE_ENSURE_SESSION_HORIZON_CAMPUS_987654' => true, 'FEATURE_UNRELATED_CAMPUS_987654' => true,
            ]]);
            file_put_contents($path, "FEATURE_SCHEDULE_OCCURRENCE_V2=true\nFEATURE_SCHEDULE_OCCURRENCE_V2_CAMPUS_2=false\n");
            $report = \Laravel2833Preflight::collect($this->app, $request, $path);
            $this->assertTrue($report['ok']);
            $this->assertTrue($report['read_only']);
            $this->assertNotContains($migration, $report['pending_migrations']);
            $this->assertNotEmpty($report['pending_migrations']);
            $this->assertTrue($report['flags']['FEATURE_SCHEDULE_OCCURRENCE_V2']['file']['enabled']);
            $this->assertFalse($report['flags']['FEATURE_SCHEDULE_OCCURRENCE_V2']['fresh_current_revision_enabled']);
            $this->assertFalse($report['flags']['FEATURE_SCHEDULE_OCCURRENCE_V2_CAMPUS_2']['file']['enabled']);
            $this->assertTrue($report['flags']['FEATURE_SCHEDULE_OCCURRENCE_V2_CAMPUS_2']['fresh_current_revision_enabled']);
            $this->assertSame(['kind' => 'missing', 'enabled' => false], $report['flags']['FEATURE_ENSURE_SESSION_HORIZON_CAMPUS_987654']['file']);
            $this->assertTrue($report['flags']['FEATURE_ENSURE_SESSION_HORIZON_CAMPUS_987654']['fresh_current_revision_enabled']);
            $this->assertArrayNotHasKey('FEATURE_UNRELATED_CAMPUS_987654', $report['flags']);
            $this->assertFalse($pdo->inTransaction());
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM framework_preflight_migrations')->fetchColumn());
        } finally {
            unlink($path);
            $pdo->exec('DROP TABLE framework_preflight_migrations');
        }
    }
}
