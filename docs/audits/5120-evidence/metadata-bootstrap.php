<?php
require '/app/tests/bootstrap.php';
\PHPUnit\Event\Facade::instance()->registerSubscriber(new class implements \PHPUnit\Event\Test\PreparedSubscriber {
    public function notify(\PHPUnit\Event\Test\Prepared $event): void {
        $db = \Illuminate\Support\Facades\DB::connection();
        $meta = $db->selectOne('SELECT DATABASE() AS db, @@transaction_isolation AS isolation_level, VERSION() AS version');
        if (!str_starts_with($meta->db, 'AllTrue_test')) throw new \RuntimeException('Non-test database refused');
        $seq = 0;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$seq): void {
            if (preg_match('/for update|^(update|delete|insert)/i', $query->sql)
                && preg_match('/studentclass|learningrecord|studentsingin|session_deduction_ledger|package_session_ledger|user_engagement|`user`/i', $query->sql)) {
                fwrite(STDERR, 'SQL_EVIDENCE ' . json_encode(['seq' => ++$seq, 'sql_template' => $query->sql]) . PHP_EOL);
            }
        });
        fwrite(STDERR, 'ISOLATION_EVIDENCE ' . json_encode(['db' => $meta->db, 'isolation' => $meta->isolation_level, 'mysql' => $meta->version, 'transaction_level' => $db->transactionLevel()]) . PHP_EOL);
    }
});
