<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Deletion-strategy cleanup (the pattern of Ruby's database_cleaner) for tests that commit rows because they set
 * `$connectionsToTransact = []` (DDL, second connections, lock races). RefreshDatabase rolls nothing back for them, so
 * every row they leave behind is visible to every later test of the run, and the nightly random-order job fails
 * (#5154).
 *
 * setUp: record the highest auto-increment primary key of every table. tearDown (always runs): delete the rows above
 * it, with foreign key checks off so table order does not matter. A table that no longer exists is skipped.
 *
 * Known gaps (the test must clean these itself):
 *  - tables with a UUID, string or composite primary key (only single auto-increment keys are tracked);
 *  - a row inserted with an explicit id at or below the table's maximum at setUp;
 *  - a pre-existing row the test updated or deleted (changes are not reverted);
 *  - DDL the test ran (indexes, columns), and files, caches or other connections' session state.
 *
 * Laravel runs `setUp<TraitName>` of every used trait during setUp, after RefreshDatabase.
 */
trait CleansCommittedRows
{
    /** @var array<string,array{pk:string,max:int}> */
    private array $committedRowsSnapshot = [];

    protected function setUpCleansCommittedRows(): void
    {
        $this->committedRowsSnapshot = [];
        foreach ($this->committedRowsAutoIncrementKeys() as $table => $pk) {
            $max = DB::table($table)->max($pk);
            $this->committedRowsSnapshot[$table] = ['pk' => $pk, 'max' => (int) ($max ?? 0)];
        }
        $this->beforeApplicationDestroyed(fn () => $this->deleteRowsCommittedByThisTest());
    }

    protected function deleteRowsCommittedByThisTest(): void
    {
        $existing = $this->committedRowsAutoIncrementKeys();
        $failed = [];
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($this->committedRowsSnapshot as $table => $snapshot) {
                if (!isset($existing[$table])) {
                    continue;
                }
                try {
                    DB::table($table)->where($snapshot['pk'], '>', $snapshot['max'])->delete();
                } catch (\Throwable $e) {
                    $failed[] = $table . ': ' . $e->getMessage();
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        $this->committedRowsSnapshot = [];
        if ($failed !== []) {
            throw new \RuntimeException('CleansCommittedRows could not delete from: ' . implode('; ', $failed));
        }
    }

    /** @return array<string,string> table => single auto-increment primary key column */
    private function committedRowsAutoIncrementKeys(): array
    {
        $rows = DB::select(
            "SELECT c.TABLE_NAME AS t, c.COLUMN_NAME AS col
             FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES tb ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME AND tb.TABLE_TYPE = 'BASE TABLE'
             WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_KEY = 'PRI' AND c.EXTRA LIKE '%auto_increment%'
               AND c.TABLE_NAME <> 'migrations'"
        );
        $keys = [];
        foreach ($rows as $row) {
            $keys[(string) $row->t] = (string) $row->col;
        }

        return $keys;
    }
}
