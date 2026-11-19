<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/**
 * RefreshDatabase + AllTrue_test extension columns.
 *
 * - Extension DDL is applied on every refresh (other suites may leave
 *   RefreshDatabaseState::$migrated=true without these columns).
 * - Never DROP/ADD inside an open Testing transaction: MySQL DDL implicit-commits
 *   and would leave Laravel's transaction counter desynced (next begin → SAVEPOINT).
 * - Call endOuterTestingTransactions() before any mid-test DDL, then
 *   rebuildIsolatedSchema() to remigrate + re-enter a real outer transaction.
 */
trait AgreedFeeRefreshDatabase
{
    use RefreshDatabase {
        refreshTestDatabase as private laravelRefreshTestDatabase;
    }

    private bool $agreedFeeRequiresFreshSchema = false;

    protected function refreshTestDatabase()
    {
        // Also protect setup failures after a committed ALTER but before rollback
        // registration. Successful teardown may revalidate the generation below.
        $this->beforeApplicationDestroyed(static function () {
            RefreshDatabaseState::$migrated = false;
        });
        AgreedFeeTestSchema::assertTestConnection();
        $this->agreedFeeRequiresFreshSchema = false;
        if (!RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            $this->app[Kernel::class]->setArtisan(null);
            RefreshDatabaseState::$migrated = true;
        }
        // Remember whether the migration supplied these columns before this fixture ran.
        $hadExtensionColumns = AgreedFeeTestSchema::hasExtensionColumns();
        AgreedFeeTestSchema::ensureExtensionColumns();
        $this->beginDatabaseTransaction();
        // Laravel rolls back fixture rows first. Preserve the schema that was
        // present at setup so ordinary RefreshDatabase tests can reuse it.
        $this->beforeApplicationDestroyed(function () use ($hadExtensionColumns) {
            RefreshDatabaseState::$migrated = false;
            if (!$this->agreedFeeRequiresFreshSchema) {
                RefreshDatabaseState::$migrated = $hadExtensionColumns
                    ? AgreedFeeTestSchema::hasExtensionColumns()
                    : AgreedFeeTestSchema::restoreBaseSchemaAfterTest();
            }
        });
    }

    /**
     * End the RefreshDatabase outer wrap with a real ROLLBACK (all levels),
     * then disconnect so PDO and Laravel's counter are both clean.
     * Call this *before* any ALTER/DROP that would implicit-commit.
     */
    protected function endOuterTestingTransactions(): void
    {
        $this->agreedFeeRequiresFreshSchema = true;
        $database = $this->app->make('db');
        foreach ($this->connectionsToTransact() as $name) {
            $connection = $database->connection($name);
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($connection->transactionLevel() !== 0) {
                throw new \RuntimeException(
                    'Failed to clear testing transaction level before DDL (level='
                    .$connection->transactionLevel().')'
                );
            }
            $connection->disconnect();
        }
    }

    /**
     * migrate:fresh + extension columns, then open a new outer testing transaction.
     * Must only run when no outer testing transaction is open (call
     * endOuterTestingTransactions() first if one was).
     */
    protected function rebuildIsolatedSchema(): void
    {
        $this->agreedFeeRequiresFreshSchema = true;
        AgreedFeeTestSchema::assertTestConnection();
        foreach ($this->connectionsToTransact() as $name) {
            $level = DB::connection($name)->transactionLevel();
            if ($level > 0) {
                throw new \RuntimeException(
                    'rebuildIsolatedSchema requires endOuterTestingTransactions() first; still in level='.$level
                );
            }
        }
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[Kernel::class]->setArtisan(null);
        AgreedFeeTestSchema::ensureExtensionColumns();
        RefreshDatabaseState::$migrated = true;
        $this->beginDatabaseTransaction();
    }
}
