<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Scheduling\LessonBeforeContractStartCensus;
use App\Support\ContractPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * in-app #536 / #5198 option A: a lesson reassigned to a contract that starts later is allowed, StartDate stays, the
 * lesson is not flagged IsContractException (that means "off the weekly pattern"), and the read model derives
 * before_contract_start from dates alone.
 */
class ClassSessionBeforeContractStartTest extends TestCase
{
    use RefreshDatabase;

    public function test_pure_rule(): void
    {
        $this->assertTrue(ContractPeriod::lessonBeforeStart('2026-09-27', '2026-10-04'));
        $this->assertTrue(ContractPeriod::lessonBeforeStart('2026-09-27 00:00:00', '2026-10-04 08:00:00'));
        $this->assertFalse(ContractPeriod::lessonBeforeStart('2026-10-04', '2026-10-04'));
        $this->assertFalse(ContractPeriod::lessonBeforeStart('2026-10-05', '2026-10-04'));
        $this->assertFalse(ContractPeriod::lessonBeforeStart('2026-09-27', null));
        $this->assertFalse(ContractPeriod::lessonBeforeStart(null, '2026-10-04'));
    }

    public function test_reassign_to_later_contract_succeeds_and_api_reports_derived_flag_only(): void
    {
        $token = $this->token();
        $student = $this->student(1);
        $old = $this->course($student->id, '2026-08-30');
        $new = $this->course($student->id, '2026-10-04');
        $early = $this->makeSession($old->ID, '2026-09-27');
        $inPeriod = $this->makeSession($new->ID, '2026-10-11');
        $flagBefore = (int) DB::table('ClassSession')->where('id', $early)->value('IsContractException');

        $this->postJson("/api/v1/class-sessions/{$early}/reassign-contract", ['new_student_class_id' => $new->ID, 'reason' => '改到正確合約'], ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->assertSame((int) $new->ID, (int) DB::table('ClassSession')->where('id', $early)->value('StudentClassID'));
        $this->assertSame('2026-10-04', substr((string) DB::table('StudentClass')->where('ID', $new->ID)->value('StartDate'), 0, 10), 'StartDate is not moved');
        $this->assertSame($flagBefore, (int) DB::table('ClassSession')->where('id', $early)->value('IsContractException'), 'IsContractException is untouched');

        $rows = collect($this->getJson("/api/v1/class-sessions?student_class_id={$new->ID}&start=2026-09-01&end=2026-10-31", ['Authorization' => "Bearer {$token}"])->assertOk()->json('materialized.data'))->keyBy('id');
        $this->assertTrue($rows[$early]['before_contract_start']);
        $this->assertFalse($rows[$inPeriod]['before_contract_start']);
        $this->assertSame($flagBefore === 1, $rows[$early]['is_contract_exception']);
    }

    public function test_census_counts_per_campus_ids_only_and_skips_cancelled_and_writes_nothing(): void
    {
        $a = $this->student(1);
        $b = $this->student(2);
        $ca = $this->course($a->id, '2026-10-04');
        $cb = $this->course($b->id, '2026-10-04');
        $s1 = $this->makeSession($ca->ID, '2026-09-27');
        $this->makeSession($ca->ID, '2026-09-20', 'cancelled');
        $this->makeSession($ca->ID, '2026-10-04');
        $s2 = $this->makeSession($cb->ID, '2026-10-03');
        $before = DB::table('ClassSession')->get()->toJson();

        $r = app(LessonBeforeContractStartCensus::class)->run();

        $this->assertSame(2, $r['count']);
        $this->assertSame([1 => [$s1], 2 => [$s2]], array_map(fn ($c) => $c['ids'], $r['by_campus']));
        $this->assertSame($before, DB::table('ClassSession')->get()->toJson());

        Artisan::call('sessions:census-before-contract-start', ['--json' => true]);
        $this->assertSame(2, json_decode(Artisan::output(), true)['count']);
        Artisan::call('bugs:verify-reproductions', ['--json' => true]);
        $row = collect(json_decode(Artisan::output(), true)['conditions'])->firstWhere('key', 'lesson_before_contract_start');
        $this->assertSame([2, false, 'OPEN'], [$row['count'], $row['enforced'], $row['state']]);
    }

    public function test_session_dates_post_flags_materialized_slots_for_count_and_package_courses(): void
    {
        $token = $this->token();
        $student = $this->student(1);
        $plain = $this->course($student->id, '2026-10-04');
        $pkgA = $this->course($student->id, '2026-10-04');
        $pkgB = $this->course($student->id, '2026-10-04');
        DB::table('StudentClass')->whereIn('ID', [$pkgA->ID, $pkgB->ID])->update(['PackageID' => 9801]);
        $expected = [];
        foreach ([$plain, $pkgA] as $c) {
            $expected[(int) $c->ID] = [$this->makeSession($c->ID, '2026-09-27'), $this->makeSession($c->ID, '2026-10-11')];
        }

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/student-classes/session-dates', [
                'branch_id' => 1, 'range_start' => '2026-09-01', 'range_end' => '2026-10-31',
                'courses' => array_map(fn ($c) => ['id' => (int) $c->ID, 'first_class_date' => '2026-10-04', 'sessions_purchased' => 8, 'days_of_week' => [7]], [$plain, $pkgA]),
            ])->assertOk();

        foreach ($expected as $courseId => [$early, $inPeriod]) {
            $flags = collect($res->json("{$courseId}.materialized"))->pluck('before_contract_start', 'id');
            $this->assertTrue($flags[$early], "course {$courseId}: lesson before StartDate");
            $this->assertFalse($flags[$inPeriod], "course {$courseId}: in-period lesson");
        }
    }

    private function token(): string
    {
        $user = User::create(['LoginName' => 'dir-bcs-' . uniqid() . '@test.com', 'Name' => '主任測試', 'PSW' => 'secret', 'type' => 'S', 'phone' => '0912345678']);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function student(int $campusId): Student
    {
        return Student::create(['name' => '期間測試生-' . uniqid(), 'CampusID' => $campusId, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function course(int $studentId, string $startDate): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1, 'Period' => 4,
            'StartDate' => $startDate, 'TotalHours' => 20, 'Charge' => 8800, 'Paid' => 1, 'Rate' => 1100, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
    }

    private function makeSession(int $courseId, string $date, string $status = 'scheduled'): int
    {
        // Y2: 23:00 keeps a same-day row from reading as already ended.
        return DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId, 'SessionDate' => $date, 'StartTime' => '23:00', 'EndTime' => '23:30', 'Status' => $status,
        ]);
    }
}
