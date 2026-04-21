<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubjectScopeResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 凍結在週一，確保 now()+7 依然是週一（符合 days_of_week=[1] ISO 週一）
        Carbon::setTestNow(Carbon::parse('2026-04-20 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * When Subject table has only English names (e.g. 'Math'), the resolver
     * must match correctly and NOT fall back to SubjectID=1.
     * The teacher's scope binding uses the same Subject.id, so no false-positive
     * scope_warning should appear.
     */
    public function test_enrollment_resolves_english_subject_name_without_false_positive_scope_warning(): void
    {
        $mathSubjectId = (int) DB::table('Subject')->where('Subject_Name', 'Math')->value('id');
        $this->assertGreaterThan(0, $mathSubjectId, 'Sanity: Math should exist in Subject table');

        $token = $this->createDirectorToken([1], 'dir-scope-res@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-scope-res@example.com');

        DB::table('teacher_subject_levels')->insert([
            'teacher_id' => $teacherId,
            'subject_id' => $mathSubjectId,
            'level' => 'junior',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $futureDate = now()->addDays(7)->toDateString();

        $pastDate = now()->subDays(3)->toDateString();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/enrollments', [
            'branch_id' => 1,
            'student' => [
                'name' => '科目解析測試生',
                'grade' => 'J2',
            ],
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'confirmed_dates' => [$pastDate],
            'future_dates' => [$futureDate],
            'days_of_week' => [1],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
            'total_classes' => 2,
        ]);

        $response->assertCreated();

        $studentClassId = (int) ($response->json('student_class_id') ?? 0);
        $this->assertTrue($studentClassId > 0);

        $sc = \App\Models\StudentClass::find($studentClassId);
        $this->assertEquals($mathSubjectId, (int) $sc->SubjectID, 'SubjectID should be the real Math id, not fallback 1');

        $this->assertNull(
            $response->json('scope_warning'),
            'No scope_warning expected when teacher has Math scope for junior level'
        );
    }

    /**
     * Same scenario but via the class-sessions/batch endpoint.
     */
    public function test_batch_store_resolves_english_subject_without_fallback_to_1(): void
    {
        $mathSubjectId = (int) DB::table('Subject')->where('Subject_Name', 'Math')->value('id');
        $this->assertGreaterThan(0, $mathSubjectId);

        $token = $this->createDirectorToken([1], 'dir-batch-scope@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-batch-scope@example.com');
        $student = Student::create([
            'name' => '批次科目測試',
            'CampusID' => 1,
            'ClassID' => 8,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        DB::table('teacher_subject_levels')->insert([
            'teacher_id' => $teacherId,
            'subject_id' => $mathSubjectId,
            'level' => 'junior',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $futureDate = now()->addDays(7)->toDateString();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 1,
            'confirmed_dates' => [],
            'future_dates' => [$futureDate],
            'days_of_week' => [1],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ]);

        $response->assertCreated();

        $studentClassId = (int) ($response->json('student_class_id') ?? 0);
        $sc = \App\Models\StudentClass::find($studentClassId);
        $this->assertEquals($mathSubjectId, (int) $sc->SubjectID, 'SubjectID should match the Subject table Math row');

        $this->assertNull(
            $response->json('scope_warning'),
            'No scope_warning when teacher scope matches'
        );
    }

    /**
     * When subject cannot be resolved at all, enrollment should return 422
     * instead of silently writing SubjectID=1.
     */
    public function test_enrollment_returns_422_for_unresolvable_subject(): void
    {
        $token = $this->createDirectorToken([1], 'dir-bad-subj@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-bad-subj@example.com');

        $futureDate = now()->addDays(7)->toDateString();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/enrollments', [
            'branch_id' => 1,
            'student' => [
                'name' => '找不到科目測試',
                'grade' => 'J1',
            ],
            'teacher_id' => $teacherId,
            'subject' => 'NonExistentSubject',
            'class_type' => 'one_on_one',
            'confirmed_dates' => [$futureDate],
            'future_dates' => [],
            'days_of_week' => [1],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
            'total_classes' => 1,
        ]);

        $response->assertStatus(422);
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0911000000',
            'MustChangePassword' => false,
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::firstOrCreate([
                'CampusID' => $campusId,
                'UserID' => $user->id,
            ], [
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
            'Name' => '鄭翔祐',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922111111',
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
}
