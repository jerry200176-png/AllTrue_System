<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CleansCommittedRows;
use Tests\TestCase;

/** Behaviour of the CleansCommittedRows trait (#5154): rows committed after setUp are gone after its teardown. */
class CleansCommittedRowsTest extends TestCase
{
    use RefreshDatabase;
    use CleansCommittedRows;

    protected array $connectionsToTransact = [];

    public function test_teardown_deletes_rows_committed_after_setup_but_keeps_earlier_rows(): void
    {
        $kept = DB::table('Campus')->count();

        $student = DB::table('Student')->insertGetId(['name' => 'Cleanup probe', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        $audit = DB::table('schedule_audit_logs')->insertGetId(['session_id' => 2, 'action_type' => 'update']);
        $this->assertSame(1, DB::table('Student')->where('id', $student)->count());

        $this->deleteRowsCommittedByThisTest();

        $this->assertSame(0, DB::table('Student')->where('id', $student)->count(), 'committed Student row survived');
        $this->assertSame(0, DB::table('schedule_audit_logs')->where('id', $audit)->count(), 'committed audit row survived');
        $this->assertSame($kept, DB::table('Campus')->count(), 'rows that existed at setUp must stay');
    }

    /** The race test commits User and UserCampus rows; UserCampus has no auto-increment key, so its own tearDown must remove them. */
    public function test_renewal_race_test_leaves_no_user_campus_rows(): void
    {
        $ref = new \ReflectionClass(RenewalSnapshotRaceTest::class);
        $user = DB::table('User')->insertGetId(['LoginName' => 'rr-dir@example.com', 'Name' => 'dir', 'PSW' => 'x', 'type' => 'A', 'phone' => '0911000000', 'MustChangePassword' => 0]);
        DB::table('UserCampus')->insert(['CampusID' => 1, 'UserID' => $user, 'Admin' => 1, 'Approved' => 1]);
        $this->assertSame(1, DB::table('UserCampus')->where('UserID', $user)->count());

        $method = $ref->getMethod('deleteOwnUserCampusRows');
        $method->invoke($ref->newInstanceWithoutConstructor());

        $this->assertSame(0, DB::table('UserCampus')->where('UserID', $user)->count());
    }
}
