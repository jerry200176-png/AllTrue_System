<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bug #495 / in-app #125：請假 cascade 把 LR 標 VoidedAt + VoidReason='一般請假'，
 * 之後 ClassSession 被點為 attended（或調課回復），LR 的 VoidedAt 沒清，
 * 老師再來填評量就被 store() 永久 409 擋住。
 *
 * 修復：store() 對「VoidReason 屬系統 cascade 白名單 + CS 為 fillable 狀態」自動 resurrect 舊 LR。
 *
 * in-app #146 延伸：attended→scheduled 會以 VoidReason='由已上調整狀態' 作廢並沖回堂數；
 * 之後 scheduled→attended 不會還原該 LR，老師重填被原本只認 '一般請假' 的判斷擋住。
 * 修復：改用 SYSTEM_RESURRECTABLE_VOID_REASONS 白名單（含 '由已上調整狀態' 等系統作廢原因）。
 */
class LearningRecordVoidedResurrectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * #146：attended→scheduled→attended 後，'由已上調整狀態' 作廢的 LR 應可重填 resurrect。
     */
    public function test_resurrect_voided_when_session_attended_after_status_adjust_void_146(): void
    {
        [$token, $teacherId, $student, $course, $cs, $voidedLr] = $this->seedResurrectScenario(
            classSessionStatus: 'attended',
            voidReason: '由已上調整狀態',
            classSessionNote: 'revert-to-scheduled',
        );

        $resp = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records', [
            'StudentID' => $student->id,
            'ClassSessionID' => $cs->id,
            'TeacherID' => $teacherId,
            'Subject' => '化學',
            'SessionDate' => $cs->SessionDate,
            'StartTime' => $cs->StartTime,
            'EndTime' => $cs->EndTime,
            'Content' => '狀態調整後重新填寫',
            'Progress' => '本週進度',
        ]);

        $resp->assertOk();
        $resp->assertJsonPath('id', $voidedLr->id);

        $voidedLr->refresh();
        $this->assertNull($voidedLr->VoidedAt, '由已上調整狀態 void 應在重填時被清除');
        $this->assertNull($voidedLr->VoidReason);
        $this->assertSame('pending', $voidedLr->Status, 'resurrect 後應為 pending，扣堂走後續核准流程');
        $this->assertFalse((bool) $voidedLr->SessionDeducted, 'resurrect 不可直接標記已扣堂');

        $this->assertSame(
            1,
            (int) DB::table('LearningRecord')->where('ClassSessionID', $cs->id)->count(),
            'should not create a second LR row',
        );
    }

    public function test_resurrect_voided_when_session_attended(): void
    {
        [$token, $teacherId, $student, $course, $cs, $voidedLr] = $this->seedResurrectScenario(
            classSessionStatus: 'attended',
            voidReason: '一般請假',
            classSessionNote: 'leave',
        );

        $resp = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records', [
            'StudentID' => $student->id,
            'ClassSessionID' => $cs->id,
            'TeacherID' => $teacherId,
            'Subject' => '數學',
            'SessionDate' => $cs->SessionDate,
            'StartTime' => $cs->StartTime,
            'EndTime' => $cs->EndTime,
            'Content' => '重新填寫之評量內容',
            'Progress' => '本週進度',
            'HomeworkStatus' => '完成',
        ]);

        $resp->assertOk();
        $resp->assertJsonPath('id', $voidedLr->id);

        $voidedLr->refresh();
        $this->assertNull($voidedLr->VoidedAt, 'VoidedAt should be cleared');
        $this->assertNull($voidedLr->VoidReason, 'VoidReason should be cleared');
        $this->assertSame('pending', $voidedLr->Status);
        $this->assertSame('重新填寫之評量內容', $voidedLr->Content);
        $this->assertSame('本週進度', $voidedLr->Progress);
        $this->assertSame('完成', $voidedLr->HomeworkStatus);

        $this->assertSame(
            1,
            (int) DB::table('LearningRecord')->where('ClassSessionID', $cs->id)->count(),
            'should not create a second LR row',
        );
    }

    public function test_still_blocked_when_session_is_leave(): void
    {
        [$token, $teacherId, $student, $course, $cs, $voidedLr] = $this->seedResurrectScenario(
            classSessionStatus: 'leave',
            voidReason: '一般請假',
            classSessionNote: 'leave',
        );

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records', [
            'StudentID' => $student->id,
            'ClassSessionID' => $cs->id,
            'TeacherID' => $teacherId,
            'Subject' => '數學',
            'SessionDate' => $cs->SessionDate,
            'StartTime' => $cs->StartTime,
            'EndTime' => $cs->EndTime,
            'Content' => '不應通過',
        ])
            ->assertStatus(409)
            ->assertJsonPath('voided', true);

        $voidedLr->refresh();
        $this->assertNotNull($voidedLr->VoidedAt, 'leave-status should keep VoidedAt');
    }

    public function test_still_blocked_when_session_is_cancelled(): void
    {
        [$token, $teacherId, $student, $course, $cs, $voidedLr] = $this->seedResurrectScenario(
            classSessionStatus: 'cancelled',
            voidReason: '一般請假',
            classSessionNote: 'cancelled-duplicate-reschedule-placeholder',
        );

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records', [
            'StudentID' => $student->id,
            'ClassSessionID' => $cs->id,
            'TeacherID' => $teacherId,
            'Subject' => '數學',
            'SessionDate' => $cs->SessionDate,
            'StartTime' => $cs->StartTime,
            'EndTime' => $cs->EndTime,
            'Content' => '不應通過',
        ])
            ->assertStatus(409)
            ->assertJsonPath('voided', true);
    }

    public function test_manual_void_reason_is_not_resurrected_even_if_session_attended(): void
    {
        [$token, $teacherId, $student, $course, $cs, $voidedLr] = $this->seedResurrectScenario(
            classSessionStatus: 'attended',
            voidReason: '主任手動作廢',
            classSessionNote: '',
        );

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records', [
            'StudentID' => $student->id,
            'ClassSessionID' => $cs->id,
            'TeacherID' => $teacherId,
            'Subject' => '數學',
            'SessionDate' => $cs->SessionDate,
            'StartTime' => $cs->StartTime,
            'EndTime' => $cs->EndTime,
            'Content' => '不應 resurrect',
        ])
            ->assertStatus(409)
            ->assertJsonPath('voided', true)
            ->assertJsonPath('existing_id', $voidedLr->id);

        $voidedLr->refresh();
        $this->assertNotNull($voidedLr->VoidedAt, 'manual void should be preserved');
    }

    /**
     * @return array{0:string,1:int,2:Student,3:StudentClass,4:ClassSession,5:LearningRecord}
     */
    private function seedResurrectScenario(
        string $classSessionStatus,
        string $voidReason,
        string $classSessionNote,
    ): array {
        static $seq = 0;
        $seq++;

        $token = $this->createDirectorToken([1], 'director-resurrect-' . $seq . '@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-resurrect-' . $seq . '@example.com');
        $student = $this->createStudent(1, '評量復原-' . $seq);

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '23:00',
        ]);
        $courseId = (int) $course->ID;

        $pastDate = now()->subDay()->toDateString();
        $cs = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $pastDate,
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => $classSessionStatus,
            'Note' => $classSessionNote,
        ]);

        $voidedLr = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $cs->id,
            'TeacherID' => $teacherId,
            'Content' => '舊作廢內容',
            'Status' => 'pending',
            'SessionDate' => $cs->SessionDate,
            'StartTime' => $cs->StartTime,
            'EndTime' => $cs->EndTime,
            'VoidedAt' => now()->subDays(3),
            'VoidReason' => $voidReason,
        ]);

        return [$token, $teacherId, $student, $course, $cs, $voidedLr];
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
            'MustChangePassword' => false,
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName,
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922000000',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        return (int) $teacher->id;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createStudentClassForTest(int $studentId, int $teacherId, array $overrides = []): StudentClass
    {
        $o = array_merge([
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'rate_per_30min' => 500,
            'duration_hours' => 2,
            'payment_type' => 'session',
            'sessions_purchased' => 8,
            'sessions_used' => 0,
            'remaining_sessions' => 8,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '16:00',
            'Memo' => '測試課程',
        ], $overrides);

        return StudentClass::create([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => $o['first_class_date'],
            'EndDate' => null,
            'TotalHours' => 40,
            'Memo' => $o['Memo'],
            'Charge' => null,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 0,
            'Disconunt' => null,
            'Rate' => (float) $o['rate_per_30min'],
            'LearnTimeID' => null,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => $o['schedule_mode'] ?? 'count',
            'SessionCount' => (int) $o['sessions_purchased'],
            'RemainingSessions' => (int) $o['remaining_sessions'],
            'UsedSessions' => (int) $o['sessions_used'],
            'SessionDuration' => max(30, (int) $o['duration_hours'] * 60),
            'ClassType' => $o['class_type'],
        ]);
    }
}
