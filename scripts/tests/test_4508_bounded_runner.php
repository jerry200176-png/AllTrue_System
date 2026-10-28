<?php
/** Synthetic PDO/application; no database or production configuration is loaded. */
require __DIR__.'/../production-deploy/migrate-4508-bounded.php';

class BoundaryStatement extends PDOStatement
{
    public function __construct(private mixed $value) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchColumn(int $column = 0): mixed { return $this->value; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->value; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $orientation = PDO::FETCH_ORI_NEXT, int $offset = 0): mixed { return $this->value; }
}
class BoundaryPdo extends PDO
{
    public string $version = '10.11.19-MariaDB';
    public array $vars = ['lock_wait_timeout' => '86400', 'max_statement_time' => '0', 'alter_algorithm' => 'DEFAULT', 'wsrep_on' => 'OFF'];
    public array $sets = [];
    public bool $ignoreSets = false;
    public bool $nullable = true;
    public function __construct() {}
    public function getAttribute(int $attribute): mixed { return 'mysql'; }
    public function inTransaction(): bool { return false; }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        return new BoundaryStatement(str_contains($query, 'VARIABLES') ? $this->vars : (str_contains($query, 'VERSION') ? $this->version : 'AllTrue'));
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new BoundaryStatement(str_contains($query, 'SELECT ENGINE') ? 'InnoDB'
            : (str_contains($query, 'COLUMN_TYPE') ? ['COLUMN_TYPE' => 'timestamp', 'IS_NULLABLE' => $this->nullable ? 'YES' : 'NO']
            : [['INDEX_NAME' => 'expiry', 'COLUMN_NAME' => 'expires_at', 'NON_UNIQUE' => 1, 'SUB_PART' => null, 'INDEX_TYPE' => 'BTREE']]));
    }
    public function exec(string $statement): int|false
    {
        $this->sets[] = $statement;
        if (!$this->ignoreSets && preg_match('/SET SESSION (\w+) = (.+)$/', $statement, $m)) {
            $this->vars[$m[1]] = trim($m[2], "'");
        }
        return 0;
    }
}
class BoundaryDb
{
    public bool $writeReads = false;
    public mixed $reconnector = null;
    public string $name = 'mysql';
    public string $defaultName = 'mysql';
    public array $config = ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'AllTrue'];
    public function __construct(public BoundaryPdo $pdo) {}
    public function connection(): self { return $this; }
    public function useWriteConnectionWhenReading(): void { $this->writeReads = true; }
    public function getPdo(): PDO { return $this->pdo; }
    public function getTablePrefix(): string { return ''; }
    public function getName(): string { return $this->name; }
    public function getDefaultConnection(): string { return $this->defaultName; }
    public function getConfig(): array { return $this->config; }
    public function setReconnector(callable $callback): void { $this->reconnector = $callback; }
}
class BoundaryRepository
{
    public array $ran = [];
    public function repositoryExists(): bool { return true; }
    public function getRan(): array { return $this->ran; }
}
class BoundaryMigrator
{
    public array $files = [\AllTrue4508\MIGRATION => 'fixture'];
    public function paths(): array { return []; }
    public function getMigrationFiles(array $paths): array { return $this->files; }
}
class BoundaryApp extends ArrayObject
{
    public function __construct(private string $root)
    {
        parent::__construct(['db' => new BoundaryDb(new BoundaryPdo), 'migration.repository' => new BoundaryRepository, 'migrator' => new BoundaryMigrator]);
    }
    public function databasePath(string $path): string { return $this->root.'/'.$path; }
}
function verify(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException($label); }
}
$root = sys_get_temp_dir().'/boundary4508-'.bin2hex(random_bytes(8));
mkdir($root, 0700); mkdir($root.'/migrations', 0700);
$path = $root.'/migrations/'.\AllTrue4508\MIGRATION.'.php';
copy(__DIR__.'/fixtures/4508-expiry-migration.php.fixture', $path);
try {
    for ($case = 0; $case < 15; $case++) {
        $app = new BoundaryApp($root); $calls = 0; $expected = 78; $check = false;
        if ($case === 0) { $check = true; $expected = 0; }
        if ($case === 1) { $expected = 0; }
        if ($case === 2) { $app['migrator']->files['outside'] = 'fixture'; }
        if ($case === 3) { $app['db']->pdo->version = '8.0.46'; }
        if ($case === 4) { $app['db']->pdo->vars['wsrep_on'] = 'ON'; }
        if ($case === 5) { $app['db']->pdo->ignoreSets = true; }
        if (in_array($case, [6, 7, 8, 10], true)) { $expected = 75; }
        if ($case === 8) { $app['db']->pdo->nullable = false; }
        if ($case === 9) { $app['migration.repository']->ran = ['duplicate', 'duplicate']; }
        if ($case === 11) { $app['db']->defaultName = 'mysql::read'; } // getName() remains mysql in real Laravel.
        if ($case === 12) { $app['db']->config['host'] = 'other-host'; }
        if ($case === 13) { $app['db']->config['port'] = 3307; }
        if ($case === 14) { $app['db']->config['write'] = ['host' => 'other-host']; }
        ob_start();
        $code = \AllTrue4508\run($app, function () use ($app, &$calls, $case) {
            $calls++;
            if ($case === 10) { ($app['db']->reconnector)(); }
            if ($case === 6) { echo 'FAKE_PRIVATE'; throw new RuntimeException('FAKE_PRIVATE_EXCEPTION'); }
            if ($case !== 7) { $app['migration.repository']->ran[] = \AllTrue4508\MIGRATION; }
            return 0;
        }, $check);
        $output = ob_get_clean();
        verify(!str_contains($output, 'FAKE_PRIVATE'), 'no raw application/exception output');
        verify($code === $expected, 'case '.$case.' exit');
        verify($app['db']->writeReads === ($case < 11), 'unknown endpoint rejected before connection reads');
        if ($case >= 11) { verify($app['db']->pdo->sets === [], 'unknown endpoint must not SET'); }
        verify($calls === (in_array($case, [1, 6, 7, 8, 10], true) ? 1 : 0), 'no unexpected migration');
        if ($case === 0) { verify($app['db']->pdo->sets === [], 'check must not SET/DDL'); }
        if ($expected === 75) { verify(str_contains($output, 'HOLD_possible_partial_DDL'), 'no false success'); }
    }
    file_put_contents($path, 'synthetic wrong source');
    $app = new BoundaryApp($root); ob_start(); $code = \AllTrue4508\run($app, fn () => throw new RuntimeException('must not run'), false); ob_end_clean();
    verify($code === 78 && $app['db']->pdo->sets === [], 'wrong source stops before session changes');
    echo "bounded4508: 16 synthetic cases PASS\n";
} finally {
    unlink($path); rmdir($root.'/migrations'); rmdir($root);
}
