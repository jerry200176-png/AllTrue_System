<?php

/**
 * PHPUnit bootstrap safety checks.
 *
 * RefreshDatabase can drop every table in the configured schema. Refuse to
 * bootstrap Laravel unless the effective database is in the dedicated test
 * namespace. The plain name remains supported for CI and serial legacy runs;
 * parallel local runs should use scripts/test-backend-isolated.sh.
 */
(function (): void {
    $database = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? 'AllTrue_test');

    if (!is_string($database) || !preg_match('/\AAllTrue_test(?:_[A-Za-z0-9_]+)?\z/', $database)) {
        fwrite(
            STDERR,
            "Refusing to run PHPUnit against unsafe DB_DATABASE. " .
            "Expected AllTrue_test or AllTrue_test_<safe suffix>.\n"
        );
        exit(1);
    }
})();

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');

// A cached production configuration can bypass test environment settings.
$configCache = __DIR__ . '/../bootstrap/cache/config.php';
if (file_exists($configCache)) {
    @unlink($configCache);
}

require __DIR__ . '/../vendor/autoload.php';
