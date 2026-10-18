<?php
/**
 * DRAFT — install as: backend/tests/Support/agreed-fee-isolated-migrate.php
 *
 * Same Laravel process: inspect loaded DSN → sanitize before providers → bootstrap
 * → run migrator. Plain `php artisan migrate` is forbidden
 * for this harness (post-hoc PHP guards cannot undo a wrong DDL target).
 *
 *   cd backend && php tests/Support/agreed-fee-isolated-migrate.php
 */
declare(strict_types=1);

require __DIR__.'/agreed-fee-isolated-db-guard.php';

// Process-env preflight before bootstrap (no DDL yet).
agreed_fee_isolated_assert_db();

$backendRoot = dirname(__DIR__, 2);

// Refuse pre-baked config cache (silent production DSN redirect risk).
$configCache = $backendRoot.'/bootstrap/cache/config.php';
if (is_file($configCache)) {
    agreed_fee_isolated_refuse('bootstrap/cache/config.php present — clear before isolated migrate');
}

$app = agreed_fee_isolated_boot_application($backendRoot);

/** @var Illuminate\Database\Migrations\Migrator $migrator */
$migrator = $app->make('migrator');
$migrator->setConnection('mysql');
$paths = [$backendRoot.'/database/migrations'];
if (!$migrator->repositoryExists()) {
    $migrator->getRepository()->createRepository();
}
$migrator->run($paths, ['pretend' => false]);
fwrite(STDOUT, "migrate: OK AllTrue_test (guarded same-process)\n");
