<?php
/**
 * Isolated full-stack support: backend/tests/Support/agreed-fee-isolated-db-guard.php
 *
 * Refuse DSN / config-cache overrides / non-synthetic schema /
 * non-loopback. Loaded-config assert and sanitized configuration run before
 * providers bootstrap; DDL remains in the same guarded process.
 */
declare(strict_types=1);

require_once __DIR__.'/alltrueTestDatabase.php';

function agreed_fee_isolated_refuse(string $why): never
{
    fwrite(STDERR, "db-guard: refusing {$why}\n");
    exit(90);
}

function agreed_fee_isolated_assert_db(): void
{
    if (getenv('APP_ENV') !== 'testing') {
        agreed_fee_isolated_refuse('APP_ENV must be testing');
    }
    foreach (['APP_CONFIG_CACHE', 'DATABASE_URL', 'DB_URL', 'DB_SOCKET', 'DB_READ_HOST', 'DB_WRITE_HOST'] as $bad) {
        $v = getenv($bad);
        if (is_string($v) && $v !== '') {
            agreed_fee_isolated_refuse("env {$bad} set");
        }
    }
    $host = (string) (getenv('DB_HOST') ?: '');
    $name = (string) (getenv('DB_DATABASE') ?: '');
    if ($name === '' || $host === '') {
        agreed_fee_isolated_refuse('DB_HOST/DB_DATABASE unset');
    }
    if (!alltrue_is_allowed_test_database($name)) {
        agreed_fee_isolated_refuse("DB_DATABASE={$name}");
    }
    if (!alltrue_is_allowed_test_db_host($host)) {
        agreed_fee_isolated_refuse("DB_HOST={$host}");
    }
}

/**
 * After LoadConfiguration, before providers: refuse an unexpected isolated DSN.
 * Does NOT rewrite a bad target into a good one — exit 90 instead.
 */
function agreed_fee_isolated_assert_loaded_connection_or_refuse(): void
{
    agreed_fee_isolated_assert_db();

    $default = (string) config('database.default');
    if ($default !== 'mysql') {
        agreed_fee_isolated_refuse("database.default={$default}");
    }

    $cfg = config('database.connections.mysql');
    if (!is_array($cfg)) {
        agreed_fee_isolated_refuse('mysql connection missing');
    }
    if (($cfg['driver'] ?? null) !== 'mysql') {
        agreed_fee_isolated_refuse('mysql driver mismatch');
    }
    if (!empty($cfg['url'])) {
        agreed_fee_isolated_refuse('mysql.url set (DATABASE_URL / config redirect)');
    }
    if (!empty($cfg['unix_socket'])) {
        agreed_fee_isolated_refuse('mysql.unix_socket set');
    }
    if (!empty($cfg['options'])) {
        agreed_fee_isolated_refuse('mysql PDO options set');
    }
    // Candidate database.php ships read=>[] / write=>[] when DB_READ_HOST is unset.
    // Empty defaults are OK; only non-empty overrides are redirects.
    $read = $cfg['read'] ?? null;
    $write = $cfg['write'] ?? null;
    if (is_array($read) && $read !== []) {
        agreed_fee_isolated_refuse('mysql read override non-empty');
    }
    if (is_array($write) && $write !== []) {
        agreed_fee_isolated_refuse('mysql write override non-empty');
    }
    if ((!is_array($read) && $read !== null) || (!is_array($write) && $write !== null)) {
        agreed_fee_isolated_refuse('mysql read/write override malformed');
    }
    if (!empty($cfg['read']['host']) || !empty($cfg['write']['host'])) {
        agreed_fee_isolated_refuse('mysql read/write host override');
    }

    $host = (string) ($cfg['host'] ?? '');
    $name = (string) ($cfg['database'] ?? '');
    $envHost = (string) getenv('DB_HOST');
    $envName = (string) getenv('DB_DATABASE');
    if ((int) ($cfg['port'] ?? 3306) !== (int) (getenv('DB_PORT') ?: 3306)) {
        agreed_fee_isolated_refuse('loaded port differs from intended test port');
    }

    if (!alltrue_is_allowed_test_database($name)) {
        agreed_fee_isolated_refuse("loaded database={$name}");
    }
    if (!alltrue_is_allowed_test_db_host($host)) {
        agreed_fee_isolated_refuse("loaded host={$host}");
    }
    if ($name !== $envName || $host !== $envHost) {
        // Loaded config drifted from the process env the operator intended.
        agreed_fee_isolated_refuse("loaded {$host}/{$name} != env {$envHost}/{$envName}");
    }
}

/** Install only the shared allowlisted TCP keys, before any provider can connect. */
function agreed_fee_isolated_harden_connection_after_pass(): void
{
    $mysql = config('database.connections.mysql');
    if (!is_array($mysql)) {
        agreed_fee_isolated_refuse('mysql connection missing at harden');
    }
    try {
        $mysql = alltrue_race_database_config($mysql);
    } catch (InvalidArgumentException $e) {
        agreed_fee_isolated_refuse('invalid synthetic TCP configuration');
    }
    config([
        'database.default' => 'mysql',
        'database.connections.mysql' => $mysql,
    ]);
}

/** @deprecated use assert_loaded + harden; kept for router boot path naming */
function agreed_fee_isolated_assert_effective_connection(): void
{
    agreed_fee_isolated_assert_loaded_connection_or_refuse();
    agreed_fee_isolated_harden_connection_after_pass();
}

/**
 * Process-local runtime for isolated harness only.
 * APP_KEY identical to backend/phpunit.xml; flag ON only in this process.
 * Precise loopback CORS origins — allowlist, not lifenet blacklist.
 */
function agreed_fee_isolated_apply_runtime_config(): void
{
    config([
        'app.key' => 'base64:c29tZXJhbmRvbWtleWZvcnRlc3RpbmcxcXdhc2R6eGM=',
        'app.env' => 'testing',
        'app.url' => 'http://127.0.0.1:8000',
        'billing.agreed_fee_correction_enabled' => true,
        'database.default' => 'mysql',
        'cors.allowed_origins' => [
            'http://127.0.0.1:5179',
            'http://localhost:5179',
            'http://127.0.0.1:8000',
            'http://localhost:8000',
        ],
    ]);
}

/** Same before-provider pattern as alltrue_boot_race_worker; return the app for Migrator/HTTP. */
function agreed_fee_isolated_boot_application(string $backendRoot): \Illuminate\Foundation\Application
{
    agreed_fee_isolated_assert_db();
    if (is_file($backendRoot.'/bootstrap/cache/config.php')) {
        agreed_fee_isolated_refuse('bootstrap/cache/config.php present');
    }
    require_once $backendRoot.'/vendor/autoload.php';
    $app = require $backendRoot.'/bootstrap/app.php';
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, static function () {
        agreed_fee_isolated_assert_loaded_connection_or_refuse();
        agreed_fee_isolated_harden_connection_after_pass();
        agreed_fee_isolated_apply_runtime_config();
    });
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    return $app;
}
