<?php
/** Fixed #4508 entry; no arbitrary SQL/path/budget inputs and no privilege changes. */
namespace AllTrue4508;

const MIGRATION = '2026_10_26_000001_add_expires_at_to_personal_access_tokens';
const SOURCE_HASH = 'e9cd5c466475acb346adbcadf640074787d7d2488263098382a332eb95ec7b47';
const MDL_SECONDS = 5;
const STATEMENT_SECONDS = 120;

function variables(\PDO $pdo): array
{
    return $pdo->query("SHOW SESSION VARIABLES WHERE Variable_name IN ('lock_wait_timeout','max_statement_time','alter_algorithm','wsrep_on')")
        ->fetchAll(\PDO::FETCH_KEY_PAIR);
}

function profile(\PDO $pdo, string $prefix): array
{
    if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->inTransaction()
        || !preg_match('/^[a-zA-Z0-9_]*$/D', $prefix)) {
        throw new \RuntimeException('connection_unknown');
    }
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $statement = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $statement->execute([$prefix.'personal_access_tokens']);
    $engine = $statement->fetchColumn();
    $vars = variables($pdo);
    // MySQL max_execution_time limits SELECTs, not ALTER. Never substitute it.
    if (!preg_match('/^10\.11\.[0-9]+.*MariaDB/i', $version) || $engine !== 'InnoDB'
        || array_diff(['lock_wait_timeout', 'max_statement_time', 'alter_algorithm', 'wsrep_on'], array_keys($vars))
        || strtoupper((string) $vars['wsrep_on']) !== 'OFF') {
        throw new \RuntimeException('ddl_profile_unverified');
    }
    return $vars;
}

function pending($app): array
{
    $repository = $app['migration.repository'];
    if (!$repository->repositoryExists()) {
        throw new \RuntimeException('migration_ledger_missing');
    }
    $ran = $repository->getRan();
    if (count($ran) !== count(array_unique($ran))) {
        throw new \RuntimeException('ambiguous_ledger');
    }
    $migrator = $app['migrator'];
    $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [$app->databasePath('migrations')]));
    $names = array_values(array_diff(array_keys($files), $ran));
    sort($names);
    return $names;
}

function inspect($app): array
{
    $db = $app['db']->connection();
    $config = $db->getConfig();
    // Match the canonical mysqldump endpoint, not merely a same-named database.
    // Split/URL/socket configurations and ::read aliases are outside this profile.
    if ($db->getName() !== 'mysql' || ($config['host'] ?? null) !== '127.0.0.1'
        || (string) ($config['port'] ?? '') !== '3306' || ($config['database'] ?? null) !== 'AllTrue'
        || !empty($config['unix_socket']) || !empty($config['url']) || !empty($config['read']) || !empty($config['write'])) {
        throw new \RuntimeException('backup_endpoint_unverified');
    }
    $db->useWriteConnectionWhenReading();
    $pdo = $db->getPdo();
    $path = $app->databasePath('migrations/'.MIGRATION.'.php');
    if (!is_file($path) || !hash_equals(SOURCE_HASH, hash_file('sha256', $path))) {
        throw new \RuntimeException('migration_source_mismatch');
    }
    if (pending($app) !== [MIGRATION]) {
        throw new \RuntimeException('pending_scope_mismatch');
    }
    // The existing canonical backup hardcodes this DB. Do not alter another DB.
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'AllTrue') {
        throw new \RuntimeException('backup_database_mismatch');
    }
    profile($pdo, $db->getTablePrefix());
    return [$db, $pdo];
}

function configure($db, \PDO $pdo): void
{
    $pdo->exec('SET SESSION lock_wait_timeout = '.MDL_SECONDS);
    $pdo->exec('SET SESSION max_statement_time = '.STATEMENT_SECONDS);
    $pdo->exec("SET SESSION alter_algorithm = 'INPLACE'");
    $vars = variables($pdo);
    if ((int) ($vars['lock_wait_timeout'] ?? 0) !== MDL_SECONDS
        || (float) ($vars['max_statement_time'] ?? 0) !== (float) STATEMENT_SECONDS
        || strtoupper((string) ($vars['alter_algorithm'] ?? '')) !== 'INPLACE') {
        throw new \RuntimeException('session_bounds_unverified');
    }
    // A lost PDO must not reconnect and silently lose its per-session limits.
    $db->setReconnector(static function () { throw new \RuntimeException('bounded_connection_lost'); });
}

