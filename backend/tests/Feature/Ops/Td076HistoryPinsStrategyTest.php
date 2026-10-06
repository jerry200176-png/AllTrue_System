<?php

namespace Tests\Feature\Ops;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\ScheduleChangeLog;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Operations\Strategies\Td076HistoryPinsStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Td076HistoryPinsStrategyTest extends TestCase
{
    use RefreshDatabase;

    private StudentClass $sc;
    private int $contract;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $mk = fn (string $n) => (int) User::create(['LoginName' => "{$n}@td076.example.com", 'Name' => $n, 'PSW' => 'x', 'type' => 'T',
            'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false])->id;
        [$this->contract, $this->other] = [$mk('a'), $mk('b')];
        $stu = Student::create(['name' => 's', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $this->sc = StudentClass::create([
            'StudentID' => $stu->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $this->contract, 'by1' => 1,
            'Period' => 8, 'StartDate' => '2026-04-01', 'TotalHours' => 16, 'Charge' => 0, 'Rate' => 1000,
            'SessionCount' => 8, 'RemainingSessions' => 5, 'SessionDuration' => 120, 'week' => 5, 'time' => '16:00:00',
            'class_type' => 'one_on_one', 'ScheduleMode' => 'count', 'Stop' => 0, 'Paid' => 1, 'MDT' => now(),
        ]);
    }

    public function test_dry_run_writes_nothing_then_pin_and_inverse_remove_exactly_what_was_created(): void
    {
        $session = $this->taught('2026-04-10', [$this->other]);

        $plan = $this->strategy()->plan($this->params());

        $this->assertSame([['type' => 'pin', 'class_session_id' => (int) $session->id, 'student_class_id' => (int) $this->sc->ID, 'teacher_id' => $this->other]], $plan['manifest']['actions']);
        $this->assertSame([0, 0], [Schedule::count(), ScheduleChangeLog::count()], 'dry-run writes nothing');

        $result = $this->strategy()->execute($this->strategy()->plan($this->params($plan['digest'])), []);

        $live = Schedule::where('status', 'scheduled')->firstOrFail();
        $this->assertSame($this->other, (int) $live->teacher_id);
        $this->assertSame('2026-04-10', substr((string) $live->original_schedule_date, 0, 10));
        $this->assertSame('pin', ScheduleChangeLog::firstOrFail()->reason);
        $this->assertSame('after', $this->strategy()->plan($this->params('b' . substr($plan['digest'], 1)))['state'], 'nothing left to pin');

        $this->assertTrue($this->strategy()->rollback($result['snapshot'], [])['ok']);
        $this->assertSame([0, 0], [Schedule::count(), ScheduleChangeLog::count()]);
    }

    public function test_rfid_and_learning_record_conflict_is_quarantined_not_pinned(): void
    {
        $session = $this->taught('2026-04-10', [$this->other, $this->contract]);

        $plan = $this->strategy()->plan($this->params());

        $this->assertSame([], $plan['manifest']['actions']);
        $this->assertSame([['reason' => 'evidence_conflict', 'class_session_id' => (int) $session->id]], $plan['manifest']['quarantine']);
        $this->strategy()->execute($this->strategy()->plan($this->params($plan['digest'])), []);
        $this->assertSame(0, Schedule::count());
    }

    public function test_contract_teacher_evidence_and_existing_exception_rows_need_no_pin(): void
    {
        $this->taught('2026-04-10', [$this->contract]);
        $withRow = $this->taught('2026-04-17', [$this->other]);
        $anchor = Schedule::create(['student_id' => $this->sc->StudentID, 'teacher_id' => $this->contract, 'subject' => 'Math', 'day_of_week' => 5,
            'type' => 'normal', 'status' => 'rescheduled', 'deduction' => 0, 'branch_id' => 1, 'student_course_id' => $this->sc->ID,
            'schedule_date' => '2026-04-17', 'start_time' => '16:00', 'end_time' => '18:00', 'class_type' => 'one_on_one']);
        Schedule::create(['student_id' => $this->sc->StudentID, 'teacher_id' => $this->other, 'subject' => 'Math', 'day_of_week' => 5,
            'type' => 'normal', 'status' => 'scheduled', 'deduction' => 1, 'branch_id' => 1, 'student_course_id' => $this->sc->ID,
            'schedule_date' => '2026-04-17', 'start_time' => '16:00', 'end_time' => '18:00', 'class_type' => 'one_on_one',
            'original_schedule_id' => $anchor->id]);

        $this->assertSame([], $this->strategy()->plan($this->params())['manifest']['actions']);
    }

    private function strategy(): Td076HistoryPinsStrategy
    {
        return app(Td076HistoryPinsStrategy::class);
    }

    /** @return array<string,mixed> */
    private function params(string $digest = ''): array
    {
        return ['campus_id' => 1, 'decision_reference' => 'repair-td076-r2-history-pins-20261006', 'expected_digest' => $digest];
    }

    /** Attended past session; the learning record names the first teacher, a sign-in the second (RFID-style) when given. */
    private function taught(string $date, array $teachers): ClassSession
    {
        $session = ClassSession::create(['StudentClassID' => $this->sc->ID, 'SessionDate' => $date,
            'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'attended']);
        LearningRecord::create(['StudentClassID' => $this->sc->ID, 'ClassSessionID' => $session->id, 'TeacherID' => $teachers[0],
            'Status' => 'approved', 'Content' => 'x', 'SessionDate' => $date, 'StartTime' => '16:00', 'EndTime' => '18:00']);
        if (isset($teachers[1])) {
            StudentSignIn::create(['StudentClassID' => $this->sc->ID, 'StudentID' => $this->sc->StudentID, 'TeacherID' => $teachers[1],
                'RecordedByUserID' => null, 'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1, 'SignInDT' => "{$date} 16:05:00",
                'MDT' => now(), 'ClassSessionID' => $session->id, 'Status' => 'present', 'SessionDeducted' => 1]);
        }

        return $session;
    }
}
