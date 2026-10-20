<?php

namespace Tests\Feature;

use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentLineBinding;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #3988 on real, independently committed connections (no test transaction
 * wrapper), following NotificationSyncConcurrencyTest:
 *
 * 1. An unbind committed on another connection between the login's lookup
 *    and its session insert leaves no active session (post-insert re-read).
 * 2. An unbind that runs after the login committed (real BindingService)
 *    expires the new session.
 * 3. An unbind that is still in flight (session expiry done, binding delete
 *    not yet committed) blocks the login's session insert under InnoDB
 *    REPEATABLE READ, so the re-read can only run after the unbind commits.
 *    This is the premise the fix relies on; READ COMMITTED would not hold it.
 */
class ParentLineLoginUnbindConcurrency3988Test extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string|null> */
    protected $connectionsToTransact = [];

    private const LINE_ID = 'U3988cccccccccccccccccccccccccccc';
    private const RACE = 'unbind_race_3988';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This regression covers MySQL/InnoDB locking.');
        }
        config(['database.connections.' . self::RACE => config('database.connections.' . config('database.default'))]);
        DB::purge(self::RACE);
    }

    protected function tearDown(): void
    {
        ParentSession::flushEventListeners();
        DB::table('ParentSession')->where('line_user_id', self::LINE_ID)->delete();
        DB::table('student_line_bindings')->where('line_user_id', self::LINE_ID)->delete();
        DB::table('Student')->where('name', 'race-3988-concurrent')->delete();
        DB::table('security_audit_events')->where('event_type', 'parent.auth')
            ->where('created_at', '>=', now()->subMinutes(10))->delete();
        DB::disconnect(self::RACE);
        parent::tearDown();
    }

    private function boundStudent(): Student
    {
        $student = Student::create([
            'name' => 'race-3988-concurrent', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        StudentLineBinding::create([
            'student_id' => $student->id, 'line_user_id' => self::LINE_ID,
            'verified_at' => now(), 'verification_method' => 'contact_phone',
        ]);
        Http::fake(['https://api.line.me/v2/profile' => Http::response(['userId' => self::LINE_ID], 200)]);

        return $student;
    }

    /** The unbind's two writes (session expiry, then binding delete) on the race connection. */
    private function unbindOnRaceConnection(int $studentId, bool $commit): void
    {
        $race = DB::connection(self::RACE);
        $race->beginTransaction();
        $race->table('ParentSession')
            ->where('StudentID', $studentId)->where('line_user_id', self::LINE_ID)
            ->where('ExpiresAt', '>', now())->update(['ExpiresAt' => now()]);
        $race->table('student_line_bindings')
            ->where('student_id', $studentId)->where('line_user_id', self::LINE_ID)->delete();
        if ($commit) {
            $race->commit();
        }
    }

    public function test_unbind_committed_on_another_connection_before_insert_leaves_no_active_session(): void
    {
        $student = $this->boundStudent();
        $fired = false;
        ParentSession::creating(function () use (&$fired, $student) {
            if ($fired) {
                return;
            }
            $fired = true;
            $this->unbindOnRaceConnection((int) $student->id, true);
        });

        $res = $this->postJson('/api/v1/parent/login-line', ['access_token' => 'valid-token']);

        $this->assertTrue($fired);
        $res->assertStatus(401);
        $this->assertSame(0, ParentSession::query()->where('StudentID', $student->id)
            ->where('line_user_id', self::LINE_ID)->where('ExpiresAt', '>', now())->count());
    }

    public function test_unbind_after_a_committed_login_expires_that_session(): void
    {
        $student = $this->boundStudent();
        $this->postJson('/api/v1/parent/login-line', ['access_token' => 'valid-token'])->assertOk();
        $bindingId = (int) StudentLineBinding::query()->where('line_user_id', self::LINE_ID)->value('id');

        $this->assertTrue(app(\App\Services\ParentBinding\BindingService::class)->deleteBinding($bindingId));

        $this->assertSame(0, ParentSession::query()->where('StudentID', $student->id)
            ->where('line_user_id', self::LINE_ID)->where('ExpiresAt', '>', now())->count());
    }

    public function test_in_flight_unbind_blocks_the_session_insert_under_repeatable_read(): void
    {
        $this->assertSame('REPEATABLE-READ', alltrue_race_isolation_level(), 'the #3988 fix relies on InnoDB next-key locks');

        $student = $this->boundStudent();
        $this->unbindOnRaceConnection((int) $student->id, false);
        try {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
            $blocked = false;
            try {
                DB::table('ParentSession')->insert([
                    'StudentID' => $student->id, 'line_user_id' => self::LINE_ID,
                    'TokenHash' => hash('sha256', 'race-3988'), 'ExpiresAt' => now()->addDays(30),
                ]);
            } catch (QueryException $e) {
                $blocked = str_contains($e->getMessage(), '1205') || str_contains($e->getMessage(), 'Lock wait timeout');
            }
            $this->assertTrue($blocked, 'session insert must wait for the in-flight unbind');
        } finally {
            DB::connection(self::RACE)->rollBack();
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        }
    }
}