function complete($app, \PDO $pdo, string $prefix): bool
{
    $ran = $app['migration.repository']->getRan();
    if (count(array_keys($ran, MIGRATION, true)) !== 1 || pending($app) !== []) {
        return false;
    }
    $statement = $pdo->prepare("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'expires_at'");
    $statement->execute([$prefix.'personal_access_tokens']);
    $column = $statement->fetch(\PDO::FETCH_ASSOC);
    if (!$column || !preg_match('/^timestamp(?:\([0-9]+\))?$/iD', $column['COLUMN_TYPE']) || $column['IS_NULLABLE'] !== 'YES') {
        return false;
    }
    $statement = $pdo->prepare('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SUB_PART, INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
    $statement->execute([$prefix.'personal_access_tokens']);
    $indexes = [];
    foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $indexes[$row['INDEX_NAME']][] = [$row['COLUMN_NAME'], (int) $row['NON_UNIQUE'], $row['SUB_PART'], $row['INDEX_TYPE']];
    }
    return in_array([['expires_at', 1, null, 'BTREE']], $indexes, true);
}

function run($app, callable $apply, bool $check): int
{
    $attempted = false;
    $buffer = ob_get_level();
    ob_start();
    try {
        [$db, $pdo] = inspect($app);
        if ($check) {
            $message = '4508_pre_ddl_guard=pass'; $code = 0;
        } else {
            configure($db, $pdo);
            $attempted = true;
            if ($apply() !== 0 || !complete($app, $pdo, $db->getTablePrefix())) {
                throw new \RuntimeException('ddl_or_readback_incomplete');
            }
            $message = '4508_migration=complete_primary_schema_and_ledger_verified'; $code = 0;
        }
    } catch (\Throwable $error) {
        // Never print PDO/config/error text. 75 means possible DDL: no automatic rollback.
        $message = $attempted ? '4508_migration=HOLD_possible_partial_DDL' : '4508_pre_ddl_guard=HOLD';
        $code = $attempted ? 75 : 78;
    } finally {
        while (ob_get_level() > $buffer) { ob_end_clean(); }
    }
    echo $message, "\n";
    return $code;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $expected = (string) getenv('EXPECTED_BACKEND_SHA');
        if (PHP_SAPI !== 'cli' || realpath(getcwd()) !== '/home/admin/backend'
            || !preg_match('/^[a-f0-9]{40}$/D', $expected) || !in_array($argv, [[$argv[0]], [$argv[0], '--check']], true)) {
            throw new \RuntimeException('unapproved_entry');
        }
        exec('GIT_OPTIONAL_LOCKS=0 git rev-parse HEAD 2>/dev/null', $lines, $status);
        if ($status !== 0 || !hash_equals($expected, trim(implode('', $lines)))) {
            throw new \RuntimeException('revision_mismatch');
        }
        require '/home/admin/backend/vendor/autoload.php';
        $app = require '/home/admin/backend/bootstrap/app.php';
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();
        // Direct Migrator lets our catch own failures; Kernel::handle reports/renders
        // raw exceptions before returning. No console rendering or logger dispatch.
        $migrator = $app['migrator'];
        $migrator->setOutput(new \Symfony\Component\Console\Output\NullOutput);
        exit(run($app, static function () use ($app, $migrator) {
            $migrator->run([$app->databasePath('migrations/'.MIGRATION.'.php')], ['pretend' => false, 'step' => false]);
            return 0;
        }, isset($argv[1])));
    } catch (\Throwable $error) {
        echo "4508_pre_ddl_guard=HOLD\n";
        exit(78);
    }
}
