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
 * Two real connections, committed rows: right before the enrollment transaction's first student FOR UPDATE,
 * a second connection commits a completed session for the same slot (a stale snapshot would miss it).
 */
class RenewalSnapshotRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function connectionsToTransact()
    {
        return [];
    }

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

    /** Barrier: right before the transaction's first student FOR UPDATE, connection A commits $concurrent; then the forced renewal runs. */
    private function race(callable $concurrent, string $renewDate, string $kind, int $weekday = 3): array
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
        $payload = fn (string $date, string $kind) => [
            'branch_id' => 1, 'student_id' => $student->id, 'teacher_id' => $teacher->id, 'subject' => 'Math',
            'class_type' => 'one_on_one', 'total_classes' => 1, 'confirmed_dates' => [], 'future_dates' => [],
            'session_plan' => [['session_date' => $date, 'start_time' => '16:00', 'kind' => $kind, 'subject' => 'Math']],
            'days_of_week' => [Carbon::parse($date)->dayOfWeekIso], 'start_time' => '16:00', 'duration_minutes' => 120,
            'price_per_session' => 500, 'payment_type' => 'session', 'course_start_date' => $date,
        ];
        $first = (int) $this->withHeaders($h)->postJson('/api/v1/class-sessions/batch',
            $payload(Carbon::now()->addWeeks(8)->next(Carbon::WEDNESDAY)->toDateString(), 'future'))->assertCreated()->json('student_class_id');

        config(['database.connections.mysql_a' => config('database.connections.'.config('database.default'))]);
        $a = DB::connection('mysql_a');
        $fired = false;
        DB::connection()->beforeExecuting(function ($q) use (&$fired, $a, $first, $student, $teacher, $concurrent) {
            if (!$fired && stripos($q, 'for update') !== false && stripos($q, '`Student`') !== false) {
                $fired = true;
                $a->transaction(fn () => $concurrent($a, $first, ['student' => $student->id, 'teacher' => $teacher->id]));
            }
        });
        $renew = $payload($renewDate, $kind) + ['force' => true, 'force_reason' => 'renewal_next_term', 'existing_contract_ids' => [$first]];
        $res = $this->withHeaders($h)->postJson('/api/v1/class-sessions/batch', $renew);
        $this->assertTrue($fired, 'student lock query never ran');

        return [$res, $first];
    }

    public function test_renewal_guard_sees_concurrent_commit_despite_repeatable_read(): void
    {
        $past = Carbon::now()->subWeeks(2)->toDateString();
        $sessions = DB::table('ClassSession')->count();
        [$res] = $this->race(fn ($a, $first) => $a->table('ClassSession')->insert([
            'StudentClassID' => $first, 'SessionDate' => $past, 'StartTime' => '16:00:00',
            'EndTime' => '18:00:00', 'Status' => 'completed', 'Note' => '',
        ]), $past, 'confirmed');

        $this->assertSame(422, $res->status(), $res->getContent());
        $this->assertSame('renewal_confirmed_slot_overlap', $res->json('code'));
        $this->assertSame($sessions + 2, DB::table('ClassSession')->count()); // first course session + the concurrent commit
    }

    public function test_second_concurrent_count_renewal_is_revalidated_under_the_lock(): void
    {
        $thu = Carbon::now()->addWeeks(30)->next(Carbon::THURSDAY)->toDateString();
        $classes = DB::table('StudentClass')->count();
        [$res] = $this->race(function ($a, $first) use ($thu) {
            $row = (array) $a->table('StudentClass')->where('ID', $first)->first();
            unset($row['ID']);
            $a->table('StudentClass')->insert(array_merge($row, ['StartDate' => $thu, 'EndDate' => $thu]));
        }, $thu, 'future', 4);

        $this->assertSame(422, $res->status(), $res->getContent());
        $this->assertSame('renewal_range_overlap', $res->json('code'));
        $this->assertSame($classes + 2, DB::table('StudentClass')->count()); // first course + the concurrent contract only
    }

    public function test_legitimate_renewal_still_succeeds_after_an_unrelated_concurrent_commit(): void
    {
        $later = Carbon::now()->addWeeks(40)->next(Carbon::WEDNESDAY)->toDateString();
        [$res, $first] = $this->race(fn ($a, $first, $ids) => $a->table('Student')->where('id', $ids['student'])
            ->update(['name' => 'renamed']), $later, 'future');

        $this->assertSame(201, $res->status(), $res->getContent());
        $this->assertSame(2, DB::table('StudentClass')->count());
        $this->assertNotSame($first, (int) $res->json('student_class_id'));
    }
}
