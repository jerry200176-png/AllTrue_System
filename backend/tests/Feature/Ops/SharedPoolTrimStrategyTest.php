<?php

namespace Tests\Feature\Ops;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\StudentSignIn;
use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\SharedPoolTrimStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class SharedPoolTrimStrategyTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'trim-shared-pool-overplan-20261101';

    private int $poolA;
    private int $poolB;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('Student')->insert([['id' => 1, 'name' => 's1', 'CampusID' => 2, 'ClassID' => 1, 'enable' => 1], ['id' => 2, 'name' => 's2', 'CampusID' => 3, 'ClassID' => 1, 'enable' => 1]]);
        // Pre-#4730 pools: 4 bought, each of 2 subjects planned 4 weeks -> 8 planned, 4 past what is left.
        $this->poolA = $this->pool(2, 1, [100, 101]);
        $this->poolB = $this->pool(3, 2, [200, 201]);
    }

    private function pool(int $campus, int $student, array $courses): int
    {
        $id = (int) CoursePackage::create(['student_id' => $student, 'campus_id' => $campus, 'name' => 'pool', 'total_sessions' => 4,
            'remaining_sessions' => 4, 'used_sessions' => 0, 'rate' => 500, 'rate_unit' => 'session', 'class_type' => 'one_on_one',
            'paid' => false, 'stop' => false, 'enabled' => true])->id;
        foreach ($courses as $i => $course) {
            DB::table('StudentClass')->insert(['ID' => $course, 'StudentID' => $student, 'GradeID' => 1, 'SubjectID' => $i + 1, 'TeacherID' => 1,
                'by1' => 1, 'Period' => 4, 'Pay' => 0, 'Rate' => 500, 'TotalHours' => 8, 'StartDate' => now()->toDateString(),
                'ClassType' => 'one_on_one', 'ScheduleMode' => 'count', 'Stop' => 0, 'Paid' => 0, 'Charge' => 0, 'PackageID' => $id]);
            foreach ([1, 2, 3, 4] as $week) {
                ClassSession::create(['StudentClassID' => $course, 'SessionDate' => now()->addWeeks($week)->toDateString(),
                    'StartTime' => $i === 0 ? '16:00:00' : '18:00:00', 'EndTime' => $i === 0 ? '18:00:00' : '20:00:00', 'Status' => 'scheduled', 'Note' => '']);
            }
        }

        return $id;
    }

    private function params(array $campuses, string $digest = ''): array
    {
        return ['campus_ids' => $campuses, 'decision_reference' => self::REF, 'expected_digest' => $digest, 'restore_request_id' => ''];
    }

    /** @return list<string> week-and-course labels of a pool's scheduled lessons */
    private function planned(int $pool): array
    {
        return ClassSession::whereIn('StudentClassID', DB::table('StudentClass')->where('PackageID', $pool)->pluck('ID'))->where('Status', 'scheduled')
            ->orderBy('SessionDate')->orderBy('StartTime')->get()->map(fn ($s) => $s->StudentClassID . '@' . substr((string) $s->SessionDate, 0, 10))->all();
    }

    public function test_dry_run_lists_ids_only_and_writes_nothing(): void
    {
        $plan = (new SharedPoolTrimStrategy())->plan($this->params([2]));

        $this->assertSame('unpinned', $plan['state']);
        $this->assertSame(['pools' => 1, 'rows' => 4, 'blocked' => 0], array_intersect_key($plan['totals'], ['pools' => 0, 'rows' => 0, 'blocked' => 0]));
        $this->assertSame(['package_id', 'session_id', 'course_id', 'date', 'start'], array_keys($plan['manifest'][0]));
        $this->assertSame([$this->poolA], array_values(array_unique(array_column($plan['manifest'], 'package_id'))), 'campus 2 only');
        $this->assertCount(8, $this->planned($this->poolA));
        $this->assertFalse((new SharedPoolTrimStrategy())->plan(['campus_ids' => [2], 'decision_reference' => 'other'])['ok']);
    }

    public function test_execute_needs_the_dry_run_digest(): void
    {
        $s = new SharedPoolTrimStrategy();
        $this->assertSame('digest_mismatch', $s->plan($this->params([2], str_repeat('a', 64)))['state']);
        $this->expectException(RuntimeException::class);
        $s->execute($s->plan($this->params([2])), []);
    }

    public function test_execute_deletes_the_latest_past_what_is_left_and_verify_and_retry_hold(): void
    {
        $s = new SharedPoolTrimStrategy();
        $before = $this->planned($this->poolA);
        $plan = $s->plan($this->params([2], $s->plan($this->params([2]))['digest']));
        $this->assertSame('pinned', $plan['state']);

        $result = $s->execute($plan, ['operation_id' => 'op-1']);

        $this->assertSame(4, $result['deleted']);
        $this->assertSame(array_slice($before, 0, 4), $this->planned($this->poolA), 'weeks 1-2 of both subjects stay');
        $this->assertCount(8, $this->planned($this->poolB), 'another campus is untouched');
        $this->assertCount(4, $result['snapshot']['rows']);
        $this->assertSame(4, DB::table('schedule_audit_logs')->where('action_type', 'delete')->count());
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop.shared_pool_trim')->count());
        $this->assertTrue($s->verify($plan, $result)['ok']);
        $retry = $s->plan($this->params([2], $plan['digest']));
        $this->assertSame('after', $retry['state']);
        $this->assertTrue($s->execute($retry, [])['already_applied']);
    }

    public function test_a_lesson_touched_after_the_dry_run_blocks_execute(): void
    {
        $s = new SharedPoolTrimStrategy();
        $plan = $s->plan($this->params([2], $s->plan($this->params([2]))['digest']));
        $lesson = ClassSession::find($plan['manifest'][0]['session_id']);
        StudentSignIn::create(['StudentClassID' => $lesson->StudentClassID, 'StudentID' => 1, 'ClassSessionID' => $lesson->id,
            'Status' => 'leave', 'SignInDT' => ($at = substr((string) $lesson->SessionDate, 0, 10) . ' 16:00:00'), 'SignOutDT' => $at]);

        try {
            $s->execute($plan, []);
            $this->fail('drift must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('shared_pool_trim_digest_drift', $e->getMessage());
        }
        $this->assertCount(8, $this->planned($this->poolA));
    }

    public function test_a_lesson_moved_after_the_dry_run_blocks_execute(): void
    {
        $s = new SharedPoolTrimStrategy();
        $plan = $s->plan($this->params([2], $s->plan($this->params([2]))['digest']));
        // RescheduleSessionService moves a lesson in place (same row, new date/time) while the approval waits.
        ClassSession::whereKey($plan['manifest'][0]['session_id'])->update(['SessionDate' => now()->addWeeks(9)->toDateString()]);

        try {
            $s->execute($plan, []);
            $this->fail('a moved lesson must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('shared_pool_trim_digest_drift', $e->getMessage());
        }
        $this->assertCount(8, $this->planned($this->poolA));
    }

    public function test_rollback_restores_rows_and_never_doubles_a_replanned_slot(): void
    {
        $s = new SharedPoolTrimStrategy();
        $before = $this->planned($this->poolA);
        $result = $s->execute($s->plan($this->params([2], $s->plan($this->params([2]))['digest'])), []);
        $first = $result['snapshot']['rows'][0];
        ClassSession::create(['StudentClassID' => $first['StudentClassID'], 'SessionDate' => $first['SessionDate'], 'StartTime' => $first['StartTime'],
            'EndTime' => $first['EndTime'], 'Status' => 'scheduled', 'Note' => '']); // a renewal planned that slot again

        $back = $s->rollback($result['snapshot'], []);

        $this->assertSame([3, [(int) $first['id']]], [$back['restored'], $back['skipped_ids']]);
        $this->assertSame($before, $this->planned($this->poolA));
    }

    /** One POP request through the real catalog and service: draft, dry-run, Founder approve, execute, verify. */
    private function popRun(array $params): array
    {
        $service = new PopOperationService(new PopOperationCatalog(dirname(__DIR__, 4) . '/operations/catalog.yaml'));
        $id = (string) $service->createDraft('shared-pool-trim-overplan-20261101', $params, 'pool-trim-test-' . bin2hex(random_bytes(4)),
            'user:1', 'super_admin', [], 1)['id'];
        $dry = $service->runDryRun($id, 'user:1', 1, 'super_admin', []);
        $digest = (string) ($dry['plan']['digest'] ?? '');
        if ($params['expected_digest'] === '') {
            return [$id, $digest, $dry];
        }
        $sha = str_repeat('c', 40);
        $token = $service->approve($id, 'founder-go-pool-trim-test', 'user:2', 'super_admin', $sha, 2, [], 15)['token'];
        $execute = $service->run($id, 'execute', $token, $sha, 'pop-pi-local');
        $verify = $service->run($id, 'verify', $token, $sha, 'pop-pi-local');

        return [$id, $digest, $execute, $verify];
    }

    public function test_a_restore_request_puts_back_a_trim_from_its_stored_snapshot_and_skips_a_taken_student_slot(): void
    {
        [, $digest] = $this->popRun($this->params([2]));
        [$trimId, , $execute, $verify] = $this->popRun($this->params([2], $digest));
        $this->assertSame(['succeeded', 'succeeded'], [$execute['result'], $verify['result']]);
        $this->assertCount(4, $this->planned($this->poolA));
        $deleted = json_decode((string) DB::table('pop_execution_records')->where('operation_id', $trimId)->where('phase', 'execute')->value('snapshot'), true)['rows'];
        // Since the trim, the student booked another course (another package) in one freed slot (Codex P2).
        $taken = $deleted[0];
        DB::table('StudentClass')->insert(['ID' => 300, 'StudentID' => 1, 'GradeID' => 1, 'SubjectID' => 9, 'TeacherID' => 1, 'by1' => 1, 'Period' => 1,
            'Pay' => 0, 'Rate' => 500, 'TotalHours' => 2, 'StartDate' => now()->toDateString(), 'ClassType' => 'one_on_one', 'ScheduleMode' => 'count',
            'Stop' => 0, 'Paid' => 0, 'Charge' => 0]);
        ClassSession::create(['StudentClassID' => 300, 'SessionDate' => $taken['SessionDate'], 'StartTime' => $taken['StartTime'],
            'EndTime' => $taken['EndTime'], 'Status' => 'scheduled', 'Note' => '']);

        $restore = ['restore_request_id' => $trimId] + $this->params([2]);
        [, $restoreDigest, $dry] = $this->popRun($restore);
        $this->assertSame('succeeded', $dry['result']);
        [, , $execute, $verify] = $this->popRun(['expected_digest' => $restoreDigest] + $restore);

        $this->assertSame(['succeeded', 'succeeded'], [$execute['result'], $verify['result']]);
        $this->assertCount(7, $this->planned($this->poolA), 'three come back; the slot the student booked since stays theirs');
        $this->assertFalse(ClassSession::whereKey($taken['id'])->exists());
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'pop.shared_pool_trim.restore')->count());
        $again = (new SharedPoolTrimStrategy())->plan(['expected_digest' => $restoreDigest] + $restore);
        $this->assertSame('after', $again['state'], 'a second restore puts nothing back twice');
    }

    public function test_catalog_entry_requires_the_exact_founder_shape(): void
    {
        $catalog = new PopOperationCatalog(dirname(__DIR__, 4) . '/operations/catalog.yaml');
        $service = new PopOperationService($catalog);
        $roles = new ReflectionMethod($service, 'approvalRoles');
        $entry = $catalog->operation('shared-pool-trim-overplan-20261101');
        $this->assertSame(['campus_ids', 'decision_reference', 'expected_digest', 'restore_request_id'], $entry['parameter_keys']);
        $this->assertSame(['super_admin'], $roles->invoke($service, $entry));
        $entry['blast_radius'] = 'global';
        $this->expectException(RuntimeException::class);
        $roles->invoke($service, $entry);
    }
}
