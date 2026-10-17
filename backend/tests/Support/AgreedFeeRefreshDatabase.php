<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/**
 * RefreshDatabase + AllTrue_test extension columns applied **before** the outer txn.
 * Do not DROP/ADD mid-case inside the wrap; use rebuildIsolatedSchema() when DDL is required.
 */
trait AgreedFeeRefreshDatabase
{
    use RefreshDatabase {
        refreshTestDatabase as private laravelRefreshTestDatabase;
    }

    protected function refreshTestDatabase()
    {
        if (!RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            $this->app[Kernel::class]->setArtisan(null);
            AgreedFeeTestSchema::ensureExtensionColumns();
            RefreshDatabaseState::$migrated = true;
        }
        $this->beginDatabaseTransaction();
    }

    protected function rebuildIsolatedSchema(): void
    {
        foreach ($this->connectionsToTransact() as $name) {
            try {
                DB::connection($name)->rollBack();
            } catch (\Throwable $e) {
                // Outer wrap may already be gone after DDL implicit commit.
            }
        }
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app[Kernel::class]->setArtisan(null);
        AgreedFeeTestSchema::ensureExtensionColumns();
        RefreshDatabaseState::$migrated = true;
        $this->beginDatabaseTransaction();
    }
}
