<?php
/** Disposable local/CI database only; never load application configuration. */
require __DIR__.'/../production-deploy/migrate-4508-bounded.php';

function expect(bool $value, string $label): void
{
    if (!$value) { throw new RuntimeException($label); }
}
$dsn = (string) getenv('BOUNDARY_TEST_DSN');
expect((bool) preg_match('~^mysql:host=127\.0\.0\.1;port=[0-9]+;dbname=AllTrue_test_4508$~D', $dsn), 'test DSN only');
$open = static fn () => new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo = $open(); $blocker = $open();
$pdo->exec('CREATE TABLE personal_access_tokens (id BIGINT PRIMARY KEY, token VARCHAR(64) NOT NULL UNIQUE) ENGINE=InnoDB');
$pdo->exec("INSERT INTO personal_access_tokens VALUES (1, 'synthetic-preserved')");
$db = new class {
    public mixed $reconnector;
    public function setReconnector(callable $callback): void { $this->reconnector = $callback; }
};
try {
    \AllTrue4508\profile($pdo, '');
    \AllTrue4508\configure($db, $pdo);
    expect((float) \AllTrue4508\variables($pdo)['max_statement_time'] === 120.0, 'real session readback');
    $blocker->beginTransaction();
    $blocker->query('SELECT id FROM personal_access_tokens')->fetchAll();
    // Shorter TEST-ONLY values make both independent abort mechanisms observable.
    foreach ([[0.1, 5, 1969], [120, 1, 1205]] as [$statement, $lock, $error]) {
        $pdo->exec('SET SESSION max_statement_time = '.$statement);
        $pdo->exec('SET SESSION lock_wait_timeout = '.$lock);
        $start = microtime(true);
        try {
            $pdo->exec('ALTER TABLE personal_access_tokens ADD expires_at TIMESTAMP NULL');
            throw new RuntimeException('blocked DDL unexpectedly succeeded');
        } catch (PDOException $caught) {
            expect((int) ($caught->errorInfo[1] ?? 0) === $error, 'native abort code '.$error);
        }
        expect(microtime(true) - $start < 10, 'fixture statement returned within test budget');
        expect((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='personal_access_tokens' AND COLUMN_NAME='expires_at'")->fetchColumn() === 0, 'failed ALTER has no column');
    }
    $blocker->rollBack();
    \AllTrue4508\configure($db, $pdo);
    $pdo->exec('ALTER TABLE personal_access_tokens ADD expires_at TIMESTAMP NULL');
    $pdo->exec('ALTER TABLE personal_access_tokens ADD INDEX expiry (expires_at)');
    expect($pdo->query('SELECT token FROM personal_access_tokens WHERE id=1')->fetchColumn() === 'synthetic-preserved', 'existing test row preserved');
    expect($pdo->query('SELECT expires_at FROM personal_access_tokens WHERE id=1')->fetchColumn() === null, 'expiry remains NULL');
    echo "bounded4508: native MariaDB session/statement/MDL/DDL preservation PASS\n";
} finally {
    if ($blocker->inTransaction()) { $blocker->rollBack(); }
    $pdo->exec('DROP TABLE personal_access_tokens');
}
