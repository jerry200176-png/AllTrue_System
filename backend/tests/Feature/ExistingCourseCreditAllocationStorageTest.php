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
        [$token, $source, $target] = $this->fixture();
        $url = "/api/v1/student-classes/{$source->ID}/subject-credit-transfer";
        $input = ['target_course_id' => (int) $target->ID, 'sessions' => 2, 'reason' => 'synthetic legacy transfer'];
        $preview = $this->withToken($token)->postJson($url . '/preview', $input)->assertOk();
        $input += ['state_hash' => $preview->json('state_hash'), 'idempotency_key' => 'storage-legacy-transfer'];
        $created = $this->withToken($token)->postJson($url, $input)->assertCreated();
        $transferId = $created->json('transfer.id');

        // Simulate the schema before this additive migration only in the native isolated database.
        $this->assertStringStartsWith('AllTrue_test_', DB::connection()->getDatabaseName());
        $migration = require database_path('migrations/2026_11_17_073000_create_existing_course_credit_allocation_groups.php');
        $migration->down();
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
}
