<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\ClassSessionMaterializationService;
use App\Services\RescheduleSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Occurrence identity P1: ClassSession.OriginalSessionDate/OriginalStartTimeHM are stamped by
 * upsertSlot on create (series rows only) and are write-once via ClassSessionObserver.
 */
class ClassSessionOriginalSlotIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function course(): StudentClass
    {
        $teacher = User::create([
            'LoginName' => 'osi-t-' . uniqid() . '@example.com', 'Name' => '老師', 'PSW' => 'x', 'type' => 'T',
            'phone' => '091' . random_int(1000000, 9999999), 'MustChangePassword' => false,
        ]);
        $student = Student::create([
            'name' => '原始時段身分測試', 'CampusID' => 1, 'ClassID' => 1, 'SchoolName' => 'Test',
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);

        return StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacher->id,
            'ClassType' => 'one_on_one', 'by1' => 1, 'Period' => 4, 'StartDate' => '2026-07-03', 'TotalHours' => 20,
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0, 'MDate' => now(),
            'ScheduleMode' => 'count', 'week' => 5, 'time' => '20:00:00', 'Memo' => '',
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function slot(StudentClass $c, array $extra = []): ClassSession
    {
        return app(ClassSessionMaterializationService::class)->upsertSlot(array_merge([
            'StudentClassID' => $c->ID, 'SessionDate' => '2026-12-04', 'StartTime' => '20:00:00',
            'EndTime' => '22:00:00', 'Status' => 'scheduled',
        ], $extra))['session'];
    }

    private function orig(ClassSession $s): array
    {
        $r = ClassSession::query()->find($s->id);

        return [substr((string) $r->OriginalSessionDate, 0, 10), $r->OriginalStartTimeHM];
    }

    public function test_upsert_slot_stamps_original_slot_on_create_only(): void
    {
        $c = $this->course();
        $s = $this->slot($c);
        $this->assertSame(['2026-12-04', '20:00'], $this->orig($s));

        // idempotent hit returns the existing row; a different payload must not restamp it
        $again = app(ClassSessionMaterializationService::class)->upsertSlot([
            'StudentClassID' => $c->ID, 'SessionDate' => '2026-12-04', 'StartTime' => '20:00:00',
        ]);
        $this->assertFalse($again['created']);
        $this->assertSame(['2026-12-04', '20:00'], $this->orig($again['session']));
    }

    public function test_extra_occurrence_has_no_original_slot(): void
    {
        $s = $this->slot($this->course(), ['_extra_occurrence' => true]);
        $r = ClassSession::query()->find($s->id);
        $this->assertNull($r->OriginalSessionDate);
        $this->assertNull($r->OriginalStartTimeHM);
    }

    public function test_original_slot_survives_reschedule_substitute_restore_and_transfer(): void
    {
        $c = $this->course();
        $other = $this->course();
        $s = $this->slot($c);
        $expected = ['2026-12-04', '20:00'];

        app(RescheduleSessionService::class)->execute([
            'student_class_id' => $c->ID, 'old_date' => '2026-12-04', 'old_start_time' => '20:00',
            'new_date' => '2026-12-05', 'start_time' => '10:00', 'end_time' => '12:00',
        ]);
        $s->refresh();
        $this->assertSame('2026-12-05', substr((string) $s->SessionDate, 0, 10), 'session moved in place');
        $this->assertSame($expected, $this->orig($s));

        // substitute-style teacher/time change, then restore to the original slot
        $s->update(['SessionDate' => '2026-12-06', 'StartTime' => '09:00:00', 'EndTime' => '11:00:00', 'Note' => 'substitute']);
        $this->assertSame($expected, $this->orig($s));
        $s->update(['SessionDate' => '2026-12-04', 'StartTime' => '20:00:00', 'EndTime' => '22:00:00', 'Note' => 'restore']);
        $this->assertSame($expected, $this->orig($s));

        // transfer to another contract keeps the frozen identity (Founder Q2)
        $s->update(['StudentClassID' => $other->ID]);
        $this->assertSame($expected, $this->orig($s));
    }

    public function test_observer_ignores_and_logs_mutation_of_set_original_slot(): void
    {
        Log::spy();
        $s = $this->slot($this->course());

        $s->OriginalSessionDate = '2027-01-01';
        $s->OriginalStartTimeHM = '08:00';
        $s->Note = 'try to rewrite identity';
        $s->save();

        $this->assertSame(['2026-12-04', '20:00'], $this->orig($s));
        $this->assertSame('try to rewrite identity', ClassSession::find($s->id)->Note, 'other fields still save');
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_null_original_slot_can_be_set_once_then_frozen(): void
    {
        $s = $this->slot($this->course(), ['_extra_occurrence' => true]);
        $s->update(['OriginalSessionDate' => '2026-12-04', 'OriginalStartTimeHM' => '20:00']);
        $this->assertSame(['2026-12-04', '20:00'], $this->orig($s));
        $s->update(['OriginalStartTimeHM' => '21:00']);
        $this->assertSame(['2026-12-04', '20:00'], $this->orig($s));
    }
}
