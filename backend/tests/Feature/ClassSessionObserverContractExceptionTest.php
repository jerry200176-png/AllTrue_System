<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * R84 (structural fix for #556/F1/R83's recurrence risk): IsContractException
 * must not depend on every ClassSession-mutating controller/service remembering
 * to call a matcher. ClassSessionObserver::updating() recomputes it whenever an
 * ALREADY-PERSISTED session's SessionDate/StartTime/EndTime is moved, so a brand
 * new write path (the exact way this bug family keeps reappearing) gets correct
 * behavior for free without writing a single line about contract matching.
 *
 * It's deliberately `updating`, not `creating`/`saving`: a brand-new session's
 * mismatch with contract is ambiguous (intentional one-off add vs. raw/stale
 * data that schedule_drift should catch), so creates are left alone — see
 * test_new_session_creation_is_not_auto_flagged below and
 * StudentClassScheduleDriftExceptionTest::test_unflagged_non_contract_session_still_triggers_drift.
 *
 * These tests deliberately bypass RescheduleSessionService, ClassSessionController,
 * and StudentClassController entirely — plain Eloquent attribute assignment + save()
 * — to prove the guarantee lives in the model layer, not in any particular caller.
 */
class ClassSessionObserverContractExceptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression for a real mistake made while building this fix: an earlier
     * version hooked the recompute off `saving` (fires on create too), which
     * broke SameDayMultiSlotTest::test_index_schedule_drift_detected_when_sessions_differ
     * and SyncFutureSessionTimesTwoPhaseTest::test_same_day_shift_forward_uses_two_phase —
     * both create raw off-contract ClassSession rows expecting them to read
     * back unflagged (so schedule_drift / reflow treat them as real drift, not
     * a protected exception). A brand-new row's mismatch with contract is
     * ambiguous intent; only `updating` (existing row, time actually moved) is
     * unambiguous enough to auto-decide.
     */
    public function test_new_session_creation_is_not_auto_flagged(): void
    {
        $this->skipIfColumnMissing();

        $teacher = User::create([
            'LoginName' => 'cso-create-t-' . uniqid() . '@example.com',
            'Name' => '老師',
            'PSW' => 'x',
            'type' => 'T',
            'phone' => '093' . random_int(1000000, 9999999),
            'MustChangePassword' => false,
        ]);
        $student = Student::create([
            'name' => '新建堂次不自動標記測試',
            'CampusID' => 1,
            'ClassID' => 1,
            'SchoolName' => 'Test',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacher->id,
            'ClassType' => 'one_on_one',
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-07-03',
            'TotalHours' => 20,
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 6,
            'UsedSessions' => 2,
            'Charge' => 1600,
            'Pay' => 16000,
            'Paid' => 0,
            'Rate' => 800,
            'Stop' => 0,
            'MDate' => now(),
            'ScheduleMode' => 'count',
            'week' => 6, // contract = Saturday
            'time' => '15:00:00',
            'Memo' => '',
        ]);

        // Off-contract at creation time (Friday 10:00, contract is Sat 15:00) —
        // simulates raw/stale data a backfill or legacy import might leave behind.
        $session = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-07-31', // Friday
            'StartTime' => '10:00:00',
            'EndTime' => '12:00:00',
            'Status' => 'scheduled',
        ]);

        $session->refresh();
        $this->assertFalse(
            (bool) $session->IsContractException,
            'A brand-new off-contract session must NOT be auto-flagged on create — schedule_drift must still catch it.'
        );
    }

    public function test_generic_write_path_marks_exception_when_moved_off_contract_slot(): void
    {
        $this->skipIfColumnMissing();

        [$course, $session] = $this->seedFridayContractSession();

        $this->assertFalse((bool) $session->IsContractException);

        // Nothing here calls ContractScheduleMatcher or any reschedule service —
        // this is exactly the shape of code a future write path would write.
        $session->StartTime = '15:00:00';
        $session->EndTime = '17:00:00';
        $session->save();

        $session->refresh();
        $this->assertTrue(
            (bool) $session->IsContractException,
            'A plain save() moving the session off its contract slot must self-mark the exception flag with no caller-side matcher call.'
        );
    }

    public function test_moving_back_onto_contract_slot_clears_exception_flag(): void
    {
        $this->skipIfColumnMissing();

        [$course, $session] = $this->seedFridayContractSession();

        $session->StartTime = '15:00:00';
        $session->EndTime = '17:00:00';
        $session->save();
        $session->refresh();
        $this->assertTrue((bool) $session->IsContractException);

        $session->StartTime = '20:00:00';
        $session->EndTime = '22:00:00';
        $session->save();

        $session->refresh();
        $this->assertFalse(
            (bool) $session->IsContractException,
            'Moving back onto the contract slot must clear the exception flag (R83).'
        );
    }

    public function test_explicit_assignment_in_the_same_save_is_not_overridden(): void
    {
        $this->skipIfColumnMissing();

        [$course, $session] = $this->seedFridayContractSession();

        // Mirrors ExceptionWorkflowController::confirm — a session explicitly
        // created/updated as an exception must stay one even if its date/time
        // happens to land back on the contract slot, because the caller set the
        // flag explicitly in the same write.
        $session->StartTime = '20:00:00';
        $session->EndTime = '22:00:00';
        $session->IsContractException = true;
        $session->save();

        $session->refresh();
        $this->assertTrue(
            (bool) $session->IsContractException,
            'An explicitly-set IsContractException in the same save must win over the auto-recompute.'
        );
    }

    public function test_unrelated_field_change_does_not_touch_exception_flag(): void
    {
        $this->skipIfColumnMissing();

        [$course, $session] = $this->seedFridayContractSession();

        $session->StartTime = '15:00:00';
        $session->EndTime = '17:00:00';
        $session->save();
        $session->refresh();
        $this->assertTrue((bool) $session->IsContractException);

        // Status-only change (e.g. attendance) must not recompute the flag.
        $session->Status = 'attended';
        $session->save();

        $session->refresh();
        $this->assertTrue((bool) $session->IsContractException);
    }

    private function skipIfColumnMissing(): void
    {
        if (!Schema::hasColumn('ClassSession', 'IsContractException')) {
            $this->markTestSkipped('IsContractException column missing');
        }
    }

    /** @return array{0: StudentClass, 1: ClassSession} */
    private function seedFridayContractSession(): array
    {
        $teacher = User::create([
            'LoginName' => 'cso-t-' . uniqid() . '@example.com',
            'Name' => '老師',
            'PSW' => 'x',
            'type' => 'T',
            'phone' => '092' . random_int(1000000, 9999999),
            'MustChangePassword' => false,
        ]);

        $student = Student::create([
            'name' => '契約例外觀察者測試',
            'CampusID' => 1,
            'ClassID' => 1,
            'SchoolName' => 'Test',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacher->id,
            'ClassType' => 'one_on_one',
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-07-03',
            'TotalHours' => 20,
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 6,
            'UsedSessions' => 2,
            'Charge' => 1600,
            'Pay' => 16000,
            'Paid' => 0,
            'Rate' => 800,
            'Stop' => 0,
            'MDate' => now(),
            'ScheduleMode' => 'count',
            'week' => 5,
            'time' => '20:00:00',
            'Memo' => '',
        ]);

        $session = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-07-31', // Friday
            'StartTime' => '20:00:00',
            'EndTime' => '22:00:00',
            'Status' => 'scheduled',
        ]);

        return [$course, $session];
    }
}
