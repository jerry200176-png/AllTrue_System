<?php

namespace Tests\Feature;

use App\Models\ExistingCourseCreditAllocationGroup;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\{DatabaseTruncation, RefreshDatabaseState};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\Support\SubjectCreditFixtures;
use Tests\TestCase;

/** In-app563: additive audit storage must preserve existing v1 transfers, cash and teaching history. */
class ExistingCourseCreditAllocationStorageTest extends TestCase
{
    use DatabaseTruncation, SubjectCreditFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_additive_storage_preserves_a_preexisting_transfer_and_its_native_replay_and_reverse(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Taipei'));
        // Build the genuine pre-migration shape only in this isolated test; production down() retains audit schema.
        $this->assertStringStartsWith('AllTrue_test_', DB::connection()->getDatabaseName());
        $this->assertDatabaseCount('existing_course_credit_allocation_groups', 0);
        Schema::table('contract_subject_credit_transfers', function (Blueprint $t): void {
            $t->dropForeign('subject_credit_allocation_group_fk');
            $t->dropColumn('allocation_group_id');
        });
        Schema::drop('existing_course_credit_allocation_groups');
        [$token, $source, $target] = $this->fixture();
        $url = "/api/v1/student-classes/{$source->ID}/subject-credit-transfer";
        $input = ['target_course_id' => (int) $target->ID, 'sessions' => 2, 'reason' => 'synthetic legacy transfer'];
        $preview = $this->withToken($token)->postJson($url . '/preview', $input)->assertOk();
        $input += ['state_hash' => $preview->json('state_hash'), 'idempotency_key' => 'storage-legacy-transfer'];
        $created = $this->withToken($token)->postJson($url, $input)->assertCreated();
        $transferId = $created->json('transfer.id');

        $migration = require database_path('migrations/2026_11_17_073000_create_existing_course_credit_allocation_groups.php');
        $this->assertFalse(ExistingCourseCreditAllocationGroup::storageReady());
        $business = $this->businessSnapshot();
        $audit = (array) DB::table('contract_subject_credit_transfers')->where('id', $transferId)->first();
        $migration->up();

        $this->assertTrue(ExistingCourseCreditAllocationGroup::storageReady());
        $this->assertDatabaseCount('existing_course_credit_allocation_groups', 0);
        $current = (array) DB::table('contract_subject_credit_transfers')->where('id', $transferId)->first();
        $this->assertArrayHasKey('allocation_group_id', $current);
        $this->assertNull($current['allocation_group_id']);
        unset($current['allocation_group_id']);
        $this->assertSame($audit, $current);
        $this->assertSame($business, $this->businessSnapshot());
        $this->withToken($token)->postJson($url, $input)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($business, $this->businessSnapshot());
        $this->withToken($token)->postJson($url . "/$transferId/reverse", ['reason' => 'synthetic restore'])->assertOk();
        $this->assertSame([8, 12000], [(int) $source->fresh()->SessionCount, (int) $source->fresh()->Charge]);
        $this->assertDatabaseCount('existing_course_credit_allocation_groups', 0);
    }

    public function test_readiness_requires_the_audit_table_its_typed_fields_and_the_child_link(): void
    {
        $this->assertStringStartsWith('AllTrue_test_', DB::connection()->getDatabaseName());
        $groups = 'existing_course_credit_allocation_groups';
        $children = 'contract_subject_credit_transfers';
        $this->assertTrue(ExistingCourseCreditAllocationGroup::storageReady());
        Schema::rename($groups, $groups . '_isolated_probe');
        try { $this->assertFalse(ExistingCourseCreditAllocationGroup::storageReady()); }
        finally { Schema::rename($groups . '_isolated_probe', $groups); }
        $this->assertTrue(ExistingCourseCreditAllocationGroup::storageReady());
        Schema::table($groups, fn (Blueprint $t) => $t->dropColumn('reverse_reason'));
        try { $this->assertFalse(ExistingCourseCreditAllocationGroup::storageReady()); }
        finally { Schema::table($groups, fn (Blueprint $t) => $t->string('reverse_reason', 100)->nullable()); }
        $this->assertTrue(ExistingCourseCreditAllocationGroup::storageReady());
        Schema::table($children, fn (Blueprint $t) => $t->dropColumn('request_fingerprint'));
        try { $this->assertFalse(ExistingCourseCreditAllocationGroup::storageReady()); }
        finally { Schema::table($children, fn (Blueprint $t) => $t->string('request_fingerprint', 64)); }
        $this->assertTrue(ExistingCourseCreditAllocationGroup::storageReady());
        Schema::table($children, function (Blueprint $t): void {
            $t->dropForeign('subject_credit_allocation_group_fk');
            $t->dropColumn('allocation_group_id');
        });
        try { $this->assertFalse(ExistingCourseCreditAllocationGroup::storageReady()); }
        finally {
            Schema::table($children, function (Blueprint $t) use ($groups): void {
                $t->unsignedBigInteger('allocation_group_id')->nullable();
                $t->foreign('allocation_group_id', 'subject_credit_allocation_group_fk')->references('id')->on($groups)->restrictOnDelete();
            });
        }
        $this->assertTrue(ExistingCourseCreditAllocationGroup::storageReady());
    }

