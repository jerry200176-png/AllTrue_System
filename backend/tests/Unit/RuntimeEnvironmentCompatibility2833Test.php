<?php

namespace Tests\Unit;

use Dotenv\Repository\Adapter\AdapterInterface;
use Illuminate\Config\Repository;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use PhpOption\Option;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** #2833: existing maintenance approval is live process state, never cached config. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class RuntimeEnvironmentCompatibility2833Test extends TestCase
{
    public function test_existing_command_gates_preserve_legacy_adapter_precedence_and_normalization(): void
    {
        $previous = Container::getInstance();
        $app = new Application(sys_get_temp_dir());
        $app->instance('env', 'production');
        $app->instance('config', new Repository(['app' => ['env' => 'production']]));
        $gates = [
            'GenerateForwardSessions' => 'assertProductionAllowed',
            'BackfillScheduleOccurrenceIdentityCommand' => 'productionWriteAllowed',
            'RepairAttendanceRootFix826' => 'productionAllowed',
            'RepairTransferSessionEntitlement' => 'productionWriteAllowed',
            'RepairDuplicateSessionSlots' => 'assertProductionAllowed',
            'RepairChargeDisplay1734' => 'prodOk',
            'CleanupClassSessionIntraDuplicates' => 'assertProductionAllowed',
            'RepairTyphoonAttendance1903' => 'prodOk',
            'RepairRenewalOverlap234' => 'prodOk',
            'RepairUnattendedSession29212' => 'productionAllowed',
            'GuardiansCutoverAuditCommand' => 'assertProductionAllowed',
            'RepairSupersedeRenewalSession' => 'prodOk',
            'RepairMergeRenewalLearningRecord' => 'prodOk',
            'RepairLeaveCascadeSlotTimes' => 'assertProductionAllowed',
            'RepairFounderStudent9Attendance' => 'productionAllowed',
        ];
        try {
            foreach ([null, '0', '1', 'true', 'false', 'null', '"1"', 'invalid'] as $process) {
                foreach ([null, '0', '1', 'true', 'false', 'null', '"1"', 'invalid'] as $environment) {
                    foreach ([null, '0', '1', 'true', 'false', 'null', '"1"', 'invalid'] as $server) {
                        $this->seed('ALLOW_PROD_REPAIR', $process, $environment, $server);
                        $this->seed('I_APPROVE_TD076_OCCURRENCE_BACKFILL', '1', null, null);
                        $legacy = env('ALLOW_PROD_REPAIR');
                        self::assertSame($legacy, Env::get('ALLOW_PROD_REPAIR'));
                        foreach ($gates as $name => $method) {
                            $class = 'App\\Console\\Commands\\' . $name;
                            $command = new $class();
                            $command->setLaravel($app);
                            $input = new ArrayInput(['--force' => true], $command->getDefinition());
                            $command->setInput($input);
                            $command->setOutput(new OutputStyle($input, new BufferedOutput()));
                            $expected = in_array($name, ['GuardiansCutoverAuditCommand', 'RepairLeaveCascadeSlotTimes'], true)
                                ? (string) $legacy === '1' : $legacy === '1';
                            self::assertSame($expected, (new \ReflectionMethod($command, $method))->invoke($command), $name);
                        }
                    }
                }
            }
            $this->seed('ALLOW_PROD_REPAIR', '1', null, null);
            $command = new \App\Console\Commands\BackfillScheduleOccurrenceIdentityCommand();
            $command->setLaravel($app);
            $input = new ArrayInput(['--force' => true], $command->getDefinition());
            $command->setInput($input);
            $command->setOutput(new OutputStyle($input, new BufferedOutput()));
            $this->seed('I_APPROVE_TD076_OCCURRENCE_BACKFILL', null, null, null);
            self::assertFalse((new \ReflectionMethod($command, 'productionWriteAllowed'))->invoke($command));
            $command = new \App\Console\Commands\GenerateForwardSessions();
            $command->setLaravel($app);
            $input = new ArrayInput(['--scheduled' => true], $command->getDefinition());
            $command->setInput($input);
            $command->setOutput(new OutputStyle($input, new BufferedOutput()));
            $this->seed('ALLOW_PROD_REPAIR', null, null, null);
            self::assertTrue((new \ReflectionMethod($command, 'assertProductionAllowed'))->invoke($command));
            $input = new ArrayInput([], $command->getDefinition());
            $command->setInput($input);
            self::assertFalse((new \ReflectionMethod($command, 'assertProductionAllowed'))->invoke($command));
        } finally {
            $this->seed('ALLOW_PROD_REPAIR', null, null, null);
            $this->seed('I_APPROVE_TD076_OCCURRENCE_BACKFILL', null, null, null);
            Container::setInstance($previous);
        }
    }

    public function test_custom_adapter_and_live_values_retain_existing_laravel_semantics(): void
    {
        $this->seed('ALLOW_PROD_REPAIR', null, null, null);
        Env::extend(fn () => new class implements AdapterInterface {
            public static function create(): Option { return Option::fromValue(new self()); }
            public function write(string $name, string $value): bool { return false; }
            public function delete(string $name): bool { return false; }
            public function read(string $name): Option
            {
                return Option::fromValue($name === 'ALLOW_PROD_REPAIR' ? '1' : null);
            }
        });
        self::assertSame('1', env('ALLOW_PROD_REPAIR'));
        self::assertSame('1', Env::get('ALLOW_PROD_REPAIR'));
        $_SERVER['ALLOW_PROD_REPAIR'] = '0';
        self::assertSame('0', Env::get('ALLOW_PROD_REPAIR'));
        $_SERVER['ALLOW_PROD_REPAIR'] = '1';
        self::assertSame('1', Env::get('ALLOW_PROD_REPAIR'));
    }

    public function test_actual_cold_cache_keeps_existing_maintenance_approval_live_and_out_of_config(): void
    {
        $previous = Container::getInstance();
        $root = sys_get_temp_dir() . '/runtime-cache-2833-' . bin2hex(random_bytes(8));
        mkdir($root . '/bootstrap/cache', 0700, true);
        mkdir($root . '/config', 0700);
        file_put_contents($root . '/config/app.php', "<?php return ['env'=>'production','timezone'=>'UTC'];");
        file_put_contents($root . '/runtime-flags.fixture', "ALLOW_PROD_REPAIR=1\nI_APPROVE_TD076_OCCURRENCE_BACKFILL=1\n");
        try {
            $this->seed('ALLOW_PROD_REPAIR', null, null, null);
            $this->seed('I_APPROVE_TD076_OCCURRENCE_BACKFILL', null, null, null);
            Env::enablePutenv();
            $app = new Application($root);
            $app->loadEnvironmentFrom('runtime-flags.fixture');
            (new \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables())->bootstrap($app);
            (new \Illuminate\Foundation\Bootstrap\LoadConfiguration())->bootstrap($app);
            self::assertFalse($app['config_loaded_from_cache']);
            self::assertSame('1', Env::get('ALLOW_PROD_REPAIR'));
            $config = $app['config']->all();
            self::assertArrayNotHasKey('ALLOW_PROD_REPAIR', $config);
            self::assertArrayNotHasKey('I_APPROVE_TD076_OCCURRENCE_BACKFILL', $config);
            file_put_contents($root . '/bootstrap/cache/config.php', '<?php return ' . var_export($config, true) . ';');
            $this->seed('ALLOW_PROD_REPAIR', null, null, null);
            $this->seed('I_APPROVE_TD076_OCCURRENCE_BACKFILL', null, null, null);
            Env::enablePutenv();
            $app = new Application($root);
            $app->loadEnvironmentFrom('runtime-flags.fixture');
            (new \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables())->bootstrap($app);
            (new \Illuminate\Foundation\Bootstrap\LoadConfiguration())->bootstrap($app);
            self::assertTrue($app['config_loaded_from_cache']);
            self::assertNull(Env::get('ALLOW_PROD_REPAIR')); // Cache never loads the fixture dotenv.
            $command = new \App\Console\Commands\GenerateForwardSessions();
            $command->setLaravel($app);
            $input = new ArrayInput(['--force' => true], $command->getDefinition());
            $command->setInput($input);
            $command->setOutput(new OutputStyle($input, new BufferedOutput()));
            $guard = new \ReflectionMethod($command, 'assertProductionAllowed');
            self::assertFalse($guard->invoke($command));
            $this->seed('ALLOW_PROD_REPAIR', '1', null, null);
            self::assertTrue($guard->invoke($command));
            $this->seed('ALLOW_PROD_REPAIR', '0', null, null);
            self::assertFalse($guard->invoke($command));
            self::assertTrue($app['config_loaded_from_cache']);
        } finally {
            $this->seed('ALLOW_PROD_REPAIR', null, null, null);
            $this->seed('I_APPROVE_TD076_OCCURRENCE_BACKFILL', null, null, null);
            Container::setInstance($previous);
            unlink($root . '/bootstrap/cache/config.php');
            unlink($root . '/config/app.php');
            unlink($root . '/runtime-flags.fixture');
            rmdir($root . '/bootstrap/cache');
            rmdir($root . '/bootstrap');
            rmdir($root . '/config');
            rmdir($root);
        }
    }

    private function seed(string $name, ?string $process, ?string $environment, ?string $server): void
    {
        $process === null ? putenv($name) : putenv($name . '=' . $process);
        unset($_ENV[$name], $_SERVER[$name]);
        if ($environment !== null) $_ENV[$name] = $environment;
        if ($server !== null) $_SERVER[$name] = $server;
    }
}
