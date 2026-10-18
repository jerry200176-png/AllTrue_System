<?php

namespace Tests\Feature;

use GuzzleHttp\Client;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\AgreedFeeRefreshDatabase;
use Tests\TestCase;

/** #4253: actual loopback HTTP and isolated MySQL, never production cases or mocked API responses. */
class AgreedFeeIsolatedHttpTest extends TestCase
{
    use AgreedFeeRefreshDatabase { refreshTestDatabase as private refreshGuardedSchema; }
    private ?Process $server = null;
    private ?string $artifacts = null;
    private array $childEnv;
    private string $baseUrl;
    private string $token;

    protected function refreshTestDatabase()
    {
        $name = (string) config('database.connections.mysql.database');
        if ($name === 'AllTrue_test' && !(getenv('CI') === 'true' && getenv('GITHUB_ACTIONS') === 'true')) {
            $this->markTestSkipped('Run through scripts/phpunit-isolated.sh; shared local schema is refused.');
        }
        $this->refreshGuardedSchema();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->endOuterTestingTransactions();
        $this->artifacts = sys_get_temp_dir().'/alltrue-fee-http-'.bin2hex(random_bytes(8));
        mkdir($this->artifacts, 0700);
        $db = config('database.connections.mysql');
        $this->childEnv = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'],
            'APP_CONFIG_CACHE' => false, 'DATABASE_URL' => false, 'DB_URL' => false, 'DB_SOCKET' => false,
            'DB_READ_HOST' => false, 'DB_WRITE_HOST' => false, 'PHP_CLI_SERVER_WORKERS' => false,
            'AGREED_FEE_ISOLATED_ARTIFACT_DIR' => $this->artifacts];
    }

    protected function tearDown(): void
    {
        if ($this->server) $this->server->stop(2);
        if ($this->artifacts) (new Filesystem)->deleteDirectory($this->artifacts);
        parent::tearDown();
    }

    private function runSeed(array $override = []): Process
    {
        $process = new Process([PHP_BINARY, '-d', 'zend.exception_ignore_args=1',
            base_path('tests/Support/agreed-fee-isolated-seed.php')], base_path(), array_replace($this->childEnv, $override));
        $process->setTimeout(20); $process->run();
        return $process;
    }

    private function startServer(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false); fclose($socket);
        $this->baseUrl = 'http://'.$address;
        $this->server = new Process([PHP_BINARY, '-d', 'zend.exception_ignore_args=1', '-S', $address,
            '-t', base_path('public'), base_path('tests/Support/agreed-fee-isolated-router.php')], base_path(), $this->childEnv);
        $this->server->setTimeout(null); $this->server->start();
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $probe = @stream_socket_client('tcp://'.$address, $errno, $error, 0.05);
            if ($probe) { fclose($probe); return; }
            if (!$this->server->isRunning()) break;
            usleep(50000);
        }
        $this->fail('Isolated HTTP server did not start.');
    }

    private function request(string $method, string $path, array $body = [], bool $authorized = true): array
    {
        $options = ['http_errors' => false, 'allow_redirects' => false, 'timeout' => 5,
            'headers' => ['Accept' => 'application/json']];
        if ($authorized) $options['headers']['Authorization'] = 'Bearer '.$this->token;
        if ($method === 'POST') $options['json'] = $body;
        $response = (new Client)->request($method, $this->baseUrl.$path, $options);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true) ?? []];
    }

    private function seedGuardedFixture(): Process
    {
        $before = DB::table('StudentClass')->count();
        $this->assertSame(90, $this->runSeed(['AGREED_FEE_ISOLATED_ARTIFACT_DIR' => sys_get_temp_dir()])->getExitCode());
        $this->assertSame($before, DB::table('StudentClass')->count());
        $rejected = $this->runSeed(['DB_DATABASE' => 'AllTrue']);
        $this->assertSame(90, $rejected->getExitCode());
        $this->assertSame($before, DB::table('StudentClass')->count());
        $this->assertFileDoesNotExist($this->artifacts.'/fixture.env');
        $seed = $this->runSeed(); $this->assertSame(0, $seed->getExitCode(), $seed->getErrorOutput());
        $before = DB::table('StudentClass')->count();
        $this->assertSame(90, $this->runSeed()->getExitCode());
        $this->assertSame($before, DB::table('StudentClass')->count());
        return $seed;
    }

    public function test_real_http_fee_apply_replay_and_state_changed_preserve_all_other_records(): void
    {
        $seed = $this->seedGuardedFixture(); $this->assertSame(0, $seed->getExitCode(), $seed->getErrorOutput());
        $environment = parse_ini_file($this->artifacts.'/fixture.env', false, INI_SCANNER_RAW);
        $this->token = $environment['AGREED_FEE_ISOLATED_TOKEN'];
        $this->assertFalse(str_contains($seed->getOutput(), $this->token));
        foreach (['fixture.env', 'courses.json'] as $file) $this->assertSame(0600, fileperms($this->artifacts.'/'.$file) & 0777);
        $allow = json_decode(file_get_contents($this->artifacts.'/courses.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(5, $allow['course_ids']);
        $this->startServer();
        $this->assertSame(401, $this->request('GET', '/__isolated/fee-snapshot?course_id='.$allow['course_ids'][0], [], false)[0]);
        $this->assertSame(403, $this->request('GET', '/__isolated/fee-snapshot?course_id=999999999')[0]);
        $this->assertSame(404, $this->request('POST', '/api/v1/payment-reports/director-record')[0]);
        $user = (array) DB::table('User')->where('id', $allow['user_id'])->first(); unset($user['id']);
        $user['LoginName'] = 'other-fixture-'.Str::uuid(); $otherUser = DB::table('User')->insertGetId($user);
        $otherAuth = (array) DB::table('auth_tokens')->where('token', $this->token)->first(); unset($otherAuth['id']);
        $originalToken = $this->token; $this->token = (string) Str::uuid();
        DB::table('auth_tokens')->insert(array_replace($otherAuth, ['token' => $this->token, 'user_id' => $otherUser]));
        try { $denied = $this->request('GET', '/__isolated/fee-snapshot?course_id='.$allow['course_ids'][0]); }
        finally { $this->token = $originalToken; }
        $this->assertSame(403, $denied[0]); $this->assertSame('token_user_mismatch', $denied[1]['message']);
        foreach ($allow['cases'] as $name => $case) {
            if ($name === 'state_probe') continue;
            $id = $case['course_id']; $path = '/api/v1/student-classes/'.$id.'/agreed-fee-correction';
            $snapshotPath = '/__isolated/fee-snapshot?course_id='.$id;
            [$status, $before] = $this->request('GET', $snapshotPath); $this->assertSame(200, $status);
            $courseBefore = (array) DB::table('StudentClass')->where('ID', $id)->first(); unset($courseBefore['Charge']);
            $body = ['scope' => 'billed', 'original_invoice_id' => $case['invoice_id'], 'new_charge' => $case['new_charge'],
                'reason' => 'Synthetic full-stack fee correction', 'idempotency_key' => (string) Str::uuid()];
            [$status, $preview] = $this->request('POST', $path.'/preview', $body); $this->assertSame(200, $status);
            $this->assertSame($before, $this->request('GET', $snapshotPath)[1]);
            $apply = $body + ['preview_token' => $preview['preview_token']];
            [$status, $result] = $this->request('POST', $path.'/apply', $apply); $this->assertSame(200, $status);
            $after = $this->request('GET', $snapshotPath)[1];
            $this->assertSame($case['new_charge'], $after['charge']);
            $this->assertSame($case['new_charge'], $after['open_unpaid_total']);
            $this->assertSame($before['original_invoice_items'], $after['original_invoice_items']);
            $this->assertSame($case['expect_invoice_total'], $after['original_invoice']['TotalAmount']);
            $this->assertSame('void', $after['original_invoice']['Status']);
            $this->assertSame(1, $after['correction_count']); $this->assertSame(1, $after['audit_agreed_fee_count']);
            $this->assertSame([0, 0, $before['notification_count']], [$after['payment_count'], $after['payment_report_count'], $after['notification_count']]);
            $courseAfter = (array) DB::table('StudentClass')->where('ID', $id)->first(); unset($courseAfter['Charge']);
            $this->assertSame($courseBefore, $courseAfter);
            $this->assertSame([200, $result], $this->request('POST', $path.'/apply', $apply));
            $this->assertSame($after, $this->request('GET', $snapshotPath)[1]);
        }
        $case = $allow['cases']['state_probe']; $id = $case['course_id'];
        $path = '/api/v1/student-classes/'.$id.'/agreed-fee-correction';
        $body = ['scope' => 'billed', 'original_invoice_id' => $case['invoice_id'], 'new_charge' => 500,
            'reason' => 'Synthetic stale preview', 'idempotency_key' => (string) Str::uuid()];
        [$status, $preview] = $this->request('POST', $path.'/preview', $body); $this->assertSame(200, $status);
        $this->assertSame(200, $this->request('POST', '/__isolated/bump-tracked-state', ['course_id' => $id])[0]);
        $before = $this->request('GET', '/__isolated/fee-snapshot?course_id='.$id)[1];
        [$status, $error] = $this->request('POST', $path.'/apply', $body + ['preview_token' => $preview['preview_token']]);
        $this->assertSame(409, $status); $this->assertSame('state_changed', $error['code']);
        $this->assertSame($before, $this->request('GET', '/__isolated/fee-snapshot?course_id='.$id)[1]);
        DB::table('auth_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(401, $this->request('GET', '/__isolated/fee-snapshot?course_id='.$id)[0]);
    }
}
