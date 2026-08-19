<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Interim workaround for in-app #1901/#1902/#1904: move already-materialized
// ClassSession rows (and their LearningRecord/StudentSignIn) to a different
// StudentClass so a teacher does not have to refill evaluations after a
// course split. Deliberately never touches SessionCount/Charge/deduction
// fields on either course — those stay BillingContractLockGuard's job.
class StudentClassTransferSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfers_session_and_carries_learning_record_and_signin(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $source = $this->createCourse($student->id, 1);
        $target = $this->createCourse($student->id, 1);
        $sessionId = $this->createClassSession((int) $source->ID, '2026-08-02');
        $this->createLearningRecord((int) $source->ID, $sessionId);
        $this->createSignIn((int) $source->ID, $sessionId);

        $res = $this->postJson(
            "/api/v1/student-classes/{$source->ID}/transfer-sessions",
            ['session_ids' => [$sessionId], 'target_student_class_id' => $target->ID],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertOk();
        $this->assertSame(
            (int) $target->ID,
            (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID')
        );
        $this->assertSame(
            (int) $target->ID,
            (int) LearningRecord::where('ClassSessionID', $sessionId)->value('StudentClassID')
        );
        $this->assertSame(
            (int) $target->ID,
            (int) StudentSignIn::where('ClassSessionID', $sessionId)->value('StudentClassID')
        );

        // Source course's own billing fields are untouched.
        $source->refresh();
        $this->assertSame(8, (int) $source->SessionCount);
    }

    public function test_rejects_when_target_belongs_to_a_different_student(): void
    {
        $token = $this->createDirectorToken([1]);
        $studentA = $this->createStudent(1);
        $studentB = $this->createStudent(1);
        $source = $this->createCourse($studentA->id, 1);
        $target = $this->createCourse($studentB->id, 1);
        $sessionId = $this->createClassSession((int) $source->ID, '2026-08-02');

        $res = $this->postJson(
            "/api/v1/student-classes/{$source->ID}/transfer-sessions",
            ['session_ids' => [$sessionId], 'target_student_class_id' => $target->ID],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(422);
        $this->assertSame(
            (int) $source->ID,
            (int) DB::table('ClassSession')->where('id', $sessionId)->value('StudentClassID')
        );
    }

    public function test_rejects_session_id_not_in_source_course(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $source = $this->createCourse($student->id, 1);
        $target = $this->createCourse($student->id, 1);
        $otherCourse = $this->createCourse($student->id, 1);
        $foreignSessionId = $this->createClassSession((int) $otherCourse->ID, '2026-08-02');

        $res = $this->postJson(
            "/api/v1/student-classes/{$source->ID}/transfer-sessions",
            ['session_ids' => [$foreignSessionId], 'target_student_class_id' => $target->ID],
            ['Authorization' => "Bearer {$token}"]
        );

        $res->assertStatus(422);
        $this->assertSame(
            (int) $otherCourse->ID,
            (int) DB::table('ClassSession')->where('id', $foreignSessionId)->value('StudentClassID')
        );
    }

    // ── helpers ──

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-transfersess-' . uniqid() . '@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function createStudent(int $campusId): Student
    {
        return Student::create([
            'name' => '轉移測試生-' . uniqid(),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createCourse(int $studentId, int $teacherId): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'TotalHours' => 20,
            'Charge' => 8800,
            'Paid' => 1,
            'Rate' => 1100,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ]);
    }

    private function createClassSession(int $courseId, string $date): int
    {
        return DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => 'attended',
        ]);
    }

    private function createLearningRecord(int $courseId, int $sessionId): void
    {
        DB::table('LearningRecord')->insert([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $sessionId,
            'TeacherID' => 1,
            'CreatedByUserID' => 1,
            'Content' => '轉移測試評量內容',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSignIn(int $courseId, int $sessionId): void
    {
        DB::table('StudentSingIn')->insert([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $sessionId,
            'StudentID' => 1,
            'TeacherID' => 1,
            'Status' => 'present',
            'SignInDT' => now(),
        ]);
    }
}
