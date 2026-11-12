<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * #5113 design PR 2: schema contract of the additive link table `payment_report_reassignments`.
 * Kept apart from the model test: MySQL DDL implicitly commits, so this class creates no rows
 * (they would leak into other tests).
 */
class PaymentReportReassignmentMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'payment_report_reassignments';
    private const MIGRATION = 'migrations/2026_11_12_120000_create_payment_report_reassignments_table.php';

    public function test_columns_match_the_design_and_only_the_source_bill_is_nullable(): void
    {
        $columns = collect(Schema::getColumns(self::TABLE))->keyBy('name');

        $this->assertSame([
            'id', 'from_report_id', 'to_report_id', 'from_invoice_id', 'to_invoice_id', 'from_student_id',
            'to_student_id', 'campus_id', 'amount', 'reason', 'idempotency_key', 'actor_user_id', 'created_at',
        ], $columns->keys()->all());
        $this->assertSame(['from_invoice_id'], $columns->filter(fn ($c) => $c['nullable'])->keys()->all());
        $this->assertTrue($columns['id']['auto_increment']);
        $this->assertStringContainsString('unsigned', $columns['amount']['type']);
        $this->assertSame('varchar(500)', $columns['reason']['type']);
        $this->assertSame('char(36)', $columns['idempotency_key']['type']);
        // Only created_at carries a default (its own insert time); no column can backfill anything.
        $defaulted = $columns->filter(fn ($c) => ! in_array(strtoupper((string) $c['default']), ['', 'NULL'], true));
        $this->assertSame(['created_at'], $defaulted->keys()->all());
        $this->assertStringContainsStringIgnoringCase('current_timestamp', (string) $columns['created_at']['default']);
    }

    public function test_unique_keys_indexes_and_no_foreign_keys(): void
    {
        $indexes = collect(Schema::getIndexes(self::TABLE))->keyBy('name');

        $this->assertSame(['from_report_id'], $indexes['prr_from_report_uq']['columns']);
        $this->assertTrue($indexes['prr_from_report_uq']['unique']);
        $this->assertSame(['to_report_id'], $indexes['prr_to_report_uq']['columns']);
        $this->assertTrue($indexes['prr_to_report_uq']['unique']);
        $this->assertSame(['idempotency_key'], $indexes['prr_idempotency_key_idx']['columns']);
        $this->assertFalse($indexes['prr_idempotency_key_idx']['unique']);
        $this->assertSame(['campus_id', 'created_at'], $indexes['prr_campus_created_idx']['columns']);
        $this->assertFalse($indexes['prr_campus_created_idx']['unique']);
        $this->assertCount(5, $indexes); // primary + the four above
        // History rows outlive what they point at: nothing cascades into or out of this table.
        $this->assertSame([], Schema::getForeignKeys(self::TABLE));
    }

    public function test_migration_is_create_only_and_rolls_back_by_dropping_only_this_table(): void
    {
        $this->assertTrue(Schema::hasTable(self::TABLE));
        $others = array_values(array_diff($this->tableNames(), [self::TABLE]));
        $reportColumns = Schema::getColumnListing('payment_reports');
        $m = require database_path(self::MIGRATION);

        try {
            $m->down();
            $droppedOnlyThis = ! Schema::hasTable(self::TABLE) && $this->tableNames() === $others;
        } finally {
            // Whatever down() did, leave the shared test schema complete and reopen the
            // transaction teardown rolls back (DDL committed the one RefreshDatabase opened).
            if (! Schema::hasTable(self::TABLE)) {
                $m->up();
            }
            if (! DB::connection()->getPdo()->inTransaction()) {
                DB::connection()->getPdo()->beginTransaction();
            }
        }

        $this->assertTrue($droppedOnlyThis, 'down() must drop this table and nothing else');
        $this->assertTrue(Schema::hasTable(self::TABLE));
        $this->assertSame(0, DB::table(self::TABLE)->count()); // up() writes no rows
        $this->assertSame($others, array_values(array_diff($this->tableNames(), [self::TABLE])));
        $this->assertSame($reportColumns, Schema::getColumnListing('payment_reports'));
        $this->assertCount(5, Schema::getIndexes(self::TABLE)); // up() restores the keys too
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        // This connection's database only: parallel shards keep other test databases on the same server.
        return array_map(
            fn ($row) => $row->name,
            DB::select('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')
        );
    }
}
