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
 * 守護 LearningRecord::scopeExcludeLeaveSessionPendingReview
 * 請假類堂次（leave / leave_adjusted / excused）上的 pending / changes_requested
 * 評量不應出現在待填／待審列表；已核准者仍可查詢。
 *
 * 對應 PRD「請假後不再要求填評量」FR-005。
 * 與前端 SmartCalendar / LearningRecordsPage 的 LEAVE_STATUSES 對齊。
 */
class LearningRecordLeaveExclusionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_lr_on_leave_session_is_excluded_from_index(): void
    {
        [$token, $teacherId, $studentId] = $this->bootActors('leave-hidden');
        $courseId = $this->seedCourse($studentId, $teacherId);

        $cs = $this->seedSession($courseId, 'leave');
        $record = $this->seedPendingLr($courseId, $teacherId, $cs);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?branch_id=1&per_page=50');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $record->id, $ids, '請假堂次的待審評量不應出現在列表');
    }

    public function test_pending_lr_on_leave_adjusted_session_is_excluded(): void
    {
        [$token, $teacherId, $studentId] = $this->bootActors('leave-adjusted-hidden');
        $courseId = $this->seedCourse($studentId, $teacherId);

        $cs = $this->seedSession($courseId, 'leave_adjusted');
        $record = $this->seedPendingLr($courseId, $teacherId, $cs);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?branch_id=1&per_page=50');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $record->id, $ids, 'leave_adjusted 堂次的待審評量不應出現在列表');
    }

    public function test_pending_lr_on_excused_session_is_excluded(): void
    {
        // 守護 FR-005：excused 納入請假類判斷（2026-04-22 修復，Bug #5）。
        [$token, $teacherId, $studentId] = $this->bootActors('excused-hidden');
        $courseId = $this->seedCourse($studentId, $teacherId);

        $cs = $this->seedSession($courseId, 'excused');
        $record = $this->seedPendingLr($courseId, $teacherId, $cs);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?branch_id=1&per_page=50');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $record->id, $ids, 'excused 堂次的待審評量不應出現在列表（FR-005 回歸守護）');
    }

    public function test_approved_lr_on_leave_session_is_still_visible(): void
    {
        [$token, $teacherId, $studentId] = $this->bootActors('leave-approved-visible');
        $courseId = $this->seedCourse($studentId, $teacherId);

        $cs = $this->seedSession($courseId, 'leave');
        $record = $this->seedPendingLr($courseId, $teacherId, $cs);
        $record->Status = 'approved';
        $record->ApprovedAt = now();
        $record->save();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?branch_id=1&per_page=50');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $record->id, $ids, '已核准評量即使堂次為請假狀態仍應可查');
    }

    public function test_pending_lr_on_attended_session_remains_visible_regression(): void
    {
        // 回歸：leave → attended 恢復路徑，評量必須仍可顯示／待審。
        [$token, $teacherId, $studentId] = $this->bootActors('attended-still-visible');
        $courseId = $this->seedCourse($studentId, $teacherId);

        $cs = $this->seedSession($courseId, 'attended');
        $record = $this->seedPendingLr($courseId, $teacherId, $cs);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?branch_id=1&per_page=50');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $record->id, $ids, '已上（attended）堂次的待審評量必須正常顯示（回歸守護）');
    }

    public function test_pending_lr_on_attended_session_remains_visible_after_course_stopped(): void
    {
        [$token, $teacherId, $studentId] = $this->bootActors('attended-stopped-visible');
        $courseId = $this->seedCourse($studentId, $teacherId);
        $cs = $this->seedSession($courseId, 'attended');
        $record = $this->seedPendingLr($courseId, $teacherId, $cs);

        StudentClass::where('ID', $courseId)->update(['Stop' => 1]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?branch_id=1&per_page=50');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $record->id, $ids, '已上課的歷史評量不可因課程後來停用而消失');
    }

    public function test_ensure_past_creates_pending_lr_for_attended_stopped_course(): void
    {
        [$token, $teacherId, $studentId] = $this->bootActors('ensure-attended-stopped');
        $courseId = $this->seedCourse($studentId, $teacherId);
        $cs = $this->seedSession($courseId, 'attended');

        StudentClass::where('ID', $courseId)->update(['Stop' => 1]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/ensure-past', ['branch_id' => 1]);

        $res->assertOk()
            ->assertJsonPath('created', 1);

        $this->assertDatabaseHas('LearningRecord', [
            'ClassSessionID' => $cs->id,
            'StudentClassID' => $courseId,
            'Status' => 'pending',
        ]);
    }

    // ── helpers ──

    /**
     * @return array{0:string,1:int,2:int} [directorToken, teacherId, studentId]
     */
    private function bootActors(string $slug): array
    {
        $directorToken = $this->createDirectorToken([1], "director-{$slug}@example.com");
        $teacherId = $this->createTeacher(1, "teacher-{$slug}@example.com");
        $student = $this->createStudent(1, "測試學生-{$slug}");
        return [$directorToken, $teacherId, (int) $student->id];
    }

    private function seedCourse(int $studentId, int $teacherId): int
    {
        $course = StudentClass::create([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-03-01',
            'EndDate' => null,
            'TotalHours' => 40,
            'Memo' => '請假排除評量測試',
            'Charge' => null,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 0,
            'Disconunt' => null,
            'Rate' => 500,
            'LearnTimeID' => null,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'SessionDuration' => 120,
            'ClassType' => 'one_on_one',
        ]);
        $this->assertTrue((int) $course->ID > 0);
        return (int) $course->ID;
    }

    private function seedSession(int $courseId, string $status): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => $status,
            'Note' => '',
        ]);
    }

    private function seedPendingLr(int $courseId, int $teacherId, ClassSession $cs): LearningRecord
    {
        return LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $cs->id,
            'TeacherID' => $teacherId,
            'Content' => '待審評量',
            'Status' => 'pending',
            'SessionDate' => $cs->SessionDate,
            'StartTime' => $cs->StartTime,
            'EndTime' => $cs->EndTime,
        ]);
    }

    /**
     * @param  array<int>  $campusIds
     */
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
}
