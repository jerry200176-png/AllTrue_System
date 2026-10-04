<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * in-app #319: /class-sessions picks the latest non-voided sign-in and learning record per session and the
 * latest substitute per slot through per-row lookups, not whole-table "latest per session" derived tables.
 */
class ClassSessionIndexLatestRowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_non_voided_rows_and_substitute_are_kept(): void
    {
        $teacher = $this->user('T', 'teacher-319-latest@example.com');
        $sub = $this->user('Sub', 'sub-319-latest@example.com');
        $admin = $this->user('S', 'admin-319-latest@example.com', 'S');
        $student = Student::create(['name' => '最新學生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacher->id,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-05-01', 'EndDate' => '2026-05-31',
            'TotalHours' => 20, 'Charge' => 0, 'Paid' => 1, 'Rate' => 500, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'date', 'SessionDuration' => 120, 'week' => 5, 'time' => '15:00:00',
        ]);
        $voidedLrSession = $this->makeSession($course->ID, '2026-05-08');
        $signInSession = $this->makeSession($course->ID, '2026-05-15');
        $voidedSignInSession = $this->makeSession($course->ID, '2026-05-19');

        LearningRecord::create([
            'StudentClassID' => $course->ID, 'ClassSessionID' => $voidedLrSession->id, 'TeacherID' => $teacher->id,
            'Content' => '舊', 'Status' => 'pending', 'SessionDate' => '2026-05-08', 'StartTime' => '15:00:00',
            'EndTime' => '17:00:00', 'VoidedAt' => now(), 'VoidReason' => 'test',
        ]);
        $keep = $this->signIn($course, $signInSession, '有效簽到');
        $this->signIn($course, $voidedSignInSession, '作廢簽到', now());
        // A substitute on 05-15 (in range) is shown; one on 05-22 (outside range) must not leak in.
        foreach (['2026-05-15', '2026-05-22'] as $date) {
            Schedule::create([
                'student_id' => $student->id, 'teacher_id' => $sub->id, 'day_of_week' => 5, 'start_time' => '15:00:00',
                'end_time' => '17:00:00', 'status' => 'scheduled', 'type' => 'normal', 'deduction' => 1, 'branch_id' => 1,
                'schedule_date' => $date, 'student_course_id' => $course->ID, 'original_schedule_id' => 1,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = collect($this->withHeaders(['Authorization' => 'Bearer ' . $this->token($admin), 'Accept' => 'application/json'])
            ->getJson('/api/v1/class-sessions?branch_id=1&start=2026-05-01&end=2026-05-20&per_page=100')
            ->assertOk()->json('data'))->keyBy('id');
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));

        $this->assertNull($rows[$voidedLrSession->id]['learning_record_id'], 'voided learning record is not the latest');
        $this->assertSame('有效簽到', $rows[$signInSession->id]['attendance_memo'], 'non-voided sign-in is returned');
        $this->assertSame('', $rows[$voidedSignInSession->id]['attendance_memo'], 'voided sign-in is not returned');
        $this->assertSame($sub->id, $rows[$signInSession->id]['substitute_teacher_id']);
        $this->assertNotNull($keep->id);
        $this->assertStringNotContainsString('GROUP BY ClassSessionID', $sql, 'no whole-table latest-per-session derived tables');
        $this->assertStringContainsString("sub2.schedule_date >= '2026-05-01'", $sql, 'substitute lookup is date-bounded');
    }

    private function user(string $name, string $login, string $type = 'T'): User
    {
        $user = User::create(['LoginName' => $login, 'Name' => $name, 'PSW' => 'secret', 'type' => $type,
            'phone' => (string) random_int(900000000, 999999999), 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => $type === 'S' ? 1 : 0, 'Approved' => 1]);

        return $user;
    }

    private function token(User $user): string
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function makeSession(int $courseId, string $date): ClassSession
    {
        return ClassSession::create(['StudentClassID' => $courseId, 'SessionDate' => $date,
            'StartTime' => '15:00:00', 'EndTime' => '17:00:00', 'Status' => 'attended']);
    }

    private function signIn(StudentClass $course, ClassSession $cs, string $memo, $voidedAt = null): StudentSignIn
    {
        return StudentSignIn::create([
            'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'ClassSessionID' => $cs->id, 'CampusID' => 1,
            'SignInDT' => $cs->SessionDate . ' 15:00:00', 'SignOutDT' => $cs->SessionDate . ' 17:00:00',
            'Status' => 'attended', 'Memo' => $memo, 'MDT' => now(), 'VoidedAt' => $voidedAt,
        ]);
    }
}
