<?php
/** Synthetic local/CI metadata fixture; no application configuration or production host. */
$source = file_get_contents(__DIR__.'/../laravel-4504-metadata.php');
$source = substr($source, 5, strrpos($source, 'AllTrue4504Metadata::run();') - 5);
eval($source);
function check(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException($label); }
}
$dsn = (string) getenv('PREFLIGHT_TEST_DSN');
check((bool) preg_match('~^mysql:host=127\.0\.0\.1;port=[0-9]+;dbname=AllTrue_test_4508$~D', $dsn), 'test DSN only');
$pdo = new PDO($dsn, 'root', 'rootpw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE personal_access_tokens (id BIGINT PRIMARY KEY, token VARCHAR(64)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE migrations (migration VARCHAR(255), batch INT) ENGINE=InnoDB');
$pdo->exec("INSERT INTO personal_access_tokens VALUES (1, 'FAKE_PRIVATE_TOKEN_NEVER_READ_BY_COLLECTOR')");
$statement = $pdo->prepare('INSERT INTO migrations VALUES (?, 1)');
$statement->execute([AllTrue4504Metadata::MIGRATION]);
try {
    $schema = AllTrue4504Metadata::metadata($pdo, '', 'migrations');
    check(!$pdo->inTransaction(), 'readonly transaction cleaned up');
    check($schema['preflight']['ledger_complete'], 'complete ledger');
    check(count($schema['preflight']['ledger_records']) === 1, 'metadata ledger only');
    check($schema['preflight']['token_table'][0]['ENGINE'] === 'InnoDB', 'native engine');
    check(array_key_exists('TABLE_ROWS', $schema['preflight']['token_table'][0]), 'estimate captured');
    check(!$schema['preflight']['backup_database_matches'], 'test DB cannot claim production backup match');
    check(!str_contains(json_encode($schema), 'FAKE_PRIVATE'), 'no token values in collector');
    check($pdo->query('SELECT token FROM personal_access_tokens')->fetchColumn() === 'FAKE_PRIVATE_TOKEN_NEVER_READ_BY_COLLECTOR', 'test row unchanged');
    $fake = new class {
        public string $name = 'mysql';
        public array $config = ['host' => '127.0.0.1', 'port' => 3306, 'database' => 'AllTrue'];
        public function getName(): string { return $this->name; }
        public function getConfig(): array { return $this->config; }
    };
    check(AllTrue4504Metadata::backupEndpointMatches($fake, 'mysql'), 'fixed endpoint');
    check(!AllTrue4504Metadata::backupEndpointMatches($fake, 'mysql::read'), 'read alias rejected even though getName is mysql');
    $fake->config['host'] = 'other';
    check(!AllTrue4504Metadata::backupEndpointMatches($fake, 'mysql'), 'same DB different endpoint rejected');
    $pdo->exec('INSERT INTO migrations SELECT migration, batch FROM migrations');
    $schema = AllTrue4504Metadata::metadata($pdo, '', 'migrations');
    check(count($schema['preflight']['ledger_records']) === 2, 'duplicates preserved for projector refusal');
    for ($i = 0; $i < 13; $i++) { $pdo->exec('INSERT INTO migrations SELECT migration, batch FROM migrations'); }
    $schema = AllTrue4504Metadata::metadata($pdo, '', 'migrations');
    check(!$schema['preflight']['ledger_complete'] && count($schema['preflight']['ledger_records']) === 10001, 'sentinel refuses truncated ledger');
    echo "4508_preflight: native read-only metadata / privacy / ledger bounds PASS\n";
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $pdo->exec('DROP TABLE migrations, personal_access_tokens');
}
