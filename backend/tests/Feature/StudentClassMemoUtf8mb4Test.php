<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * #1378 / F6 extension — StudentClass.Memo must round-trip utf8mb4 (emoji + CJK + newlines).
 *
 * Production Sentry: Incorrect string value for 📅 in Memo when column charset is utf8mb3.
 * CI DB is created as utf8mb4; migration 2026_07_22_130000 is idempotent insurance + schema lock.
 */
class StudentClassMemoUtf8mb4Test extends TestCase
{
    use RefreshDatabase;

    private const MEMO_RICH = "📅 試聽時間：\n7/24（五）14:00–16:00\n（暑假學校半天，所以下午可以上課。）\n\n📅 開學後常態課預計安排：\n每週四 20:00–22:00";

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-22 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_memo_column_is_utf8mb4_after_migrations(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('charset assertion requires MySQL');
        }

        $this->assertTrue(Schema::hasColumn('StudentClass', 'Memo'));

        $row = DB::selectOne(
            'SELECT CHARACTER_SET_NAME AS charset_name, COLLATION_NAME AS collation_name
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), 'StudentClass', 'Memo']
        );

        $this->assertSame('utf8mb4', $row->charset_name);
        $this->assertSame('utf8mb4_unicode_ci', $row->collation_name);
    }

    public function test_enrollment_batch_persists_emoji_chinese_newlines_punctuation_in_memo(): void
    {
        $token = $this->createDirectorToken([1], 'dir-memo-utf8@example.com');
        $teacherId = $this->createTeacher(1, 'teach-memo-utf8@example.com');
        $student = $this->createStudent(1, '備註字元測試生');

        $date = Carbon::now()->addWeeks(2)->next(Carbon::WEDNESDAY)->toDateString();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'trial',
            'total_classes' => 1,
            'confirmed_dates' => [],
            'future_dates' => [],
            'session_plan' => [[
                'session_date' => $date,
                'start_time' => '23:00',
                'kind' => 'future',
                'subject' => 'Math',
            ]],
            'days_of_week' => [3],
            'start_time' => '23:00',
            'duration_minutes' => 30,
            'price_per_session' => 1300,
            'payment_type' => 'session',
            'course_start_date' => $date,
            'memo' => self::MEMO_RICH,
        ]);

        $response->assertCreated();
        $studentClassId = (int) $response->json('student_class_id');
        $this->assertGreaterThan(0, $studentClassId);

        $memo = StudentClass::where('ID', $studentClassId)->value('Memo');
        $this->assertSame(self::MEMO_RICH, $memo);
        $this->assertStringContainsString('📅', (string) $memo);
        $this->assertStringContainsString('試聽時間', (string) $memo);
        $this->assertStringContainsString("\n", (string) $memo);
        $this->assertStringContainsString('（', (string) $memo);
        // No mojibake markers (common UTF-8-as-Latin1 corruption)
        $this->assertStringNotContainsString('Ã', (string) $memo);
        $this->assertStringNotContainsString("\xEF\xBF\xBD", (string) $memo);
    }

    public function test_plain_text_memo_still_round_trips(): void
    {
        $token = $this->createDirectorToken([1], 'dir-memo-plain@example.com');
        $teacherId = $this->createTeacher(1, 'teach-memo-plain@example.com');
        $student = $this->createStudent(1, '純文字備註生');

        $date = Carbon::now()->addWeeks(3)->next(Carbon::WEDNESDAY)->toDateString();
        $plain = '家長希望改週四晚上，先試聽一堂。';

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
            'future_dates' => [],
            'session_plan' => [[
                'session_date' => $date,
                'start_time' => '23:00',
                'kind' => 'future',
                'subject' => 'Math',
            ]],
            'days_of_week' => [3],
            'start_time' => '23:00',
            'duration_minutes' => 30,
            'price_per_session' => 500,
            'payment_type' => 'session',
            'course_start_date' => $date,
            'memo' => $plain,
        ]);

        $response->assertCreated();
        $id = (int) $response->json('student_class_id');
        $this->assertSame($plain, StudentClass::where('ID', $id)->value('Memo'));
    }

    /**
     * Downgrade Memo to utf8mb3 for simulation. Must clear 4-byte content first or
     * MySQL rejects the ALTER (conversion of existing emoji rows).
     */
    private function forceMemoColumnToUtf8mb3(): void
    {
        DB::table('StudentClass')->update(['Memo' => null]);
        if (Schema::hasColumn('StudentClass', 'PackageName')) {
            DB::table('StudentClass')->update(['PackageName' => null]);
        }
        DB::statement(
            'ALTER TABLE `StudentClass` MODIFY `Memo` VARCHAR(512) CHARACTER SET utf8 COLLATE utf8_unicode_ci NULL'
        );
    }

    public function test_utf8mb3_memo_column_rejects_emoji_then_migration_repairs(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('utf8mb3 simulation requires MySQL');
        }

        $this->forceMemoColumnToUtf8mb3();

        $beforeCount = StudentClass::count();
        $beforeSessions = ClassSession::count();

        try {
            StudentClass::create([
                'StudentID' => 1,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => 1,
                'by1' => 1,
                'TotalHours' => 2,
                'StartDate' => now(),
                'RemainingSessions' => 1,
                'SessionCount' => 1,
                'Memo' => self::MEMO_RICH,
            ]);
            $this->fail('Expected QueryException for utf8mb3 Memo + emoji');
        } catch (\Illuminate\Database\QueryException $e) {
            $msg = $e->getMessage();
            $this->assertTrue(
                str_contains($msg, 'Incorrect string value')
                || (str_contains($msg, 'Conversion from collation') && str_contains($msg, 'impossible'))
                || in_array((int) ($e->errorInfo[1] ?? 0), [1366, 3988], true),
                'Expected MySQL charset rejection, got: ' . $msg
            );
        }

        $this->assertSame($beforeCount, StudentClass::count(), 'failed create must not leave StudentClass row');
        $this->assertSame($beforeSessions, ClassSession::count(), 'failed create must not leave ClassSession row');

        // Re-run the #1378 migration (idempotent path after intentional utf8mb3 downgrade).
        $migration = require base_path('database/migrations/2026_07_22_130000_convert_student_class_free_text_to_utf8mb4.php');
        $migration->up();

        $row = DB::selectOne(
            'SELECT CHARACTER_SET_NAME AS charset_name
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), 'StudentClass', 'Memo']
        );
        $this->assertSame('utf8mb4', $row->charset_name);

        $created = StudentClass::create([
            'StudentID' => 1,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'TotalHours' => 2,
            'StartDate' => now(),
            'RemainingSessions' => 1,
            'SessionCount' => 1,
            'Memo' => self::MEMO_RICH,
        ]);
        $this->assertSame(self::MEMO_RICH, StudentClass::where('ID', $created->ID)->value('Memo'));
    }

    public function test_enrollment_charset_failure_returns_422_without_partial_rows(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('utf8mb3 simulation requires MySQL');
        }

        $this->forceMemoColumnToUtf8mb3();

        $token = $this->createDirectorToken([1], 'dir-memo-fail@example.com');
        $teacherId = $this->createTeacher(1, 'teach-memo-fail@example.com');
        $student = $this->createStudent(1, '字元集失敗測試生');
        $date = Carbon::now()->addWeeks(4)->next(Carbon::WEDNESDAY)->toDateString();

        $beforeSc = StudentClass::where('StudentID', $student->id)->count();
        $beforeCs = ClassSession::whereIn(
            'StudentClassID',
            StudentClass::where('StudentID', $student->id)->pluck('ID')
        )->count();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'trial',
            'total_classes' => 1,
            'confirmed_dates' => [],
            'future_dates' => [],
            'session_plan' => [[
                'session_date' => $date,
                'start_time' => '23:00',
                'kind' => 'future',
                'subject' => 'Math',
            ]],
            'days_of_week' => [3],
            'start_time' => '23:00',
            'duration_minutes' => 30,
            'price_per_session' => 1300,
            'payment_type' => 'session',
            'course_start_date' => $date,
            'memo' => self::MEMO_RICH,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'memo_charset_incompatible');

        $this->assertSame(
            $beforeSc,
            StudentClass::where('StudentID', $student->id)->count(),
            'charset failure must not leave StudentClass'
        );
        $this->assertSame(
            $beforeCs,
            ClassSession::whereIn(
                'StudentClassID',
                StudentClass::where('StudentID', $student->id)->pluck('ID')
            )->count(),
            'charset failure must not leave ClassSession'
        );

        // Restore utf8mb4 so later tests in same process (if any) stay healthy.
        $migration = require base_path('database/migrations/2026_07_22_130000_convert_student_class_free_text_to_utf8mb4.php');
        $migration->up();
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
            UserCampus::firstOrCreate(
                ['CampusID' => $campusId, 'UserID' => $user->id],
                ['Admin' => 1, 'Approved' => 1]
            );
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
