<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\CourseLeaveCascadeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression for in-app #217/#218: undoLeaveCascade()'s manual_occurrence branch
 * tried to un-void LearningRecord/StudentSignIn rows by matching
 * VoidReason against a corrupted string literal that never matched the real
 * '一般請假' value applyLeaveCascade() writes. The un-void update() silently
 * affected zero rows, so the session stayed permanently voided even after
 * the leave was undone -- and any later save for that ClassSessionID hit the
 * table's unique(ClassSessionID) index against the still-voided orphan.
 */
class ManualOccurrenceLeaveUndoVoidRestoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_undo_leave_restores_voided_learning_record_and_sign_in(): void
    {
        $student = Student::create([
            'name' => '手動排課請假撤銷測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = StudentClass::create([
            'StudentID' => $student->id,
            'TeacherID' => 99,
            'GradeID' => 1,
            'SubjectID' => 1,
            'ClassType' => 'one_on_one',
            'ScheduleMode' => 'count',
            'scheduling_policy' => 'manual_occurrence',
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'SessionDuration' => 60,
            'Rate' => 500,
            'TotalHours' => 8,
            'Charge' => 4000,
            'StartDate' => Carbon::today()->toDateString(),
            'Stop' => 0,
            'by1' => 1,
            'MDate' => now(),
        ]);

        $sessionDate = Carbon::today()->addDays(3)->toDateString();
        $session = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => $sessionDate,
            'StartTime' => '15:00:00',
            'EndTime' => '17:00:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $record = LearningRecord::create([
            'StudentClassID' => $course->ID,
            'ClassSessionID' => $session->id,
            'TeacherID' => 99,
            'Content' => '',
            'Status' => 'pending',
        ]);
        $signIn = DB::table('StudentSingIn')->insertGetId([
            'StudentClassID' => $course->ID,
            'StudentID' => $student->id,
            'TeacherID' => 99,
            'ClassSessionID' => $session->id,
            'Status' => 'attended',
            'SignInDT' => now(),
            'MDT' => now(),
        ]);

        DB::transaction(function () use ($course, $sessionDate) {
            CourseLeaveCascadeService::applyLeaveCascade($course->ID, $sessionDate);
        });

        $record->refresh();
        $this->assertNotNull($record->VoidedAt, 'Leave must void the learning record');
        $this->assertSame('一般請假', $record->VoidReason);

        $signInRow = DB::table('StudentSingIn')->where('id', $signIn)->first();
        $this->assertNotNull($signInRow->VoidedAt, 'Leave must void the sign-in row');
        $this->assertSame('一般請假', $signInRow->VoidReason);

        DB::transaction(function () use ($course, $sessionDate) {
            CourseLeaveCascadeService::undoLeaveCascade($course->ID, $sessionDate);
        });

        $record->refresh();
        $this->assertNull($record->VoidedAt, 'Undo must restore the learning record');
        $this->assertNull($record->VoidReason);

        $signInRow = DB::table('StudentSingIn')->where('id', $signIn)->first();
        $this->assertNull($signInRow->VoidedAt, 'Undo must restore the sign-in row');
        $this->assertNull($signInRow->VoidReason);

        // The whole point: a subsequent save for this session must find the
        // now-active record instead of colliding with an orphaned voided row
        // on the unique(ClassSessionID) index.
        $this->assertTrue(
            LearningRecord::where('ClassSessionID', $session->id)->active()->exists(),
            'Restored record must be visible to the normal ->active() lookup used before every save'
        );
        $this->assertTrue(
            StudentSignIn::where('ClassSessionID', $session->id)->active()->exists()
        );
    }
}
