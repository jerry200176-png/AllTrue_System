<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** #1732: overlong Memo must 422 in Chinese, not SQL 1406. */
class StudentClassMemoLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_overlong_memo_returns_chinese_422(): void
    {
        $token = $this->directorToken();
        $student = Student::create(['name' => '備註過長測試生', 'CampusID' => 1, 'ClassID' => 1]);
        $teacher = User::create([
            'LoginName' => 'memo-teacher@example.test', 'Name' => '備註老師', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0911222001', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => $teacher->id,
            'by1' => 1, 'Period' => 2, 'StartDate' => '2026-01-03', 'time' => '13:00:00',
            'TotalHours' => 4, 'SessionCount' => 2, 'SessionDuration' => 120,
            'RemainingSessions' => 2, 'UsedSessions' => 0, 'Stop' => 0,
            'Memo' => '短備註',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson("/api/v1/student-classes/{$course->ID}", [
                'subject' => '社會',
                'teacher_id' => $teacher->id,
                'payment_type' => 'monthly',
                'memo' => str_repeat('繳費明細請確認', 2000),
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => '課程備註太長，請縮短後再儲存']);
    }

    private function directorToken(): string
    {
        $director = User::create([
            'LoginName' => 'memo-director@example.test', 'Name' => '備註主任', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0911000001', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }
}
