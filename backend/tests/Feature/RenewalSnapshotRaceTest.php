<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #3738 review: MySQL REPEATABLE READ snapshot vs the forced-renewal student lock.
 *
 * Uses TWO real connections and committed data (no RefreshDatabase wrapper): just before the enrollment
 * transaction's first student FOR UPDATE, a second connection commits a completed session for the same slot.
 * If the request had already pinned a snapshot (plain read before the lock) it would miss that commit.
 */
class RenewalSnapshotRaceTest extends TestCase
{
    use RefreshDatabase;

    /** Committed data on purpose: no wrapping transaction (the second connection must see commits). */
    protected function connectionsToTransact()
    {
        return [];
    }

    /** @var array<string,int> table => max(id-like) before the test, to delete only what the test created */
    private array $baseline = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseline = $this->rowCounts();
    }

    /** @return array<string,int> */
    private function rowCounts(): array
    {
        $out = [];
        foreach (DB::select('select table_name as t from information_schema.tables where table_schema = database() and table_type = "BASE TABLE"') as $r) {
            $out[$r->t] = (int) DB::table($r->t)->count();
        }

        return $out;
    }

    protected function tearDown(): void
    {
        // Remove only what this test committed: tables whose row count grew are emptied of rows beyond the baseline.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->rowCounts() as $t => $n) {
            if ($n > ($this->baseline[$t] ?? 0)) {
                $col = DB::selectOne('select column_name as c from information_schema.columns where table_schema = database() and table_name = ? order by ordinal_position limit 1', [$t])->c;
                DB::table($t)->orderByDesc($col)->limit($n - ($this->baseline[$t] ?? 0))->delete();
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    public function test_renewal_guard_sees_concurrent_commit_despite_repeatable_read(): void
    {
        try {
            $iso = DB::selectOne('select @@transaction_isolation as i')->i; // MySQL 8
        } catch (\Throwable) {
            $iso = DB::selectOne('select @@tx_isolation as i')->i; // MariaDB / MySQL 5.7
        }
        $this->assertSame('REPEATABLE-READ', $iso);
        DB::table('Subject')->count() ?: DB::table('Subject')->insert([['School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => 'Math']]);
        $user = User::create(['LoginName' => 'rr-dir@example.com', 'Name' => 'dir', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0911000000', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $teacher = User::create(['LoginName' => 'rr-t@example.com', 'Name' => 't', 'PSW' => 'secret', 'type' => 'T', 'phone' => '0922111111', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $student = Student::create(['name' => 'rr', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $h = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $future = Carbon::now()->addWeeks(8)->next(Carbon::WEDNESDAY)->toDateString();
        $past = Carbon::now()->subWeeks(2)->toDateString();
        $payload = fn (string $date, string $kind) => [
            'branch_id' => 1, 'student_id' => $student->id, 'teacher_id' => $teacher->id, 'subject' => 'Math',
            'class_type' => 'one_on_one', 'total_classes' => 1, 'confirmed_dates' => [], 'future_dates' => [],
            'session_plan' => [['session_date' => $date, 'start_time' => '16:00', 'kind' => $kind, 'subject' => 'Math']],
            'days_of_week' => [Carbon::parse($date)->dayOfWeekIso], 'start_time' => '16:00', 'duration_minutes' => 120,
            'price_per_session' => 500, 'payment_type' => 'session', 'course_start_date' => $date,
        ];
        $first = (int) $this->withHeaders($h)->postJson('/api/v1/class-sessions/batch', $payload($future, 'future'))
            ->assertCreated()->json('student_class_id');

        config(['database.connections.mysql_a' => config('database.connections.'.config('database.default'))]);
        $a = DB::connection('mysql_a');
        $sessions = DB::table('ClassSession')->count();
        $fired = false;
        DB::connection()->beforeExecuting(function ($q) use (&$fired, $a, $first, $past) {
            if (!$fired && stripos($q, 'for update') !== false && stripos($q, '`Student`') !== false) {
                $fired = true;
                $a->transaction(fn () => $a->table('ClassSession')->insert([
                    'StudentClassID' => $first, 'SessionDate' => $past, 'StartTime' => '16:00:00',
                    'EndTime' => '18:00:00', 'Status' => 'completed', 'Note' => '',
                ]));
            }
        });

        $renew = $payload($past, 'confirmed') + ['force' => true, 'force_reason' => 'renewal_next_term', 'existing_contract_ids' => [$first]];
        $res = $this->withHeaders($h)->postJson('/api/v1/class-sessions/batch', $renew);

        $this->assertTrue($fired, 'student lock query never ran');
        $this->assertSame(422, $res->status(), $res->getContent());
        $this->assertSame('renewal_confirmed_slot_overlap', $res->json('code'));
        $this->assertSame($sessions + 1, DB::table('ClassSession')->count()); // only the concurrent commit
    }
}
