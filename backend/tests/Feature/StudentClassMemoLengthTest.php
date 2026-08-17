<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #1732 — course Memo must accept a pasted payment letter (>512 chars)
 * and reject only after MEMO_MAX_LENGTH with 422, never SQLSTATE 22001.
 */
class StudentClassMemoLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_memo_column_is_text_on_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('column type assertion requires MySQL');
        }

        $row = DB::selectOne(
            'SELECT DATA_TYPE AS data_type
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), 'StudentClass', 'Memo']
        );

        $this->assertContains(strtolower((string) ($row->data_type ?? '')), ['text', 'mediumtext', 'longtext']);
    }

    public function test_update_persists_memo_longer_than_legacy_varchar_512(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        $sc = $this->createStudentClass((int) $student->id);

        $memo = str_repeat('備', 600);

        $res = $this->putJson(
            '/api/v1/student-classes/' . $sc->getKey(),
            ['Memo' => $memo],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $this->assertSame($memo, (string) $sc->fresh()->getAttribute('Memo'));
    }

    public function test_update_accepts_memo_at_max_length(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        $sc = $this->createStudentClass((int) $student->id);

        $memo = str_repeat('x', StudentClass::MEMO_MAX_LENGTH);

        $res = $this->putJson(
            '/api/v1/student-classes/' . $sc->getKey(),
            ['Memo' => $memo],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $this->assertSame(StudentClass::MEMO_MAX_LENGTH, mb_strlen((string) $sc->fresh()->getAttribute('Memo')));
    }

    public function test_update_rejects_memo_over_max_with_422_and_does_not_write(): void
    {
        $token = $this->createDirectorToken();
        $student = $this->createStudent();
        $sc = $this->createStudentClass((int) $student->id, ['Memo' => '原備註']);

        $res = $this->putJson(
            '/api/v1/student-classes/' . $sc->getKey(),
            ['Memo' => str_repeat('x', StudentClass::MEMO_MAX_LENGTH + 1)],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(422);
        $res->assertJsonFragment(['code' => 'memo_too_long']);
        $this->assertSame('原備註', (string) $sc->fresh()->getAttribute('Memo'));
    }

    private function createDirectorToken(): string
    {
        $user = User::create([
            'LoginName' => 'dir-memo-len@example.com',
            'Name' => '主任',
            'PSW' => 'x',
            'type' => 'A',
            'phone' => '0910001732',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createStudent(): Student
    {
        return Student::create([
            'name' => '備註長度測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->toDateString(),
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 60,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }
}
