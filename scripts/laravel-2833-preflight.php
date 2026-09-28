<?php

/** Fixed, read-only metadata probe. No kernel/provider boot or business service calls. */
final class Laravel2833Preflight
{
    public static function assertRevision(string $expected, string $actual): void
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', $expected) || !hash_equals($expected, $actual)) {
            throw new RuntimeException('revision_mismatch');
        }
    }

    private static function assertCleanBackend(): void
    {
        exec('git diff --quiet HEAD -- . 2>/dev/null', $unused, $dirty);
        if ($dirty !== 0) {
            throw new RuntimeException('tracked_backend_dirty');
        }
    }

    public static function assertApplicationPaths($app, string $root): void
    {
        $root = realpath($root);
        if ($root === false || realpath($app->basePath()) !== $root || realpath($app->environmentPath()) !== $root
            || realpath($app->configPath()) !== realpath($root . '/config')
            || $app->getCachedConfigPath() !== $root . '/bootstrap/cache/config.php') {
            throw new RuntimeException('unapproved_application_paths');
        }
    }

    public static function classify($value): array
    {
        if ($value === null || in_array(strtolower((string) $value), ['null', '(null)'], true)) {
            return ['kind' => 'null', 'enabled' => null];
        }
        if (in_array(strtolower((string) $value), ['empty', '(empty)'], true)) {
            $value = '';
        }
        if (in_array(strtolower((string) $value), ['(true)', '(false)'], true)) {
            $value = strtolower((string) $value) === '(true)';
        }
        $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return ['kind' => $boolean === null ? 'invalid' : 'boolean', 'enabled' => $boolean ?? false];
    }

    public static function readFlagFile(string $path, array $flags): array
    {
        $stream = @fopen($path, 'r');
        if ($stream === false) {
            throw new RuntimeException('flag_source_unreadable');
        }
        $pattern = '/^\s*(?:export\s+)?(' . implode('|', array_map(fn ($key) => preg_quote($key, '/'), $flags)) . ')(?:_CAMPUS_[0-9]+)?\s*=/';
        $selected = '';
        try {
            while (($line = fgets($stream)) !== false) {
                if (preg_match($pattern, $line)) {
                    if (str_contains($line, '${')) {
                        throw new RuntimeException('flag_expression_unsupported');
                    }
                    $selected .= $line;
                }
                if (strlen($selected) > 65536) {
                    throw new RuntimeException('flag_scope_too_large');
                }
            }
            if (!feof($stream)) {
                throw new RuntimeException('flag_source_incomplete');
            }
        } finally {
            fclose($stream);
        }
        return array_map([self::class, 'classify'], \Dotenv\Dotenv::parse($selected));
    }

    public static function readOnly(PDO $pdo, Closure $reader)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->inTransaction()) {
            throw new RuntimeException('unsupported_transaction_state');
        }
        if ($pdo->exec('SET TRANSACTION READ ONLY') === false || !$pdo->beginTransaction()) {
            throw new RuntimeException('read_only_transaction_unavailable');
        }
        try {
            return $reader();
        } finally {
            if (!$pdo->rollBack()) {
                throw new RuntimeException('read_only_transaction_cleanup_failed');
            }
        }
    }

    public static function collect($app, array $request, string $flagPath): array
    {
        $flags = $request['flags'];
        $fileValues = self::readFlagFile($flagPath, $flags);
        $names = array_unique(array_merge($flags, array_keys($fileValues), array_keys($app['config']->get('feature_flags.values', [])), array_keys($_SERVER), array_keys($_ENV), array_keys(getenv())));
        $report = [];
        foreach ($flags as $flag) {
            $key = strtolower(substr($flag, strlen('FEATURE_')));
            foreach ($names as $name) {
                if ($name !== $flag && !preg_match('/^' . preg_quote($flag, '/') . '_CAMPUS_([0-9]+)$/D', $name, $match)) {
                    continue;
                }
                $campus = $name === $flag ? null : (int) $match[1];
                $adapter = \Illuminate\Support\Env::getRepository()->get($name);
                $report[$name] = [
                    'file' => $fileValues[$name] ?? ['kind' => 'missing', 'enabled' => false],
                    'adapter' => $adapter === null ? ['kind' => 'missing', 'enabled' => false] : self::classify(\Illuminate\Support\Env::get($name)),
                    'fresh_current_revision_enabled' => \App\Helpers\FeatureFlag::enabled($key, $campus),
                ];
            }
        }
        ksort($report);
        $connection = $app['db']->connection();
        $pdo = $connection->getPdo();
        $table = $connection->getTablePrefix() . $app['config']->get('database.migrations', 'migrations');
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
            throw new RuntimeException('unsupported_migration_table');
        }
        $ran = self::readOnly($pdo, fn () => $pdo->query('SELECT migration FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN));
        $files = [];
        foreach ($request['migration_directories'] as $directory) {
            $matches = glob($app->basePath($directory) . '/*_*.php');
            if ($matches === false || !is_dir($app->basePath($directory))) {
                throw new RuntimeException('migration_source_unavailable');
            }
            foreach ($matches as $file) {
                $files[] = basename($file, '.php');
            }
        }
        $pending = array_values(array_diff(array_unique($files), $ran));
        sort($pending);
        return ['ok' => true, 'case' => 'laravel_2833_preflight', 'read_only' => true,
            'prod_head' => $request['expected_backend_sha'], 'candidate_head' => $request['candidate_head'],
            'php_version' => PHP_VERSION, 'php_sapi' => PHP_SAPI, 'extensions' => get_loaded_extensions(), 'laravel_version' => $app->version(),
            'configuration_cached' => $app->configurationIsCached(), 'flags' => $report,
            'migration_directories' => $request['migration_directories'], 'pending_migrations' => $pending,
            'scope' => 'fresh configuration boot only; no candidate runtime, existing worker or user-path observation'];
    }

    public static function run(array $request): void
    {
        $stage = 'revision';
        try {
            self::assertRevision($request['expected_backend_sha'], trim((string) shell_exec('git rev-parse HEAD 2>/dev/null')));
            self::assertCleanBackend();
            $stage = 'configuration_boot';
            require getcwd() . '/vendor/autoload.php';
            $app = require getcwd() . '/bootstrap/app.php';
            self::assertApplicationPaths($app, getcwd());
            if ($app->environmentFile() !== '.env') {
                throw new RuntimeException('unapproved_environment_source');
            }
            if (!$app->configurationIsCached()) {
                $environment = \Illuminate\Support\Env::get('APP_ENV');
                if (is_string($environment) && is_file($app->environmentPath() . '/.env.' . $environment)) {
                    throw new RuntimeException('unapproved_environment_source');
                }
                // Use the maintained loader directly: Laravel's console bootstrap can print dotenv errors.
                \Dotenv\Dotenv::create(\Illuminate\Support\Env::getRepository(), $app->environmentPath(), $app->environmentFile())->safeLoad();
            }
            self::assertApplicationPaths($app, getcwd());
            (new \Illuminate\Foundation\Bootstrap\LoadConfiguration)->bootstrap($app);
            // Register database bindings only; never boot console/HTTP kernels or providers.
            (new \Illuminate\Database\DatabaseServiceProvider($app))->register();
            $stage = 'metadata_collection';
            $result = self::collect($app, $request, getcwd() . '/.env');
            self::assertRevision($request['expected_backend_sha'], trim((string) shell_exec('git rev-parse HEAD 2>/dev/null')));
            self::assertCleanBackend();
            echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
        } catch (Throwable $error) {
            // Never export exception messages: dotenv/SQL errors may contain secrets or data.
            echo json_encode(['ok' => false, 'case' => 'laravel_2833_preflight', 'stage' => $stage,
                'failure_type' => get_class($error), 'read_only' => true]), PHP_EOL;
            exit(1);
        }
    }
}
