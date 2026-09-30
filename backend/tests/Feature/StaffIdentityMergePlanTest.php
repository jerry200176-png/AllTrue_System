<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\StaffIdentityMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 1 (read-only): survivor = teacher (T), retired = director (D). Candidates + dry-run plan. */
class StaffIdentityMergePlanTest extends TestCase
{
    use RefreshDatabase;

    private const CUT = '2026-10-01';

    private int $campus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campus = (int) Campus::factory()->create()->getKey();
    }

    public function test_candidate_confidence_and_signals_without_pii(): void
    {
        $t1 = $this->user('T', ['LineID' => 'line-secret-1', 'Name' => 'Alpha Person']);
        $d1 = $this->user('D', ['LineID' => 'line-secret-1']);
        $t2 = $this->user('T', ['Name' => 'Same Name', 'phone' => '0911111111']);
        $d2 = $this->user('D', ['Name' => 'Same Name', 'phone' => '0922222222']);
        $t3 = $this->user('T', ['Name' => 'Ph One', 'phone' => '0933-333-333']);
        $d3 = $this->user('D', ['Name' => 'Ph Two', 'phone' => '0933333333']);
        array_map(fn ($u) => $this->campusFor($u), [$t2, $d2]);

        Artisan::call('staff:identity-merge', ['--candidates' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString("candidate teacher={$t1} director={$d1} confidence=HIGH signals=line", $out);
        $this->assertStringContainsString("candidate teacher={$t2} director={$d2} confidence=MEDIUM signals=name,campus", $out);
        $this->assertStringContainsString("candidate teacher={$t3} director={$d3} confidence=MEDIUM signals=phone", $out);
        foreach (['line-secret', 'Alpha', 'Same Name', '0911', '0933'] as $pii) {
            $this->assertStringNotContainsString($pii, $out);
        }
    }

    public function test_plan_issues_no_writes_and_fingerprint_tracks_data(): void
    {
        [$s, $r] = $this->pair();
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });
        $svc = app(StaffIdentityMergeService::class);
        $svc->candidates();
        $a = $svc->plan($s, $r, self::CUT)['fingerprint'];

        $this->assertNotEmpty($sql);
        foreach ($sql as $statement) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|drop|create|truncate)\b/i', $statement);
        }
        $this->assertSame($a, $svc->plan($s, $r, self::CUT)['fingerprint']);
        $this->workflow($r, null);
        $this->assertNotSame($a, $svc->plan($s, $r, self::CUT)['fingerprint']);
    }

    public function test_refusals(): void
    {
        [$s, $r] = $this->pair();
        $svc = app(StaffIdentityMergeService::class);
        foreach ([[$r, $s], [$s, $this->user('T')], [$this->user('D'), $r], [$s, $s], [$s, 999999], [$s, $r, 'not-a-date']] as $c) {
            $plan = $svc->plan($c[0], $c[1], $c[2] ?? self::CUT);
            $this->assertSame('REFUSED', $plan['result']);
            $this->assertSame([], preg_grep('/^(move|grant) /', $plan['lines']));
        }
    }

    public function test_open_objects_move_history_stays_and_phase1_is_always_no_go(): void
    {
        [$s, $r] = $this->pair();
        $extra = (int) Campus::factory()->create()->getKey();
        UserCampus::create(['UserID' => $r, 'CampusID' => $extra, 'Admin' => 1, 'Approved' => 1]);
        $open = $this->workflow($r, null);
        $this->workflow($r, now());

        $lines = app(StaffIdentityMergeService::class)->plan($s, $r, self::CUT)['lines'];

        $this->assertContains("move table=exception_workflows col=owner_user_id count=1 sample={$open}", $lines);
        $this->assertContains("grant table=user_capability_grants create=director campuses={$this->campus},{$extra}", $lines);
        $this->assertContains("union table=UserCampus add_campuses={$extra} rfid_copy_rows=0", $lines);
        $this->assertContains('keep table=exception_workflows col=created_by_user_id count=2', $lines);
        $this->assertContains('alias retired_login=different', $lines);
        $this->assertContains("disable user={$r} status=active->inactive", $lines);
        $this->assertSame([], preg_grep('/^move table=(StudentClass|schedules|LearningRecord)/', $lines), 'teaching data untouched');
        $this->assertContains('nogo reason=phase1-read-only', $lines);
        $this->assertContains('merge-dry-run-result=NO-GO', $lines);
        $this->assertContains('READ_ONLY=true', $lines);
    }

    public function test_rfid_collision_and_self_approval_are_no_go(): void
    {
        [$s, $r] = $this->pair();
        DB::table('UserCampus')->where('UserID', $s)->update(['RFID' => 'AAA']);
        DB::table('UserCampus')->where('UserID', $r)->update(['RFID' => 'BBB']);
        DB::table('teacher_payroll_deductions')->insert(['teacher_id' => $s, 'deduction_key' => 'k', 'status' => 'pending']);

        $lines = app(StaffIdentityMergeService::class)->plan($s, $r, self::CUT)['lines'];

        $this->assertContains("conflict rfid-collision campus_ids={$this->campus}", $lines);
        $this->assertContains('conflict self-approval pending_rows=1', $lines);
        $this->assertContains('nogo reason=rfid-collision', $lines);
        $this->assertContains('nogo reason=pending-approvals-with-survivor-as-subject', $lines);
        $this->assertStringNotContainsString('AAA', implode("\n", $lines));
    }

    /** @return array{0:int,1:int} survivor teacher, retired director */
    private function pair(): array
    {
        $ids = [$this->user('T'), $this->user('D')];
        array_map(fn ($u) => $this->campusFor($u), $ids);
        return $ids;
    }

    private function campusFor(int $userId): void
    {
        UserCampus::create(['UserID' => $userId, 'CampusID' => $this->campus, 'Admin' => 0, 'Approved' => 1]);
    }

    private function user(string $type, array $over = []): int
    {
        return (int) User::create($over + [
            'LoginName' => 'sim-' . uniqid('', true) . '@example.com', 'Name' => 'N' . uniqid(), 'PSW' => 'secret',
            'type' => $type, 'phone' => '09' . random_int(10000000, 99999999), 'status' => 'active', 'employment_type' => 'part_time',
        ])->id;
    }

    private function workflow(int $owner, mixed $closedAt): int
    {
        return (int) DB::table('exception_workflows')->insertGetId([
            'source_key' => 'k-' . uniqid(), 'campus_id' => $this->campus, 'type' => 'student_leave', 'owner_user_id' => $owner,
            'created_by_user_id' => $owner, 'closed_at' => $closedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