    public function test_schema_rollback_is_also_refused_while_the_audit_table_is_empty(): void
    {
        $this->assertDatabaseCount('existing_course_credit_allocation_groups', 0);
        $this->assertSchemaRollbackRefused();
    }

    public function test_schema_rollback_cannot_erase_an_existing_audit_group_or_its_child_lineage(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Taipei'));
        [$token, $source, $target] = $this->fixture();
        $url = "/api/v1/student-classes/{$source->ID}/subject-credit-transfer";
        $input = ['target_course_id' => (int) $target->ID, 'sessions' => 2, 'reason' => 'synthetic rollback audit'];
        $input += ['state_hash' => $this->withToken($token)->postJson($url . '/preview', $input)->assertOk()->json('state_hash'),
            'idempotency_key' => 'storage-rollback-transfer'];
        $created = $this->withToken($token)->postJson($url, $input)->assertCreated();
        $child = DB::table('contract_subject_credit_transfers')->where('id', $created->json('transfer.id'))->first();
        $group = ExistingCourseCreditAllocationGroup::create([
            'operation_key' => 'synthetic-retained-audit', 'request_fingerprint' => str_repeat('a', 64),
            'campus_id' => $child->campus_id, 'student_id' => $child->student_id,
            'source_student_class_id' => $child->source_student_class_id, 'source_invoice_id' => $child->source_invoice_id,
            'created_by' => $child->created_by, 'sessions' => 2, 'amount' => 3000, 'reason' => 'synthetic retained history',
            'source_before' => [], 'source_after' => [], 'targets_before' => [], 'targets_after' => [], 'released_sessions' => [],
        ]);
        DB::table('contract_subject_credit_transfers')->where('id', $child->id)->update(['allocation_group_id' => $group->id]);
        $this->assertSchemaRollbackRefused();
    }

    private function assertSchemaRollbackRefused(): void
    {
        $this->assertStringStartsWith('AllTrue_test_', DB::connection()->getDatabaseName());
        $before = ['business' => $this->businessSnapshot()];
        foreach (['existing_course_credit_allocation_groups', 'contract_subject_credit_transfers'] as $table) {
            $before[$table] = [Schema::getColumnListing($table), Schema::getForeignKeys($table), DB::table($table)->orderBy('id')->get()->toJson()];
        }
        $migration = require database_path('migrations/2026_11_17_073000_create_existing_course_credit_allocation_groups.php');
        $refusal = null;
        try { $migration->down(); } catch (\RuntimeException $e) { $refusal = $e; }
        $this->assertInstanceOf(\RuntimeException::class, $refusal, 'Audit schema rollback must refuse before any DDL.');
        $this->assertSame('existing_credit_audit_storage_is_retained', $refusal->getMessage());
        $this->assertSame($before['business'], $this->businessSnapshot());
        foreach (['existing_course_credit_allocation_groups', 'contract_subject_credit_transfers'] as $table) {
            $this->assertSame($before[$table], [Schema::getColumnListing($table), Schema::getForeignKeys($table), DB::table($table)->orderBy('id')->get()->toJson()]);
        }
    }
}
