<?php
/** Operator-run metadata only. Never boot application kernels/providers or print errors/config. */
final class AllTrue4504Metadata
{
    public const ROOT = '/home/admin/backend';
    public const CANDIDATE = '7f9acf5ef8ed5ead47c948da58799b035993720e';
    public const MIGRATION = '2019_12_14_000001_create_personal_access_tokens_table';

    public static function backupEndpointMatches($connection, string $default): bool
    {
        $config = $connection->getConfig();
        return $default === 'mysql' && $connection->getName() === 'mysql' && ($config['host'] ?? null) === '127.0.0.1'
            && (string) ($config['port'] ?? '') === '3306' && ($config['database'] ?? null) === 'AllTrue'
            && empty($config['unix_socket']) && empty($config['url']) && empty($config['read']) && empty($config['write']);
    }

    public static function assertExpectedRevision(string $expected, string $actual): void
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', $expected) || !hash_equals($expected, $actual)) {
            throw new RuntimeException('revision_mismatch');
        }
    }

    public static function redactColumnTypes(array $columns): array
    {
        $types = 'tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|bit|boolean|bool|char|varchar|binary|varbinary|tinytext|text|mediumtext|longtext|tinyblob|blob|mediumblob|longblob|date|time|datetime|timestamp|year|json|geometry|point|linestring|polygon';
        foreach ($columns as &$column) {
            $type = strtolower((string) ($column['COLUMN_TYPE'] ?? ''));
            if (preg_match('/^(enum|set)\\(/D', $type, $match)) {
                $column['COLUMN_TYPE'] = $match[1] . '(literals_redacted)';
            } elseif (!preg_match('/^(?:' . $types . ')(?:\\([0-9]+(?:,[0-9]+)?\\))?(?: unsigned)?(?: zerofill)?$/D', $type)) {
                $column['COLUMN_TYPE'] = 'UNKNOWN';
            }
        }
        return $columns;
    }

    public static function revision(): string
    {
        exec('GIT_OPTIONAL_LOCKS=0 git rev-parse HEAD 2>/dev/null', $lines, $status);
        $sha = trim(implode('', $lines));
        if ($status !== 0 || !preg_match('/^[a-f0-9]{40}$/D', $sha)) {
            throw new RuntimeException('revision_unavailable');
        }
        exec('GIT_OPTIONAL_LOCKS=0 git diff --quiet HEAD -- . 2>/dev/null', $unused, $dirty);
        if ($dirty !== 0) {
            throw new RuntimeException('tracked_backend_dirty');
        }
        return $sha;
    }

    public static function metadata(PDO $pdo, string $prefix, string $migrationTable): array
    {
        foreach ([$prefix . 'personal_access_tokens', $prefix . $migrationTable] as $table) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
                throw new RuntimeException('unsupported_identifier');
            }
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->inTransaction()) {
            throw new RuntimeException('unsupported_connection_state');
        }
        if ($pdo->exec('SET TRANSACTION READ ONLY') === false || !$pdo->beginTransaction()) {
            throw new RuntimeException('read_only_transaction_unavailable');
        }
        try {
            $tables = [$prefix . 'personal_access_tokens', $prefix . $migrationTable];
            $statement = $pdo->prepare('SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?) ORDER BY TABLE_NAME');
            $statement->execute($tables);
            $present = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA, CASE WHEN COLUMN_DEFAULT IS NULL OR COLUMN_DEFAULT = \'NULL\' THEN \'null\' ELSE \'non_null\' END AS DEFAULT_KIND FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
            $statement->execute([$tables[0]]);
            $columns = self::redactColumnTypes($statement->fetchAll(PDO::FETCH_ASSOC));
            $statement = $pdo->prepare('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
            $statement->execute([$tables[0]]);
            $indexes = $statement->fetchAll(PDO::FETCH_ASSOC);
            $ledgerPresent = in_array($tables[1], array_column($present, 'TABLE_NAME'), true);
            $migration = [];
            $ledger = [];
            if ($ledgerPresent) {
                $statement = $pdo->prepare('SELECT migration, batch FROM `' . $tables[1] . '` WHERE migration = ? ORDER BY batch');
                $statement->execute([self::MIGRATION]);
                $migration = $statement->fetchAll(PDO::FETCH_ASSOC);
                // Metadata ledger only, never business/token rows. Sentinel detects truncation.
                $ledger = $pdo->query('SELECT migration, batch FROM `'.$tables[1].'` ORDER BY migration, batch LIMIT 10001')->fetchAll(PDO::FETCH_ASSOC);
            }
            $vars = $pdo->query("SHOW SESSION VARIABLES WHERE Variable_name IN ('lock_wait_timeout','max_statement_time','alter_algorithm','wsrep_on')")->fetchAll(PDO::FETCH_KEY_PAIR);
            return ['tables' => $present, 'columns' => $columns, 'indexes' => $indexes,
                'migration_ledger_present' => $ledgerPresent, 'original_migration_records' => $migration,
                'preflight' => ['server_version' => $pdo->query('SELECT VERSION()')->fetchColumn(),
                    'backup_database_matches' => $pdo->query('SELECT DATABASE()')->fetchColumn() === 'AllTrue',
                    'token_table' => array_values(array_filter($present, static fn ($row) => $row['TABLE_NAME'] === $tables[0])),
                    'session_variables' => $vars, 'ledger_records' => $ledger,
                    'ledger_complete' => $ledgerPresent && count($ledger) < 10001]];
        } finally {
            if (!$pdo->rollBack()) {
                throw new RuntimeException('read_only_transaction_cleanup_failed');
            }
        }
    }

    public static function processes(): array
    {
        $counts = ['fpm' => 0, 'php_other' => 0];
        $fpmBinary = null;
        foreach (glob('/proc/[0-9]*/comm') ?: [] as $path) {
            $comm = trim((string) @file_get_contents($path));
            if (preg_match('/^php-fpm(?:[0-9.]+)?$/D', $comm)) {
                $counts['fpm']++;
                $exe = @readlink(dirname($path) . '/exe');
                if (is_string($exe) && preg_match('~^/[a-zA-Z0-9_./-]+/php-fpm8\.2$~D', $exe) && is_executable($exe)) {
                    $fpmBinary = $exe;
                }
            } elseif (preg_match('/^php(?:[0-9.]+)?$/D', $comm)) {
                $counts['php_other']++;
            }
        }
        $fpmFresh = ['state' => 'UNKNOWN'];
        if ($fpmBinary !== null) {
            // Capture and filter internally. Never emit full phpinfo (it can include environment values).
            exec(escapeshellarg($fpmBinary) . ' -i 2>/dev/null', $info, $status);
            if ($status === 0) {
                $version = null;
                $modules = [];
                foreach ($info as $line) {
                    if (preg_match('/^PHP Version => ([0-9.]+)$/D', $line, $match)) {
                        $version = $match[1];
                    }
                    // Only module section headings; arbitrary environment/config rows never leave memory.
                    if (in_array(strtolower(trim($line)), ['core', 'ctype', 'curl', 'date', 'dom', 'fileinfo', 'filter',
                        'gd', 'hash', 'iconv', 'intl', 'json', 'libxml', 'mbstring', 'openssl', 'pcntl', 'pcre',
                        'pdo', 'pdo_mysql', 'phar', 'session', 'simplexml', 'sodium', 'spl', 'standard',
                        'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'zend opcache', 'zip', 'zlib'], true)) {
                        $modules[] = $line;
                    }
                }
                $fpmFresh = ['state' => $version === null ? 'UNKNOWN' : 'fresh_binary_info_only',
                    'php_version' => $version, 'reported_module_headings' => array_values(array_unique($modules))];
            }
        }
        return ['visible_process_counts' => $counts, 'fpm_fresh_binary_info' => $fpmFresh,
            'loaded_fpm_worker_version_extensions_config_sha' => 'UNKNOWN',
            'scope' => 'process names only; no arguments, environment, memory, or network requests'];
    }

    public static function run(): void
    {
        $stage = 'paths';
        $buffer = ob_get_level();
        ob_start();
        set_error_handler(static function (int $severity): bool {
            if (error_reporting() & $severity) {
                throw new RuntimeException('suppressed_diagnostic');
            }
            return true;
        });
        try {
            if (PHP_SAPI !== 'cli' || realpath(getcwd()) !== self::ROOT) {
                throw new RuntimeException('unsupported_path_or_sapi');
            }
            $stage = 'identity_before_configuration';
            $head = self::revision();
            $expected = (string) getenv('EXPECTED_BACKEND_SHA');
            self::assertExpectedRevision($expected, $head);
            $stage = 'configuration_only';
            require self::ROOT . '/vendor/autoload.php';
            $app = require self::ROOT . '/bootstrap/app.php';
            if (realpath($app->basePath()) !== self::ROOT || realpath($app->environmentPath()) !== self::ROOT
                || realpath($app->configPath()) !== self::ROOT . '/config'
                || $app->getCachedConfigPath() !== self::ROOT . '/bootstrap/cache/config.php'
                || $app->environmentFile() !== '.env') {
                throw new RuntimeException('unapproved_configuration_source');
            }
            if (!$app->configurationIsCached()) {
                $environment = \Illuminate\Support\Env::get('APP_ENV');
                if (is_string($environment) && is_file(self::ROOT . '/.env.' . $environment)) {
                    throw new RuntimeException('alternate_environment_source');
                }
                \Dotenv\Dotenv::create(\Illuminate\Support\Env::getRepository(), self::ROOT, '.env')->safeLoad();
            }
            (new \Illuminate\Foundation\Bootstrap\LoadConfiguration)->bootstrap($app);
            (new \Illuminate\Database\DatabaseServiceProvider($app))->register();
            $connection = $app['db']->connection();
            $migrationTable = $app['config']->get('database.migrations', 'migrations');
            if (is_array($migrationTable)) {
                $migrationTable = $migrationTable['table'] ?? null;
            }
            if (!is_string($migrationTable)) {
                throw new RuntimeException('unsupported_migration_configuration');
            }
            $stage = 'metadata_only_read_only_transaction';
            $schema = self::metadata($connection->getPdo(), $connection->getTablePrefix(), $migrationTable);
            $schema['preflight']['backup_endpoint_matches'] = self::backupEndpointMatches($connection, $app['db']->getDefaultConnection());
            $stage = 'identity_and_versions';
            $manifest = json_decode((string) file_get_contents(self::ROOT . '/public/deployment.json'), true, 512, JSON_THROW_ON_ERROR);
            $manifestSha = $manifest['backend_sha'] ?? '';
            if (!is_string($manifestSha) || !preg_match('/^[a-f0-9]{40}$/D', $manifestSha)) {
                throw new RuntimeException('manifest_identity_unavailable');
            }
            $extensions = get_loaded_extensions();
            sort($extensions);
            $endHead = self::revision();
            self::assertExpectedRevision($expected, $endHead);
            self::assertExpectedRevision($expected, $manifestSha);
            $result = ['case' => 'laravel_4504_metadata', 'read_only' => true,
                'metadata_collected' => true, 'production_acceptance' => 'UNKNOWN',
                'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'candidate_head' => self::CANDIDATE,
                'checkout_sha_start' => $head, 'checkout_sha_end' => $endHead, 'manifest_backend_sha' => $manifestSha,
                'identity_consistent' => hash_equals($head, $endHead) && hash_equals($head, $manifestSha),
                'php_cli_version' => PHP_VERSION, 'php_cli_extensions' => $extensions, 'php_int_size' => PHP_INT_SIZE,
                'laravel_version_fresh_cli' => $app->version(), 'configuration_cached' => $app->configurationIsCached(),
                'config_cache_mtime_utc' => $app->configurationIsCached() ? gmdate('Y-m-d\TH:i:s\Z', filemtime($app->getCachedConfigPath())) : null,
                'process_observation' => self::processes(), 'schema_metadata' => $schema,
                'scope' => 'fresh CLI/config and schema metadata only; no token rows, business data, app kernels, providers boot, cache rebuild, migration, network probe or process restart'];
            $unexpectedOutput = ob_get_contents();
            if ($unexpectedOutput !== '') {
                throw new RuntimeException('unexpected_bootstrap_output');
            }
            while (ob_get_level() > $buffer) {
                ob_end_clean();
            }
            restore_error_handler();
            echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
        } catch (Throwable $error) {
            while (ob_get_level() > $buffer) {
                ob_end_clean();
            }
            restore_error_handler();
            echo json_encode(['metadata_collected' => false, 'read_only' => true, 'stage' => $stage,
                'production_acceptance' => 'UNKNOWN', 'failure_type' => get_class($error)]), PHP_EOL;
            exit(1);
        }
    }
}

AllTrue4504Metadata::run();
