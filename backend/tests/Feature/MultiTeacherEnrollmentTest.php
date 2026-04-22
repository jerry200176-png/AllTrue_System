<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 多師排課（allow_multi_teacher）功能回歸守護。
 *
 * 確保：
 * - FR-001：單師正常排課不受影響（回歸）
 * - FR-002：不同 day_time_slot 帶不同 teacher_id → 各別建立 StudentClass（按 subject+teacher 分組）
 * - FR-003：allow_multi_teacher=true 抑制 dual_teacher_warning
 * - FR-004：day_time_slots.*.teacher_id 驗證（非存在 User → 422）
 * - FR-005：多師排課建立的 StudentClass.TeacherID 正確對應每個老師
 * - FR-006：allow_multi_teacher 未帶 true 且出現不同老師 → 回傳 dual_teacher_warning（守護警示仍存在）
 */
class MultiTeacherEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-22 10:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── FR-001：單師正常排課回歸 ──────────────────────────────────────

    public function test_single_teacher_enrollment_still_works_unchanged(): void
    {
        $token = $this->makeDirectorToken();
        $teacher = $this->makeTeacher('single-teacher-mt@test.com');
        $student = $this->makeStudent('單師回歸');

        $futureWed = $this->nextWeekday(3);
        $futureFri = $this->nextWeekday(5);

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', [
                'branch_id'         => 1,
                'student_id'        => $student->id,
                'teacher_id'        => $teacher->id,
                'subject'           => 'Math',
                'class_type'        => 'one_on_one',
                'total_classes'     => 2,
                'confirmed_dates'   => [],
                'future_dates'      => [$futureWed, $futureFri],
                'days_of_week'      => [3, 5],
                'start_time'        => '16:00',
                'duration_minutes'  => 120,
                'price_per_session' => 800,
                'payment_type'      => 'session',
            ]);

        $res->assertCreated();

        // 單師：只建立 1 個 StudentClass
        $scCount = StudentClass::where('StudentID', $student->id)->count();
        $this->assertSame(1, $scCount, '單師排課應只建立一個 StudentClass');

        $sc = StudentClass::where('StudentID', $student->id)->first();
        $this->assertSame((int) $teacher->id, (int) $sc->TeacherID, '單師排課的 TeacherID 應正確');
        $this->assertSame(2, (int) $sc->SessionCount, 'SessionCount 應等於 total_classes');
    }

    // ── FR-002：兩位老師分別帶不同 slot → 建立兩個 StudentClass ──────

    public function test_two_teachers_on_different_slots_creates_two_student_classes(): void
    {
        $token    = $this->makeDirectorToken('dir-multi-a@test.com');
        $teacher1 = $this->makeTeacher('teacher-multi-1@test.com');
        $teacher2 = $this->makeTeacher('teacher-multi-2@test.com');
        $student  = $this->makeStudent('多師學生A');

        $futureWed = $this->nextWeekday(3);
        $futureSun = $this->nextWeekday(7);

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', [
                'branch_id'          => 1,
                'student_id'         => $student->id,
                'teacher_id'         => $teacher1->id,
                'subject'            => 'English',
                'class_type'         => 'one_on_one',
                'total_classes'      => 2,
                'confirmed_dates'    => [],
                'future_dates'       => [$futureWed, $futureSun],
                'days_of_week'       => [3, 7],
                'start_time'         => '16:00',
                'duration_minutes'   => 120,
                'price_per_session'  => 800,
                'payment_type'       => 'session',
                'allow_multi_teacher' => true,
                'day_time_slots'     => [
                    ['day' => 3, 'start_time' => '16:00', 'duration_minutes' => 120, 'teacher_id' => $teacher1->id],
                    ['day' => 7, 'start_time' => '16:00', 'duration_minutes' => 120, 'teacher_id' => $teacher2->id],
                ],
            ]);

        $res->assertCreated();

        // 兩位老師 → 兩個 StudentClass
        $studentClasses = StudentClass::where('StudentID', $student->id)->get();
        $this->assertCount(2, $studentClasses, '兩位老師各自的 slot 應各建立一個 StudentClass');

        $teacherIds = $studentClasses->pluck('TeacherID')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $expectedIds = collect([$teacher1->id, $teacher2->id])->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame($expectedIds, $teacherIds, '兩個 StudentClass 的 TeacherID 應分別對應兩位老師');
    }

    // ── FR-003：allow_multi_teacher=true 抑制 dual_teacher_warning ──────

    public function test_allow_multi_teacher_suppresses_dual_teacher_warning(): void
    {
        $token    = $this->makeDirectorToken('dir-multi-suppress@test.com');
        $teacher1 = $this->makeTeacher('teacher-supp-1@test.com');
        $teacher2 = $this->makeTeacher('teacher-supp-2@test.com');
        $student  = $this->makeStudent('警示抑制學生');

        $futureWed = $this->nextWeekday(3);
        $futureSun = $this->nextWeekday(7);

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', [
                'branch_id'           => 1,
                'student_id'          => $student->id,
                'teacher_id'          => $teacher1->id,
                'subject'             => 'Math',
                'class_type'          => 'one_on_one',
                'total_classes'       => 2,
                'confirmed_dates'     => [],
                'future_dates'        => [$futureWed, $futureSun],
                'days_of_week'        => [3, 7],
                'start_time'          => '16:00',
                'duration_minutes'    => 120,
                'price_per_session'   => 800,
                'payment_type'        => 'session',
                'allow_multi_teacher' => true,
                'day_time_slots'      => [
                    ['day' => 3, 'start_time' => '16:00', 'duration_minutes' => 120, 'teacher_id' => $teacher1->id],
                    ['day' => 7, 'start_time' => '16:00', 'duration_minutes' => 120, 'teacher_id' => $teacher2->id],
                ],
            ]);

        $res->assertCreated();

        $warnings = $res->json('warnings') ?? [];
        $hasDualWarning = collect($warnings)->contains(fn ($w) => str_contains((string) ($w['type'] ?? $w), 'dual_teacher'));
        $this->assertFalse($hasDualWarning, 'allow_multi_teacher=true 時不應出現 dual_teacher_warning');
    }

    // ── FR-004：teacher_id 非存在 User → 422 ──────────────────────────

    public function test_invalid_slot_teacher_id_returns_422(): void
    {
        $token   = $this->makeDirectorToken('dir-multi-invalid@test.com');
        $teacher = $this->makeTeacher('teacher-valid-mt@test.com');
        $student = $this->makeStudent('驗證失敗學生');

        $futureWed = $this->nextWeekday(3);

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', [
                'branch_id'           => 1,
                'student_id'          => $student->id,
                'teacher_id'          => $teacher->id,
                'subject'             => 'Math',
                'class_type'          => 'one_on_one',
                'total_classes'       => 1,
                'confirmed_dates'     => [],
                'future_dates'        => [$futureWed],
                'days_of_week'        => [3],
                'start_time'          => '16:00',
                'duration_minutes'    => 120,
                'price_per_session'   => 800,
                'payment_type'        => 'session',
                'allow_multi_teacher' => true,
                'day_time_slots'      => [
                    ['day' => 3, 'start_time' => '16:00', 'duration_minutes' => 120, 'teacher_id' => 999999],
                ],
            ]);

        $res->assertStatus(422);
    }

    // ── FR-005：多師 StudentClass.TeacherID 逐一對應每個老師 ───────────

    public function test_each_student_class_has_correct_teacher_id_for_multi_teacher(): void
    {
        $token    = $this->makeDirectorToken('dir-multi-id@test.com');
        $teacher1 = $this->makeTeacher('teacher-id-1@test.com');
        $teacher2 = $this->makeTeacher('teacher-id-2@test.com');
        $student  = $this->makeStudent('TeacherID對應學生');

        $futureWed = $this->nextWeekday(3);
        $futureSun = $this->nextWeekday(7);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', [
                'branch_id'           => 1,
                'student_id'          => $student->id,
                'teacher_id'          => $teacher1->id,
                'subject'             => 'Chemistry',
                'class_type'          => 'one_on_one',
                'total_classes'       => 2,
                'confirmed_dates'     => [],
                'future_dates'        => [$futureWed, $futureSun],
                'days_of_week'        => [3, 7],
                'start_time'          => '16:00',
                'duration_minutes'    => 120,
                'price_per_session'   => 800,
                'payment_type'        => 'session',
                'allow_multi_teacher' => true,
                'day_time_slots'      => [
                    ['day' => 3, 'start_time' => '16:00', 'duration_minutes' => 120, 'teacher_id' => $teacher1->id],
                    ['day' => 7, 'start_time' => '16:00', 'duration_minutes' => 120, 'teacher_id' => $teacher2->id],
                ],
            ])->assertCreated();

        $sc1 = StudentClass::where('StudentID', $student->id)
            ->where('TeacherID', $teacher1->id)->first();
        $sc2 = StudentClass::where('StudentID', $student->id)
            ->where('TeacherID', $teacher2->id)->first();

        $this->assertNotNull($sc1, 'teacher1 的 StudentClass 應存在');
        $this->assertNotNull($sc2, 'teacher2 的 StudentClass 應存在');

        // 各自的 ClassSession 應帶正確 teacher（透過 StudentClassID 對應）
        $sessionsOfSc1 = DB::table('ClassSession')->where('StudentClassID', $sc1->ID)->count();
        $sessionsOfSc2 = DB::table('ClassSession')->where('StudentClassID', $sc2->ID)->count();
        $this->assertGreaterThan(0, $sessionsOfSc1, 'teacher1 的 StudentClass 應有排課堂次');
        $this->assertGreaterThan(0, $sessionsOfSc2, 'teacher2 的 StudentClass 應有排課堂次');
    }

    // ── FR-006：未帶 allow_multi_teacher 時，兩 slot 用不同老師仍產生警示 ──

    public function test_missing_allow_multi_teacher_flag_still_produces_warning(): void
    {
        $token    = $this->makeDirectorToken('dir-multi-warn@test.com');
        $teacher1 = $this->makeTeacher('teacher-warn-1@test.com');
        $teacher2 = $this->makeTeacher('teacher-warn-2@test.com');
        $student  = $this->makeStudent('警示測試學生');

        $futureWed = $this->nextWeekday(3);
        $futureSun = $this->nextWeekday(7);

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', [
                'branch_id'          => 1,
                'student_id'         => $student->id,
                'teacher_id'         => $teacher1->id,
                'subject'            => 'Physics',
                'class_type'         => 'one_on_one',
                'total_classes'      => 2,
                'confirmed_dates'    => [],
                'future_dates'       => [$futureWed, $futureSun],
                'days_of_week'       => [3, 7],
                'start_time'         => '16:00',
                'duration_minutes'   => 120,
                'price_per_session'  => 800,
                'payment_type'       => 'session',
                // allow_multi_teacher 故意不帶
                'day_time_slots'     => [
                    ['day' => 3, 'start_time' => '16:00', 'duration_minutes' => 120, 'teacher_id' => $teacher1->id],
                    ['day' => 7, 'start_time' => '16:00', 'duration_minutes' => 120, 'teacher_id' => $teacher2->id],
                ],
            ]);

        // 仍應成功建立（警示不阻擋建立），但 response 中有 dual_teacher_warning
        $res->assertCreated();
        $warnings = $res->json('warnings') ?? [];
        $hasDualWarning = collect($warnings)->contains(
            fn ($w) => str_contains((string) ($w['type'] ?? $w), 'dual_teacher')
        );
        $this->assertTrue($hasDualWarning, '未帶 allow_multi_teacher 時應產生 dual_teacher_warning');
    }

    // ── FR-007：三位老師（N師）每師各一個 StudentClass ───────────────

    public function test_three_teachers_on_three_slots_creates_three_student_classes(): void
    {
        $token    = $this->makeDirectorToken('dir-triple@test.com');
        $teacher1 = $this->makeTeacher('teacher-tri-1@test.com');
        $teacher2 = $this->makeTeacher('teacher-tri-2@test.com');
        $teacher3 = $this->makeTeacher('teacher-tri-3@test.com');
        $student  = $this->makeStudent('三師學生');

        $futureMon = $this->nextWeekday(1);
        $futureWed = $this->nextWeekday(3);
        $futureFri = $this->nextWeekday(5);

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', [
                'branch_id'           => 1,
                'student_id'          => $student->id,
                'teacher_id'          => $teacher1->id,
                'subject'             => 'Biology',
                'class_type'          => 'one_on_one',
                'total_classes'       => 3,
                'confirmed_dates'     => [],
                'future_dates'        => [$futureMon, $futureWed, $futureFri],
                'days_of_week'        => [1, 3, 5],
                'start_time'          => '16:00',
                'duration_minutes'    => 60,
                'price_per_session'   => 600,
                'payment_type'        => 'session',
                'allow_multi_teacher' => true,
                'day_time_slots'      => [
                    ['day' => 1, 'start_time' => '16:00', 'duration_minutes' => 60, 'teacher_id' => $teacher1->id],
                    ['day' => 3, 'start_time' => '16:00', 'duration_minutes' => 60, 'teacher_id' => $teacher2->id],
                    ['day' => 5, 'start_time' => '16:00', 'duration_minutes' => 60, 'teacher_id' => $teacher3->id],
                ],
            ]);

        $res->assertCreated();

        $studentClasses = StudentClass::where('StudentID', $student->id)->get();
        $this->assertCount(3, $studentClasses, '三位老師各一 slot 應建立 3 個 StudentClass');

        $teacherIds = $studentClasses->pluck('TeacherID')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $expected   = collect([$teacher1->id, $teacher2->id, $teacher3->id])
            ->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame($expected, $teacherIds, '三個 StudentClass 各自對應正確老師');

        $warnings = $res->json('warnings') ?? [];
        $hasDualWarning = collect($warnings)->contains(fn ($w) => str_contains((string) ($w['type'] ?? $w), 'dual_teacher'));
        $this->assertFalse($hasDualWarning, 'allow_multi_teacher=true 三師時不應出現 dual_teacher_warning');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function makeDirectorToken(string $loginName = 'director-mt@test.com'): string
    {
        $user = User::create([
            'LoginName'          => $loginName,
            'Name'               => '主任',
            'PSW'                => 'secret',
            'type'               => 'A',
            'phone'              => 900000000,
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => 1,
            'UserID'   => $user->id,
            'Admin'    => 1,
            'Approved' => 1,
        ]);

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function makeTeacher(string $loginName): User
    {
        $teacher = User::create([
            'LoginName'          => $loginName,
            'Name'               => '老師' . uniqid(),
            'PSW'                => 'secret',
            'type'               => 'T',
            'phone'              => 911000000,
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => 1,
            'UserID'   => $teacher->id,
            'Admin'    => 0,
            'Approved' => 1,
        ]);

        return $teacher;
    }

    private function makeStudent(string $name): Student
    {
        return Student::create([
            'name'         => $name,
            'CampusID'     => 1,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }

    /** 取下一個符合 ISO weekday（1=週一 … 7=週日）的未來日期 */
    private function nextWeekday(int $isoDow): string
    {
        $d = Carbon::now()->addDay();
        for ($i = 0; $i < 14; $i++) {
            if ((int) $d->dayOfWeekIso === $isoDow) {
                return $d->toDateString();
            }
            $d->addDay();
        }
        throw new \RuntimeException("Cannot find weekday {$isoDow} in next 14 days");
    }
}
