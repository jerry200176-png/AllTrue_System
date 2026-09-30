<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\StaffIdentityMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 1 (read-only): candidates + dry-run plan. Fixed dates only; 23:00 start times (Y2). */
class StaffIdentityMergePlanTest extends TestCase
{
    use RefreshDatabase;

    private const CUT = '2026-10-01';

    private int $campus;
    private int $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campus = (int) Campus::factory()->create()->getKey();
        $this->student = (int) Student::factory()->create(['CampusID' => $this->campus])->getKey();
    }

    public function test_candidate_confidence_and_signals_without_pii(): void
    {
        $d1 = $this->user('D', ['LineID' => 'line-secret-1', 'Name' => 'Alpha Person']);
        $t1 = $this->user('T', ['LineID' => 'line-secret-1']);
        $d2 = $this->user('D', ['Name' => 'Same Name']);
        $t2 = $this->user('T', ['Name' => 'Same Name']);
        array_map(fn ($u) => $this->campusFor($u), [$d2, $t2]);

        Artisan::call('staff:identity-merge', ['--candidates' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString("candidate d={$d1} t={$t1} confidence=HIGH signals=line t_status=active", $out);
        $this->assertStringContainsString("candidate d={$d2} t={$t2} confidence=MEDIUM signals=name,campus t_status=active", $out);
        foreach (['line-secret', 'Alpha', 'Same Name'] as $pii) {
            $this->assertStringNotContainsString($pii, $out);
        }
    }

    public function test_plan_issues_no_writes_and_fingerprint_tracks_data(): void
    {
        [$s, $r] = $this->pair();
        $this->course($r);
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
        $this->course($r);
        $this->assertNotSame($a, $svc->plan($s, $r, self::CUT)['fingerprint']);
    }

    public function test_refusals(): void
    {
        [$s, $r] = $this->pair();
        $svc = app(StaffIdentityMergeService::class);
        foreach ([[$s, $this->user('D')], [$this->user('T'), $r], [$s, $s], [$s, 999999], [$s, $r, 'not-a-date']] as $c) {
            $plan = $svc->plan($c[0], $c[1], $c[2] ?? self::CUT);
            $this->assertSame('REFUSED', $plan['result']);
            $this->assertSame([], preg_grep('/^move /', $plan['lines']));
        }
    }

    public function test_classification_conflicts_and_no_go(): void
    {
        [$s, $r] = $this->pair();
        $active = $this->course($r);
        $this->course($r, ['Stop' => 1, 'EndDate' => '2026-08-31']);
        $rs = $this->makeSession($active, '2026-10-05');
        $ss = $this->makeSession($this->course($s), '2026-10-05', '23:10:00', '23:40:00');
        $lrFuture = $this->record($active, $rs, $r, 'pending');
        $this->record($active, $this->makeSession($active, '2026-09-20'), $r, 'pending');
        $subFuture = $this->schedule($r, $active, '2026-10-06');
        $this->schedule($r, $active, '2026-09-10');
        DB::table('UserCampus')->where('UserID', $s)->update(['RFID' => 'AAA']);
        DB::table('UserCampus')->where('UserID', $r)->update(['RFID' => 'BBB']);

        $lines = app(StaffIdentityMergeService::class)->plan($s, $r, self::CUT)['lines'];

        $this->assertContains("move table=StudentClass col=TeacherID count=1 sample={$active}", $lines);
        $this->assertContains("move table=LearningRecord col=TeacherID count=1 sample={$lrFuture}", $lines);
        $this->assertContains("move table=schedules col=teacher_id kind=substitute count=1 sample={$subFuture}", $lines);
        $this->assertContains("conflict slot-overlap retired_session_ids={$rs} survivor_session_ids={$ss}", $lines);
        $this->assertContains("conflict rfid-collision campus_ids={$this->campus}", $lines);
        $this->assertContains('conflict pending-past-learning-records count=1', $lines);
        foreach (['slot-overlap', 'rfid-collision', 'retired-has-pending-past-learning-records', 'survivor-missing-director-grant', 'merge-journal-table-missing', 'history-impact-not-computed'] as $code) {
            $this->assertContains("nogo reason={$code}", $lines);
        }
        $this->assertContains('merge-dry-run-result=NO-GO', $lines);
        $this->assertStringNotContainsString('AAA', implode("\n", $lines));
    }

    private function pair(): array
    {
        $ids = [$this->user('D'), $this->user('T')];
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

    private function course(int $teacher, array $over = []): int
    {
        return (int) StudentClass::create($over + [
            'StudentID' => $this->student, 'TeacherID' => $teacher, 'GradeID' => 1, 'SubjectID' => 1, 'ClassType' => 'one_on_one',
            'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 30, 'RemainingSessions' => 8, 'UsedSessions' => 0,
            'Rate' => 500, 'TotalHours' => 4, 'Charge' => 4000, 'Pay' => 4000, 'Paid' => 0, 'Stop' => 0, 'by1' => 0, 'MDate' => now(),
            'StartDate' => '2026-08-01', 'EndDate' => '2027-01-31',
        ])->ID;
    }

    private function makeSession(int $course, string $date, string $start = '23:00:00', string $end = '23:30:00'): int
    {
        return (int) ClassSession::create(['StudentClassID' => $course, 'SessionDate' => $date, 'StartTime' => $start,
            'EndTime' => $end, 'Status' => 'scheduled'])->id;
    }

    private function record(int $course, int $session, int $teacher, string $status): int
    {
        return (int) LearningRecord::create(['StudentClassID' => $course, 'ClassSessionID' => $session, 'TeacherID' => $teacher,
            'Content' => 'x', 'Subject' => 'Math', 'SessionDate' => '2026-09-01', 'StartTime' => '23:00:00', 'EndTime' => '23:30:00',
            'Status' => $status])->id;
    }

    private function schedule(int $teacher, int $course, string $date): int
    {
        return (int) DB::table('schedules')->insertGetId(['student_id' => $this->student, 'teacher_id' => $teacher, 'day_of_week' => 1,
            'branch_id' => $this->campus, 'start_time' => '23:00', 'end_time' => '23:30', 'status' => 'scheduled',
            'schedule_date' => $date, 'student_course_id' => $course, 'original_schedule_id' => 99]);
    }
}
