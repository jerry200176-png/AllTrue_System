<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
use App\Services\CourseLeaveCascadeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #170 self-healing guard — production incident regression.
 *
 * voidLiveArtifactsForLeave() only guards leave-transition code paths going forward: it does
 * nothing for a ClassSession that was already left in status=leave with a live LearningRecord
 * by a call that happened before that guard existed. This was proven in production on
 * 2026-08-07/08: after CourseLeaveCascadeService::voidLiveArtifactsForLeave() was extracted and
 * wired into ExceptionWorkflowController::confirmCandidate(), the pre-existing bad row
 * (ClassSession #15635 / LearningRecord #11828) still tripped bugs:verify-reproductions'
 * leave_session_with_live_learning_record condition every night, because the code fix never
 * touches data that was already corrupted before deploy.
 *
 * learning-records:void-stale-leave is the nightly sweep that self-heals any such row
 * regardless of how it got there — idempotent, read-safe except for the void itself.
 */
class LearningRecordVoidStaleLeaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_sweep_voids_live_learning_record_and_signin_on_leave_session(): void
    {
        $campus = Campus::factory()->create();
        $student = Student::create([
            'name' => '請假殘留評量測試生', 'CampusID' => $campus->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $scId = DB::table('StudentClass')->insertGetId($this->scRow((int) $student->id));

        $leaveSession = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId, 'SessionDate' => '2026-07-04', 'StartTime' => '15:00',
            'EndTime' => '17:00', 'Status' => 'leave',
        ]);
        $lrId = DB::table('LearningRecord')->insertGetId([
            'StudentClassID' => $scId,
            'ClassSessionID' => $leaveSession,
            'TeacherID' => 1,
            'Content' => '',
            'Subject' => '數學',
            'SessionDate' => '2026-07-04',
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $signInId = DB::table('StudentSingIn')->insertGetId([
            'StudentID' => $student->id,
            'StudentClassID' => $scId,
            'ClassSessionID' => $leaveSession,
            'SignInDT' => now(),
        ]);

        Artisan::call('learning-records:void-stale-leave');

        $lr = DB::table('LearningRecord')->where('id', $lrId)->first();
        $this->assertNotNull($lr->VoidedAt, 'live LearningRecord on a leave session must be voided');
        $this->assertSame(CourseLeaveCascadeService::VOID_REASON_LEAVE, $lr->VoidReason);

        $signIn = DB::table('StudentSingIn')->where('id', $signInId)->first();
        $this->assertNotNull($signIn->VoidedAt, 'live StudentSingIn (attendance) on a leave session must be voided');
    }

    public function test_sweep_is_idempotent_and_does_not_touch_already_voided_rows(): void
    {
        $campus = Campus::factory()->create();
        $student = Student::create([
            'name' => '請假冪等測試生', 'CampusID' => $campus->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $scId = DB::table('StudentClass')->insertGetId($this->scRow((int) $student->id));

        $leaveSession = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId, 'SessionDate' => '2026-07-05', 'StartTime' => '15:00',
            'EndTime' => '17:00', 'Status' => 'leave',
        ]);
        $lrId = DB::table('LearningRecord')->insertGetId([
            'StudentClassID' => $scId,
            'ClassSessionID' => $leaveSession,
            'TeacherID' => 1,
            'Content' => '',
            'Subject' => '數學',
            'SessionDate' => '2026-07-05',
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('learning-records:void-stale-leave');
        $firstVoidedAt = DB::table('LearningRecord')->where('id', $lrId)->value('VoidedAt');
        $this->assertNotNull($firstVoidedAt);

        Artisan::call('learning-records:void-stale-leave');
        $this->assertSame(
            $firstVoidedAt,
            DB::table('LearningRecord')->where('id', $lrId)->value('VoidedAt'),
            'second run must be a no-op for an already-voided row'
        );
    }

    public function test_sweep_does_not_touch_live_learning_record_on_non_leave_session(): void
    {
        $campus = Campus::factory()->create();
        $student = Student::create([
            'name' => '正常評量測試生', 'CampusID' => $campus->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $scId = DB::table('StudentClass')->insertGetId($this->scRow((int) $student->id));

        $attendedSession = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId, 'SessionDate' => '2026-07-04', 'StartTime' => '15:00',
            'EndTime' => '17:00', 'Status' => 'attended',
        ]);
        $lrId = DB::table('LearningRecord')->insertGetId([
            'StudentClassID' => $scId,
            'ClassSessionID' => $attendedSession,
            'TeacherID' => 1,
            'Content' => '正常評量內容',
            'Subject' => '數學',
            'SessionDate' => '2026-07-04',
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('learning-records:void-stale-leave');

        $this->assertNull(
            DB::table('LearningRecord')->where('id', $lrId)->value('VoidedAt'),
            'a live LearningRecord on a non-leave session must never be touched'
        );
    }

    /** @return array<string,mixed> */
    private function scRow(int $studentId): array
    {
        return [
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => now(), 'TotalHours' => 8, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
        ];
    }
}
