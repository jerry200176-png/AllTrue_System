<?php

namespace Tests\Feature;

use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentLineBinding;
use App\Services\ParentBinding\BindingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #3988 on real, independently committed connections (no test transaction
 * wrapper), following NotificationSyncConcurrencyTest. Login and unbind
 * serialize on the student_line_bindings row, so each case runs under both
 * REPEATABLE READ and READ COMMITTED:
 *
 * 1. An in-flight unbind (binding locked, not committed) blocks the login.
 * 2. A login in progress blocks the unbind's binding lock; once the login
 *    commits, the real BindingService unbind expires its session.
 * 3. BindingService::revokeGuardianAccess() takes that binding lock.
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
        $race = DB::connection(self::RACE);
        while ($race->transactionLevel() > 0) {
            $race->rollBack();
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        DB::table('ParentSession')->where('line_user_id', self::LINE_ID)->delete();
        DB::table('student_line_bindings')->where('line_user_id', self::LINE_ID)->delete();
        DB::table('Student')->where('name', 'race-3988-concurrent')->delete();
        DB::table('security_audit_events')->where('event_type', 'parent.auth')
            ->where('created_at', '>=', now()->subMinutes(10))->delete();
        DB::disconnect(self::RACE);
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function isolationLevels(): array
    {
        return [
            'repeatable read' => ['REPEATABLE READ'],
            'read committed' => ['READ COMMITTED'],
        ];
    }

    private function useIsolation(string $level): void
    {
        DB::statement("SET SESSION TRANSACTION ISOLATION LEVEL {$level}");
        DB::connection(self::RACE)->statement("SET SESSION TRANSACTION ISOLATION LEVEL {$level}");
        $this->assertSame(str_replace(' ', '-', $level), alltrue_race_isolation_level());
    }

    private function boundStudent(): array
    {
        $student = Student::create([
            'name' => 'race-3988-concurrent', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $binding = StudentLineBinding::create([
            'student_id' => $student->id, 'line_user_id' => self::LINE_ID,
            'verified_at' => now(), 'verification_method' => 'contact_phone',
        ]);
        Http::fake(['https://api.line.me/v2/profile' => Http::response(['userId' => self::LINE_ID], 200)]);

        return [$student, (int) $binding->id];
    }

    private function activeSessions(Student $student): int
    {
        return ParentSession::query()->where('StudentID', $student->id)
            ->where('line_user_id', self::LINE_ID)->where('ExpiresAt', '>', now())->count();
    }

    private static function isLockWait(QueryException $e): bool
    {
        return str_contains($e->getMessage(), '1205') || str_contains($e->getMessage(), 'Lock wait timeout');
    }

    /** @dataProvider isolationLevels */
    public function test_in_flight_unbind_blocks_the_login(string $level): void
    {
        $this->useIsolation($level);
        [$student] = $this->boundStudent();

        // The unbind's first steps on the other connection, not committed:
        // binding lock, session expiry, binding delete.
        $race = DB::connection(self::RACE);
        $race->beginTransaction();
        $race->table('student_line_bindings')->where('student_id', $student->id)
            ->where('line_user_id', self::LINE_ID)->lockForUpdate()->get(['id']);
        $race->table('ParentSession')->where('StudentID', $student->id)
            ->where('line_user_id', self::LINE_ID)->update(['ExpiresAt' => now()]);
        $race->table('student_line_bindings')->where('student_id', $student->id)
            ->where('line_user_id', self::LINE_ID)->delete();

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $res = $this->postJson('/api/v1/parent/login-line', ['access_token' => 'valid-token']);

        $this->assertNotSame(200, $res->status(), 'login must wait for the in-flight unbind');
        $race->commit();
        $this->assertSame(0, $this->activeSessions($student));
    }

    /** @dataProvider isolationLevels */
    public function test_login_in_progress_blocks_the_unbind_which_then_expires_the_session(string $level): void
    {
        $this->useIsolation($level);
        [$student, $bindingId] = $this->boundStudent();
        $race = DB::connection(self::RACE);
        $race->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = null;
        ParentSession::creating(function () use (&$blocked, $race, $student) {
            if ($blocked !== null) {
                return;
            }
            $race->beginTransaction();
            try {
                $race->table('student_line_bindings')->where('student_id', $student->id)
                    ->where('line_user_id', self::LINE_ID)->lockForUpdate()->get(['id']);
                $blocked = false;
            } catch (QueryException $e) {
                $blocked = self::isLockWait($e);
            } finally {
                $race->rollBack();
            }
        });

        $this->postJson('/api/v1/parent/login-line', ['access_token' => 'valid-token'])->assertOk();
        $this->assertTrue($blocked, 'the unbind lock must wait for the login');
        $this->assertSame(1, $this->activeSessions($student));

        $this->assertTrue(app(BindingService::class)->deleteBinding($bindingId));
        $this->assertSame(0, $this->activeSessions($student));
    }

    /** @dataProvider isolationLevels */
    public function test_revoke_guardian_access_locks_the_binding(string $level): void
    {
        $this->useIsolation($level);
        [$student] = $this->boundStudent();

        DB::beginTransaction();
        app(BindingService::class)->revokeGuardianAccess((int) $student->id, self::LINE_ID);

        $race = DB::connection(self::RACE);
        $race->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = false;
        try {
            $race->table('student_line_bindings')->where('line_user_id', self::LINE_ID)
                ->sharedLock()->get(['id']);
        } catch (QueryException $e) {
            $blocked = self::isLockWait($e);
        }
        DB::rollBack();

        $this->assertTrue($blocked, 'a login lock must wait for the unbind');
    }
}
