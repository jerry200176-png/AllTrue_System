<?php
/**
 * DRAFT — install as: backend/tests/Support/agreed-fee-isolated-db-guard.php
 *
 * Refuse DATABASE_URL / DB_SOCKET / read|write host / non-AllTrue_test /
 * non-loopback. Loaded-config assert happens AFTER Laravel bootstrap and
 * BEFORE any DDL (see agreed-fee-isolated-migrate.php).
 */
declare(strict_types=1);

function agreed_fee_isolated_refuse(string $why): never
{
    fwrite(STDERR, "db-guard: refusing {$why}\n");
    exit(90);
}

function agreed_fee_isolated_assert_db(): void
{
    foreach (['DATABASE_URL', 'DB_URL', 'DB_SOCKET', 'DB_READ_HOST', 'DB_WRITE_HOST'] as $bad) {
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
    if ($name !== 'AllTrue_test') {
        agreed_fee_isolated_refuse("DB_DATABASE={$name}");
    }
    if (!in_array($host, ['127.0.0.1', 'localhost', 'agreed-fee-test-mysql'], true)) {
        agreed_fee_isolated_refuse("DB_HOST={$host}");
    }
}

/**
 * After bootstrap: refuse whatever Laravel loaded if it is not the isolated DSN.
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
    if (!empty($cfg['url'])) {
        agreed_fee_isolated_refuse('mysql.url set (DATABASE_URL / config redirect)');
    }
    if (!empty($cfg['unix_socket'])) {
        agreed_fee_isolated_refuse('mysql.unix_socket set');
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

    if ($name !== 'AllTrue_test') {
        agreed_fee_isolated_refuse("loaded database={$name}");
    }
    if (!in_array($host, ['127.0.0.1', 'localhost', 'agreed-fee-test-mysql'], true)) {
        agreed_fee_isolated_refuse("loaded host={$host}");
    }
    if ($name !== $envName || $host !== $envHost) {
        // Loaded config drifted from the process env the operator intended.
        agreed_fee_isolated_refuse("loaded {$host}/{$name} != env {$envHost}/{$envName}");
    }
}

/** After a successful loaded-assert: clear residual socket/url keys and reconnect. */
function agreed_fee_isolated_harden_connection_after_pass(): void
{
    $mysql = config('database.connections.mysql');
    if (!is_array($mysql)) {
        agreed_fee_isolated_refuse('mysql connection missing at harden');
    }
    $mysql['unix_socket'] = '';
    $mysql['url'] = null;
    // Drop empty read/write split stubs so the migrator never treats them as a redirect surface.
    if (($mysql['read'] ?? null) === []) {
        unset($mysql['read']);
    }
    if (($mysql['write'] ?? null) === []) {
        unset($mysql['write']);
    }
    config([
        'database.default' => 'mysql',
        'database.connections.mysql' => $mysql,
    ]);
    Illuminate\Support\Facades\DB::purge('mysql');
    Illuminate\Support\Facades\DB::reconnect('mysql');
    agreed_fee_isolated_assert_loaded_connection_or_refuse();
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
