<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R55 fix: ClassSessionController::restoreVoidedLearningRecord() (the automatic
 * leave -> attended restore path) used to resurrect ANY voided LearningRecord on
 * that transition, regardless of VoidReason — unlike LearningRecordController::store()'s
 * reactive resurrect, which always checked a whitelist. A manually-voided record
 * (director decision, non-cascade reason) sitting on a session that happened to be
 * 'leave' would have been silently brought back to life.
 *
 * Both paths now share LearningRecordResurrectionPolicy::isEligibleForResurrect().
 */
class ClassSessionRestoreVoidedLearningRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_leave_to_attended_resurrects_system_cascade_voided_record(): void
    {
        [$token, $cs, $voidedLr] = $this->seedLeaveScenario('一般請假');

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/class-sessions/{$cs->id}", [
            'status' => 'attended',
        ])->assertOk();

        $voidedLr->refresh();
        $this->assertNull($voidedLr->VoidedAt, '系統 cascade（一般請假）作廢的 LR，leave→attended 時應自動復活');
        $this->assertNull($voidedLr->VoidReason);
        $this->assertSame('pending', $voidedLr->Status);
    }

    public function test_leave_to_attended_does_not_resurrect_manually_voided_record(): void
    {
        [$token, $cs, $voidedLr] = $this->seedLeaveScenario('主任手動作廢');

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/class-sessions/{$cs->id}", [
            'status' => 'attended',
        ])->assertOk();

        $voidedLr->refresh();
        $this->assertNotNull(
            $voidedLr->VoidedAt,
            '人工作廢（非系統白名單原因）的 LR，不可因 leave→attended 轉換被靜默復活——這是 R55 修復前的實際缺口。'
        );
        $this->assertSame('主任手動作廢', $voidedLr->VoidReason);
    }

    /**
     * 張韙 2026-08-14：attended → scheduled（VoidReason=由已上調整狀態）→ attended
     * 不可留下作廢評量，否則主任看得到歷史、老師端沒有可填草稿。
     */
    public function test_attended_to_scheduled_to_attended_resurrects_status_adjust_void(): void
    {
        [$token, $cs, $lr] = $this->seedAttendedScenario();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/class-sessions/{$cs->id}", [
            'status' => 'scheduled',
        ])->assertOk();

        $lr->refresh();
        $this->assertNotNull($lr->VoidedAt);
        $this->assertSame('由已上調整狀態', $lr->VoidReason);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/class-sessions/{$cs->id}", [
            'status' => 'attended',
        ])->assertOk();

        $lr->refresh();
        $this->assertNull($lr->VoidedAt, '由已上調整狀態作廢後再標到班，評量草稿必須自動復活');
        $this->assertNull($lr->VoidReason);
        $this->assertSame('pending', $lr->Status);
        $this->assertSame('attended', strtolower((string) $cs->fresh()->Status));
    }

    /**
     * in-app bug (木柵分校／吳艾潼, 2026-08-20): admin flips 到班→請假→已上 via the
     * generic leave->attended fallback path (not the dedicated scheduled->attended
     * branch). ClassSession.Status and the LearningRecord both end up correct, but
     * the StudentSignIn row created while marking leave stays at Status='leave',
     * VoidedAt=null — scopeExcludeLeaveSessionPendingReview() treats that stale row
     * as authoritative and hides the (correctly restored) pending LearningRecord
     * from every eval list/panel, so the teacher can't find anything to fill in.
     */
    public function test_leave_to_attended_syncs_stale_leave_student_sign_in_status(): void
    {
        [$token, $cs, $voidedLr] = $this->seedLeaveScenario('一般請假');

        $signIn = StudentSignIn::create([
            'StudentID' => $cs->studentClass->StudentID,
            'StudentClassID' => $cs->StudentClassID,
            'ClassSessionID' => $cs->id,
            'CampusID' => 1,
            'SignInDT' => $cs->SessionDate . ' ' . $cs->StartTime,
            'SignOutDT' => $cs->SessionDate . ' ' . $cs->EndTime,
            'Status' => 'leave',
            'MDT' => now(),
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/class-sessions/{$cs->id}", [
            'status' => 'attended',
        ])->assertOk();

        $voidedLr->refresh();
        $this->assertNull($voidedLr->VoidedAt, '評量草稿應照常自動復活');

        $signIn->refresh();
        $this->assertSame(
            'present',
            $signIn->Status,
            '殘留的請假簽到記錄必須跟著同步為到班，否則評量會被 scopeExcludeLeaveSessionPendingReview 誤擋'
        );
        $this->assertNull($signIn->VoidedAt);
    }

    /**
     * @return array{0:string,1:ClassSession,2:LearningRecord}
     */
    private function seedLeaveScenario(string $voidReason): array
    {
        static $seq = 0;
        $seq++;

        $director = User::create([
            'LoginName' => 'restore-lr-' . $seq . '@example.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '091' . random_int(1000000, 9999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $director->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $director->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        $teacher = User::create([
            'LoginName' => 'restore-lr-t-' . $seq . '@example.com',
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '092' . random_int(1000000, 9999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        $student = Student::create([
            'name' => 'R55 復原測試-' . $seq,
            'CampusID' => 1,
            'ClassID' => 1,
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
            'StartDate' => '2026-03-01',
            'TotalHours' => 20,
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'Charge' => 1600,
            'Pay' => 16000,
            'Paid' => 0,
            'Rate' => 800,
            'Stop' => 0,
            'MDate' => now(),
            'ScheduleMode' => 'count',
            'week' => 1,
            'time' => '16:00:00',
            'Memo' => '',
        ]);

        $pastDate = now()->subDay()->toDateString();
        $cs = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => $pastDate,
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'leave',
            'Note' => 'leave',
        ]);

        $voidedLr = LearningRecord::create([
            'StudentClassID' => $course->ID,
            'ClassSessionID' => $cs->id,
            'TeacherID' => $teacher->id,
            'Content' => '請假前舊內容',
            'Status' => 'pending',
            'SessionDate' => $cs->SessionDate,
            'StartTime' => $cs->StartTime,
            'EndTime' => $cs->EndTime,
            'VoidedAt' => now()->subDays(2),
            'VoidReason' => $voidReason,
        ]);

        return [$token, $cs, $voidedLr];
    }

    /**
     * @return array{0:string,1:ClassSession,2:LearningRecord}
     */
    private function seedAttendedScenario(): array
    {
        [$token, $cs, $lr] = $this->seedLeaveScenario('placeholder');
        $cs->Status = 'attended';
        $cs->Note = '';
        $cs->save();
        $lr->VoidedAt = null;
        $lr->VoidReason = null;
        $lr->Content = '到班後評量草稿';
        $lr->save();

        return [$token, $cs, $lr];
    }
}
