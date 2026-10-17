<?php
/**
 * DRAFT — install as: backend/tests/Support/agreed-fee-isolated-db-guard.php
 * (this file is currently named db-guard.proposed.php under docs/plans/)
 *
 * Call before any schema/seed/serve work. Refuses DATABASE_URL, DB_SOCKET,
 * non-AllTrue_test names, non-loopback hosts, and read-host overrides.
 * After Laravel boot, purge+reconnect so config-cache cannot retarget DSN.
 * Exits 90 on refuse.
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

    // Prefer env (what the stack exports) over possibly cached config.
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
 * After Laravel bootstrap: purge + re-assert effective connection matches env.
 * Call after Kernel::bootstrap() so config:cache cannot silently retarget.
 */
function agreed_fee_isolated_assert_effective_connection(): void
{
    agreed_fee_isolated_assert_db();

    $envHost = (string) getenv('DB_HOST');
    $envName = (string) getenv('DB_DATABASE');

    config([
        'database.default' => 'mysql',
        'database.connections.mysql.host' => $envHost,
        'database.connections.mysql.database' => $envName,
        'database.connections.mysql.username' => (string) (getenv('DB_USERNAME') ?: 'admin'),
        'database.connections.mysql.password' => (string) (getenv('DB_PASSWORD') ?: ''),
        'database.connections.mysql.port' => (int) (getenv('DB_PORT') ?: 3306),
        'database.connections.mysql.unix_socket' => '',
        'database.connections.mysql.url' => null,
    ]);
    Illuminate\Support\Facades\DB::purge('mysql');
    Illuminate\Support\Facades\DB::reconnect('mysql');

    $cfg = config('database.connections.mysql');
    if (($cfg['database'] ?? null) !== 'AllTrue_test') {
        agreed_fee_isolated_refuse('effective database '.($cfg['database'] ?? 'null'));
    }
    if (!in_array((string) ($cfg['host'] ?? ''), ['127.0.0.1', 'localhost', 'agreed-fee-test-mysql'], true)) {
        agreed_fee_isolated_refuse('effective host '.($cfg['host'] ?? 'null'));
    }
    if (!empty($cfg['unix_socket']) || !empty($cfg['url'])) {
        agreed_fee_isolated_refuse('socket/url on effective connection');
    }
}

/** Same APP_KEY as backend/phpunit.xml — process-local only. */
function agreed_fee_isolated_apply_runtime_config(): void
{
    config([
        'app.key' => 'base64:c29tZXJhbmRvbWtleWZvcnRlc3RpbmcxcXdhc2R6eGM=',
        'billing.agreed_fee_correction_enabled' => true,
        'database.default' => 'mysql',
    ]);
}
