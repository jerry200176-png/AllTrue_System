<?php

namespace Tests\Unit;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/** #4504: upgrade an already-migrated Sanctum 2 schema using synthetic data only. */
class SanctumExpiryMigrationTest extends TestCase
{
    private const ORIGINAL = '2019_12_14_000001_create_personal_access_tokens_table';
    private const FORWARD = '2026_10_26_000001_add_expires_at_to_personal_access_tokens';

    private Capsule $capsule;
    private Connection $connection;
    private Builder $schema;
    private mixed $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $database = (string) getenv('DB_DATABASE');
        self::assertMatchesRegularExpression('/^AllTrue_test(?:_[A-Za-z0-9_]+)?$/D', $database);
        self::assertContains(getenv('DB_HOST'), ['127.0.0.1', 'localhost']);
        $this->previousApplication = Facade::getFacadeApplication();
        $this->capsule = new Capsule;
        $this->capsule->addConnection([
            'driver' => 'mysql', 'host' => getenv('DB_HOST'), 'port' => getenv('DB_PORT'),
            'database' => $database, 'username' => getenv('DB_USERNAME'),
            'password' => getenv('DB_PASSWORD'), 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => 'pat4504_',
        ]);
        $this->connection = $this->capsule->getConnection();
        $this->schema = $this->connection->getSchemaBuilder();
        $this->capsule->getContainer()->instance('db', $this->capsule->getDatabaseManager());
        $this->capsule->getContainer()->instance('db.schema', $this->schema);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->capsule->getContainer());
        $this->dropFixtureTables();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->schema)) {
                $this->dropFixtureTables();
                $this->connection->disconnect();
            }
        } finally {
            Facade::clearResolvedInstances();
            if (isset($this->previousApplication)) {
                Facade::setFacadeApplication($this->previousApplication);
            } else {
                Facade::setFacadeApplication(null);
            }
            parent::tearDown();
        }
    }

    private function dropFixtureTables(): void
    {
        $this->schema->dropIfExists('personal_access_tokens');
        $this->schema->dropIfExists('migrations');
    }

    private function migrationPath(string $name): string
    {
        if ($name === self::ORIGINAL && ! is_file(__DIR__.'/../../database/migrations/'.$name.'.php')) {
            return __DIR__.'/../../vendor/laravel/sanctum/database/migrations/'.$name.'.php';
        }

        return __DIR__.'/../../database/migrations/'.$name.'.php';
    }

    private function columns(): array
    {
        $rows = $this->connection->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA '
            .'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            .'ORDER BY ORDINAL_POSITION', ['pat4504_personal_access_tokens']
        );

        return array_map(fn (object $row) => (array) $row, $rows);
    }

    private function indexes(): array
    {
        $rows = $this->connection->select(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX, INDEX_TYPE, SUB_PART '
            .'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            .'ORDER BY INDEX_NAME, SEQ_IN_INDEX', ['pat4504_personal_access_tokens']
        );

        return array_map(fn (object $row) => (array) $row, $rows);
    }

    private function expiryIndexCount(): int
    {
        $indexes = [];
        foreach ($this->indexes() as $row) {
            $indexes[$row['INDEX_NAME']][] = strtolower((string) $row['COLUMN_NAME']);
        }

        return count(array_filter($indexes, fn (array $columns) => $columns === ['expires_at']));
    }

    private function migrateForward(): void
    {
        $migration = require $this->migrationPath(self::FORWARD);
        $migration->up();
    }

    private function legacyTable(bool $withExpiry = false, bool $withIndex = false): void
    {
        $this->schema->create('personal_access_tokens', function (Blueprint $table) use ($withExpiry, $withIndex) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            if ($withExpiry) {
                $table->timestamp('expires_at')->nullable();
                if ($withIndex) {
                    $table->index('expires_at', 'fixture_existing_expiry_index');
                }
            }
            $table->timestamps();
        });
        $this->connection->table('personal_access_tokens')->insert([
            'tokenable_type' => 'synthetic-model', 'tokenable_id' => 7,
            'name' => 'synthetic', 'token' => str_repeat('a', 64),
            'abilities' => '["synthetic"]', 'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    public function test_4504_forward_migration_runs_when_original_is_already_recorded(): void
    {
        $this->legacyTable();
        $before = (array) $this->connection->table('personal_access_tokens')->first();
        $repository = new DatabaseMigrationRepository($this->capsule->getDatabaseManager(), 'migrations');
        $repository->createRepository();
        $repository->log(self::ORIGINAL, 1);
        $migrator = new Migrator($repository, $this->capsule->getDatabaseManager(), new Filesystem);
        $paths = array_values(array_filter([
            $this->migrationPath(self::ORIGINAL), $this->migrationPath(self::FORWARD),
        ], 'is_file'));
        $migrator->run($paths);

        self::assertTrue($this->schema->hasColumn('personal_access_tokens', 'expires_at'));
        self::assertSame(1, $this->expiryIndexCount());
        self::assertSame([self::ORIGINAL, self::FORWARD], $repository->getRan());
        self::assertSame(1, $this->connection->table('migrations')->where('migration', self::ORIGINAL)->value('batch'));
        $after = (array) $this->connection->table('personal_access_tokens')->first();
        self::assertNull($after['expires_at']);
        unset($after['expires_at']);
        self::assertSame($before, $after);
    }

    public function test_4504_missing_expiry_column_is_nullable_and_accepts_new_sanctum_tokens(): void
    {
        $this->legacyTable();
        $this->migrateForward();
        self::assertSame(1, $this->expiryIndexCount());
        $this->connection->table('personal_access_tokens')->insert([
            'tokenable_type' => 'synthetic-model', 'tokenable_id' => 8,
            'name' => 'synthetic-new', 'token' => str_repeat('b', 64), 'expires_at' => null,
        ]);
        self::assertSame(2, $this->connection->table('personal_access_tokens')->count());
        self::assertNull($this->connection->table('personal_access_tokens')->where('tokenable_id', 7)->value('expires_at'));
    }

    public function test_4504_existing_expiry_column_only_gets_missing_index(): void
    {
        $this->legacyTable(withExpiry: true);
        $this->connection->table('personal_access_tokens')->update(['expires_at' => '2027-01-01 00:00:00']);
        $before = $this->columns();
        $this->migrateForward();
        self::assertSame($before, $this->columns());
        self::assertSame(1, $this->expiryIndexCount());
        self::assertSame('2027-01-01 00:00:00', $this->connection->table('personal_access_tokens')->value('expires_at'));
    }

    public function test_4504_complete_schema_is_unchanged_even_with_an_existing_different_index_name(): void
    {
        $this->legacyTable(withExpiry: true, withIndex: true);
        $columns = $this->columns();
        $indexes = $this->indexes();
        $this->migrateForward();
        self::assertSame($columns, $this->columns());
        self::assertSame($indexes, $this->indexes());
        self::assertSame(1, $this->connection->table('personal_access_tokens')->count());
    }

    public function test_4504_repeat_up_and_noop_down_preserve_expiry_data_and_other_indexes(): void
    {
        $this->legacyTable();
        $this->migrateForward();
        $this->connection->table('personal_access_tokens')->update(['expires_at' => '2027-01-01 00:00:00']);
        $before = (array) $this->connection->table('personal_access_tokens')->first();
        $indexes = $this->indexes();
        $migration = require $this->migrationPath(self::FORWARD);
        $migration->up();
        $migration->down();
        $migration->up();
        self::assertSame($before, (array) $this->connection->table('personal_access_tokens')->first());
        self::assertSame($indexes, $this->indexes());
    }

    public function test_4504_missing_base_table_holds_instead_of_silently_recording_success(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('personal_access_tokens must exist before its expiry upgrade');
        $this->migrateForward();
    }

    public function test_4504_composite_index_is_retained_and_does_not_replace_the_expiry_index(): void
    {
        $this->legacyTable(withExpiry: true);
        $this->schema->table('personal_access_tokens', function (Blueprint $table) {
            $table->index(['expires_at', 'tokenable_id'], 'fixture_composite_expiry');
        });
        $this->connection->table('personal_access_tokens')->update(['expires_at' => '2020-01-01 00:00:00']);
        $composite = array_values(array_filter($this->indexes(),
            fn (array $index) => $index['INDEX_NAME'] === 'fixture_composite_expiry'));
        $this->migrateForward();

        self::assertSame(1, $this->expiryIndexCount());
        self::assertSame($composite, array_values(array_filter($this->indexes(),
            fn (array $index) => $index['INDEX_NAME'] === 'fixture_composite_expiry')));
        self::assertSame('2020-01-01 00:00:00', $this->connection->table('personal_access_tokens')->value('expires_at'));
    }

    public function test_4504_all_metadata_guards_use_the_primary_not_a_read_replica(): void
    {
        foreach ([false, true] as $withExpiry) {
            $this->dropFixtureTables();
            $this->legacyTable(withExpiry: $withExpiry);
            $readPdo = $this->connection->getRawReadPdo();
            $replica = $this->createMock(\PDO::class);
            $replica->expects($this->never())->method('prepare');
            $this->connection->setReadPdo($replica);
            try {
                $this->migrateForward();
            } finally {
                $this->connection->setReadPdo($readPdo);
            }

            self::assertTrue($this->schema->hasColumn('personal_access_tokens', 'expires_at'));
            self::assertSame(1, $this->expiryIndexCount());
        }
    }

    public function test_4504_fresh_install_runs_original_and_forward_without_duplicate_indexes(): void
    {
        $repository = new DatabaseMigrationRepository($this->capsule->getDatabaseManager(), 'migrations');
        $repository->createRepository();
        $migrator = new Migrator($repository, $this->capsule->getDatabaseManager(), new Filesystem);
        $migrator->run([$this->migrationPath(self::ORIGINAL), $this->migrationPath(self::FORWARD)]);

        self::assertSame([self::ORIGINAL, self::FORWARD], $repository->getRan());
        self::assertTrue($this->schema->hasColumn('personal_access_tokens', 'expires_at'));
        self::assertSame(1, $this->expiryIndexCount());
        self::assertSame(0, $this->connection->table('personal_access_tokens')->count());
    }
}
