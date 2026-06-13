<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #805 防再犯：建課時偵測「同學生 + 同科目 + 同老師 + 日期區間重疊」的進行中課程。
 *
 * 對應 in-app #161 / 復發家族 F1 的「重疊續報」變體：續報新期起始日早於舊期結束，
 * 兩筆都 active，重疊區間每週各生成一堂 → 點名重複。建課端先擋（force 可覆蓋）。
 */
class OverlappingCourseGuardTest extends TestCase
{
    use RefreshDatabase;

    private function batchPayload(int $studentId, int $teacherId, array $dates, string $classType = 'one_on_one'): array
    {
        return [
            'branch_id' => 1,
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => $classType,
            'total_classes' => count($dates),
            'confirmed_dates' => [],
            'future_dates' => [],
            'session_plan' => array_map(fn ($d) => [
                'session_date' => $d,
                'start_time' => '16:00',
                'kind' => 'future',
                'subject' => 'Math',
            ], $dates),
            'days_of_week' => [3],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
            'course_start_date' => $dates[0],
        ];
    }

    public function test_overlapping_same_teacher_subject_course_is_blocked_and_force_overrides(): void
    {
        $token = $this->createUserToken('A', [1], 'dir-ovl@example.com');
        $teacherId = $this->createTeacher(1, 'teach-ovl@example.com');
        $student = $this->createStudent(1, '重疊測試生');

        // 第一期：未來 8~11 週的週三（one_on_one）
        $base = Carbon::now()->addWeeks(8)->next(Carbon::WEDNESDAY);
        $first = [];
        $cur = $base->copy();
        for ($i = 0; $i < 4; $i++) { $first[] = $cur->toDateString(); $cur->addWeek(); }

        $res1 = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', $this->batchPayload($student->id, $teacherId, $first, 'one_on_one'));
        $res1->assertCreated();

        // 第二期：與第一期重疊（10~13 週），同生同科同師，但「不同 class_type」→
        // 既有的 duplicate_active_course（同型）不會觸發，必須由 #805 的重疊偵測擋下。
        $second = [];
        $cur = $base->copy()->addWeeks(2);
        for ($i = 0; $i < 4; $i++) { $second[] = $cur->toDateString(); $cur->addWeek(); }

        $res2 = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', $this->batchPayload($student->id, $teacherId, $second, 'one_on_two'));
        $res2->assertStatus(409)->assertJson(['code' => 'overlapping_active_course']);

        // 勾選強制建立 → 放行
        $forced = $this->batchPayload($student->id, $teacherId, $second, 'one_on_two');
        $forced['force'] = true;
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', $forced)
            ->assertCreated();
    }

    public function test_non_overlapping_renewal_after_first_ends_is_allowed(): void
    {
        $token = $this->createUserToken('A', [1], 'dir-ovl2@example.com');
        $teacherId = $this->createTeacher(1, 'teach-ovl2@example.com');
        $student = $this->createStudent(1, '接續測試生');

        $base = Carbon::now()->addWeeks(8)->next(Carbon::WEDNESDAY);
        $first = [];
        $cur = $base->copy();
        for ($i = 0; $i < 4; $i++) { $first[] = $cur->toDateString(); $cur->addWeek(); }

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', $this->batchPayload($student->id, $teacherId, $first, 'one_on_one'))
            ->assertCreated();

        // 第二期接在第一期之後（無重疊），不同 class_type → 兩道 guard 都不應觸發
        $second = [];
        $cur = $base->copy()->addWeeks(5);
        for ($i = 0; $i < 4; $i++) { $second[] = $cur->toDateString(); $cur->addWeek(); }

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', $this->batchPayload($student->id, $teacherId, $second, 'one_on_two'))
            ->assertCreated();
    }

    // ── helpers (mirror CourseStartDateTest) ──────────────────────────
    private function createUserToken(string $type, array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => $type === 'T' ? '老師測試' : '主任測試',
            'PSW' => 'secret',
            'type' => $type,
            'phone' => '0911000000',
            'MustChangePassword' => false,
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::firstOrCreate(['CampusID' => $campusId, 'UserID' => $user->id], ['Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function createTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName, 'Name' => '老師測試', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922111111', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        return (int) $teacher->id;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name, 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
    }
}
