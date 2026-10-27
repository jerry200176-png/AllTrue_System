<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** #4448: exercise the unchanged production CLI boundary against the CI database. */
class InappPackageLedgerProbeEntrypointTest extends TestCase
{
    use RefreshDatabase;

    private const SENTINEL = 'private-probe-sentinel SQL password must never reach output';

    /** @var list<string> */
    private array $fixtureRoots = [];

    protected function tearDown(): void
    {
        try {
            foreach ($this->fixtureRoots as $root) {
                (new Filesystem)->deleteDirectory($root);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_the_real_entrypoint_returns_only_allowed_json_and_rolls_back(): void
    {
        [$process, $observed, $campusId, $packageId] = $this->runProbe('success');

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertSame([
            'read_only' => true,
            'campus_id' => $campusId,
            'package_id' => $packageId,
            'all_packages' => false,
            'target_package_ids' => [],
            'session_read_only' => 1,
        ], json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(1, $observed['probe_selects']);
        $this->assertTrue($observed['rolled_back']);
    }

    public function test_the_server_refuses_a_write_inside_the_actual_probe_transaction(): void
    {
        [$process, $observed] = $this->runProbe('write');

        $this->assertSame(2, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertSame("probe_failed: Illuminate\\Database\\QueryException\n", $process->getErrorOutput());
        $this->assertSame('25006', $observed['sqlstate']);
        $this->assertSame(1792, $observed['driver_code']);
        $this->assertTrue($observed['rolled_back'], 'a rejected write must still reach the real rollback');
        $this->assertStringNotContainsString(self::SENTINEL, $process->getErrorOutput());
    }

    public function test_an_exception_message_cannot_leak_and_the_transaction_is_rolled_back(): void
    {
        [$process, $observed] = $this->runProbe('exception');

        $this->assertSame(2, $process->getExitCode());
        $this->assertSame('', $process->getOutput());
        $this->assertSame("probe_failed: RuntimeException\n", $process->getErrorOutput());
        $this->assertTrue($observed['rolled_back']);
        $this->assertStringNotContainsString(self::SENTINEL, $process->getErrorOutput());
    }

    /** @return array{Process, array<string, mixed>, int, int} */
    private function runProbe(string $mode): array
    {
        $config = DB::connection()->getConfig();
        $this->assertSame('mysql', $config['driver'], 'this boundary needs a real MySQL/MariaDB server');
        $this->assertMatchesRegularExpression('/^AllTrue_test(?:_[a-zA-Z0-9_]+)?$/D', $config['database']);
        $this->assertContains($config['host'], ['127.0.0.1', 'localhost']);

        // No fixture rows or committed business changes are needed in the child connection.
        $campusId = (int) DB::table('Campus')->max('id') + 1;
        $packageId = (int) DB::table('course_packages')->max('id') + 1;
        $backend = dirname(__DIR__, 2);
        $root = sys_get_temp_dir() . '/alltrue-probe-entry-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($root, 0700));
        $this->fixtureRoots[] = $root;
        mkdir($root . '/vendor', 0700);
        mkdir($root . '/bootstrap', 0700);
        mkdir($root . '/storage', 0700);
        file_put_contents($root . '/vendor/autoload.php', '<?php require ' . var_export($backend . '/vendor/autoload.php', true) . ';');
        copy($backend . '/tests/Support/inapp-package-ledger-probe-bootstrap.php', $root . '/bootstrap/app.php');

        $process = new Process([
            PHP_BINARY, '-d', 'zend.exception_ignore_args=1',
            dirname($backend) . '/scripts/probes/inapp-package-ledger-probe.php',
        ], $backend, [
            'APP_ENV' => 'testing', 'APP_ROOT' => $root,
            'APP_KEY' => (string) config('app.key'),
            'APP_CONFIG_CACHE' => $root . '/config-cache.php',
            'APP_SERVICES_CACHE' => $root . '/services-cache.php',
            'APP_PACKAGES_CACHE' => $root . '/packages-cache.php',
            'APP_ROUTES_CACHE' => $root . '/routes-cache.php',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $config['host'],
            'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $config['database'],
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
            'DB_SOCKET' => $config['unix_socket'] ?? '',
            'DB_URL' => false, 'DATABASE_URL' => false, 'DB_READ_HOST' => false, 'DB_WRITE_HOST' => false,
            'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array', 'TELESCOPE_ENABLED' => 'false',
            'INAPP_PACKAGE_LEDGER_PROBE' => '1', 'CAMPUS_ID' => (string) $campusId,
            'PACKAGE_ID' => (string) $packageId, 'ALL_PACKAGES' => 'false',
            'PROBE_TEST_BACKEND' => $backend, 'PROBE_TEST_MODE' => $mode,
            'PROBE_TEST_SENTINEL' => self::SENTINEL,
        ]);
        $process->setTimeout(30);
        $process->run();

        $this->assertFileExists($root . '/observed.json', $process->getErrorOutput());
        $observed = json_decode((string) file_get_contents($root . '/observed.json'), true, 512, JSON_THROW_ON_ERROR);

        return [$process, $observed, $campusId, $packageId];
    }
}
