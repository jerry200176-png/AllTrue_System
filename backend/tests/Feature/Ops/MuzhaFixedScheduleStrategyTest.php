<?php

namespace Tests\Feature\Ops;

use App\Operations\Strategies\MuzhaFixedScheduleManifest;
use App\Operations\Strategies\MuzhaFixedScheduleStrategy;
use App\Operations\PopOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MuzhaFixedScheduleStrategyTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_preflight_accepts_observed_rows_and_rejects_both_changed_exception_and_extra_row(): void
    {
        $cases = MuzhaFixedScheduleManifest::cases();
        foreach ($cases as $classId => $case) {
            DB::table('Student')->insert([
                'id' => $case['student'], 'name' => $case['name'], 'CampusID' => 16, 'ClassID' => 1,
            ]);
            DB::table('StudentClass')->insert([
                'ID' => $classId, 'StudentID' => $case['student'], 'GradeID' => 1,
                'SubjectID' => 66, 'TeacherID' => 29, 'by1' => 1, 'TotalHours' => 16,
                'StartDate' => $case['start'], 'EndDate' => $case['end'],
                'week' => 6, 'time' => $case['old'], 'ClassType' => 'one_on_two',
                'ScheduleMode' => 'count', 'Stop' => 0, 'SessionDuration' => 120,
                'SessionCount' => $case['count'], 'UsedSessions' => $case['used'],
                'RemainingSessions' => $case['remaining'],
            ]);
            foreach ($case['rows'] as $sessionId => [$date, $start, $status, $exception]) {
                DB::table('ClassSession')->insert([
                    'id' => $sessionId, 'StudentClassID' => $classId,
                    'SessionDate' => $date, 'StartTime' => $start,
                    'EndTime' => date('H:i:s', strtotime($start) + 7200),
                    'Status' => $status, 'IsContractException' => $exception,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $strategy = new MuzhaFixedScheduleStrategy();
        $parameters = ['campus_id' => 16, 'decision_reference' => 'rm-muzha-fixed-schedule-20260930'];
        $plan = $strategy->plan($parameters);
        self::assertTrue($plan['ok'], implode(',', $plan['errors']));
        self::assertSame('before', $plan['state']);
        self::assertSame(20, $plan['time_updates']);
        self::assertSame(32, $plan['occurrence_count']);

        $pop = app(PopOperationService::class);
        $draft = $pop->createDraft('muzha-fixed-schedule-20261001', $parameters,
            'test-muzha-exact-case', 'user:1', 'super_admin', [], 1);
        $dryRun = $pop->runDryRun((string) $draft['id'], 'user:1', 1, 'super_admin');
        self::assertSame('succeeded', $dryRun['result']);
        $approval = $pop->approve((string) $draft['id'], 'founder-go-muzha-test',
            'user:1', 'super_admin', str_repeat('a', 40), 1);
        self::assertTrue($approval['ready']);

        $result = $strategy->execute($plan, ['operation_id' => 'test-muzha']);
        self::assertTrue($result['ok']);
        self::assertSame('after', $strategy->plan($parameters)['state']);
        self::assertTrue($strategy->verify($strategy->plan($parameters), $result)['ok']);
        self::assertSame('15:00:00', DB::table('ClassSession')->where('id', 19267)->value('StartTime'));
        self::assertSame('10:00:00', DB::table('StudentClass')->where('ID', 3428)->value('time'));
        self::assertTrue($strategy->rollback($result['snapshot'], ['operation_id' => 'test-muzha'])['ok']);
        self::assertSame('before', $strategy->plan($parameters)['state']);

        DB::table('ClassSession')->where('id', 19267)->update(['StartTime' => '15:00', 'EndTime' => '17:00']);
        self::assertFalse($strategy->plan($parameters)['ok']);
        DB::table('ClassSession')->where('id', 19267)->update(['StartTime' => '17:00', 'EndTime' => '19:00']);
        DB::table('ClassSession')->insert([
            'id' => 999999, 'StudentClassID' => 2332, 'SessionDate' => '2026-12-26',
            'StartTime' => '15:00', 'EndTime' => '17:00', 'Status' => 'scheduled',
            'IsContractException' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        self::assertContains('occurrence_set_2332', $strategy->plan($parameters)['errors']);
    }
}
