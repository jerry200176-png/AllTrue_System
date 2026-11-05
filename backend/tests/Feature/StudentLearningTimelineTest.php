<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #4928 step 1: staff read-only learning timeline merged from evaluations, assessment scores and sign-ins. */
class StudentLearningTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_merges_three_sources_newest_first(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $director = $this->staff('A', $campus);
        $teacher = $this->staff('T', $campus);
        $student = $this->student($campus);
        $course = $this->course($student->id, $teacher['user']->id, $campus);

        $s1 = $this->lesson($course, '2026-10-01');
        $s2 = $this->lesson($course, '2026-10-15');
        $this->signIn($student, $course, $s1, 'present');
        $this->signIn($student, $course, $s2, 'absent');
        LearningRecord::create([
            'StudentClassID' => $course->ID, 'ClassSessionID' => $s1->id, 'TeacherID' => $teacher['user']->id,
            'Content' => 'x', 'Status' => 'approved', 'SessionDate' => '2026-10-01', 'HomeworkStatus' => '完成',
            'QuizScore' => '小考 90', 'NextWeekTestScope' => '第三章',
        ]);
        $assessment = Assessment::create([
            'campus_id' => $campus, 'student_class_id' => $course->ID, 'subject_id' => 1, 'title' => '單元檢測',
            'status' => 'published', 'max_score' => 100,
        ]);
        AssessmentResult::create([
            'assessment_id' => $assessment->id, 'student_id' => $student->id, 'student_class_id' => $course->ID,
            'attempt_no' => 1, 'score' => 80, 'max_score_snapshot' => 100, 'percent' => 80, 'status' => 'submitted',
            'recorded_at' => '2026-10-08 10:00:00',
        ]);

        $items = $this->withAuth($director['token'])->getJson("/api/v1/students/{$student->id}/learning-timeline")
            ->assertOk()->json('items');

        // The evaluated lesson carries its own sign-in; only the un-evaluated lesson shows as an attendance row.
        $this->assertSame(['attendance', 'assessment', 'evaluation'], array_column($items, 'type'));
        $this->assertSame(['2026-10-15', '2026-10-08', '2026-10-01'], array_column($items, 'date'));
        $this->assertSame('缺席', $items[0]['attendance']);
        $this->assertEquals(80, $items[1]['score']);
        $this->assertSame('到班', $items[2]['attendance']);
        $this->assertSame('第三章', $items[2]['next_week_scope']);
    }

    public function test_other_campus_director_and_parent_are_refused(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $other = (int) CampusFactory::new()->create()->id;
        $student = $this->student($campus);
        $student->update(['Phone' => '0911222333']);

        $this->withAuth($this->staff('A', $other)['token'])
            ->getJson("/api/v1/students/{$student->id}/learning-timeline")->assertForbidden();

        $parent = $this->postJson('/api/v1/parent/login', ['Name' => $student->name, 'Phone' => '0911222333'])->json('token');
        $this->assertContains($this->withAuth((string) $parent)
            ->getJson("/api/v1/students/{$student->id}/learning-timeline")->status(), [401, 403]);
    }

    public function test_teacher_sees_only_their_own_students(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $mine = $this->staff('T', $campus);
        $theirs = $this->staff('T', $campus);
        $student = $this->student($campus);
        $course = $this->course($student->id, $theirs['user']->id, $campus);
        $session = $this->lesson($course, '2026-10-01');
        $this->signIn($student, $course, $session, 'present');

        $this->withAuth($mine['token'])->getJson("/api/v1/students/{$student->id}/learning-timeline")->assertForbidden();
        $this->withAuth($theirs['token'])->getJson("/api/v1/students/{$student->id}/learning-timeline")
            ->assertOk()->assertJsonCount(1, 'items');
    }

    public function test_substitute_sees_only_the_lesson_they_covered(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $regular = $this->staff('T', $campus);
        $sub = $this->staff('T', $campus);
        $student = $this->student($campus);
        $course = $this->course($student->id, $regular['user']->id, $campus);
        $covered = $this->lesson($course, '2026-10-01');
        $other = $this->lesson($course, '2026-10-08');
        $this->signIn($student, $course, $covered, 'present');
        $this->signIn($student, $course, $other, 'present');
        DB::table('schedules')->insert([
            'student_id' => $student->id, 'teacher_id' => $sub['user']->id, 'subject' => 'Math', 'day_of_week' => 4,
            'start_time' => '16:00:00', 'end_time' => '17:00:00', 'class_type' => 'one_on_one', 'status' => 'scheduled',
            'type' => 'extra', 'deduction' => 1, 'branch_id' => $campus, 'schedule_date' => '2026-10-01',
            'student_course_id' => $course->ID, 'original_schedule_id' => 1,
        ]);
        LearningRecord::create([
            'StudentClassID' => $course->ID, 'ClassSessionID' => $other->id, 'TeacherID' => $regular['user']->id,
            'Content' => 'x', 'Status' => 'approved', 'SessionDate' => '2026-10-08',
        ]);

        $json = fn (array $t) => $this->withAuth($t['token'])->getJson("/api/v1/students/{$student->id}/learning-timeline")->assertOk()->json('items');
        $this->assertSame(['2026-10-01'], array_column($json($sub), 'date'));
        $this->assertSame(['2026-10-08'], array_column($json($regular), 'date'));
    }

    public function test_evaluated_lessons_past_the_cap_do_not_reappear_as_attendance_rows(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $director = $this->staff('A', $campus);
        $student = $this->student($campus);
        $course = $this->course($student->id, $director['user']->id, $campus);
        $lesson = $this->lesson($course, '2024-01-01');
        $this->signIn($student, $course, $lesson, 'present');
        LearningRecord::create([
            'StudentClassID' => $course->ID, 'ClassSessionID' => $lesson->id, 'TeacherID' => $director['user']->id,
            'Content' => 'x', 'Status' => 'approved', 'SessionDate' => '2024-01-01',
        ]);
        // 300 newer evaluations push the oldest one past the per-source cap.
        $rows = [];
        for ($i = 1; $i <= 300; $i++) {
            $sessionId = $this->lesson($course, date('Y-m-d', strtotime("2024-01-01 +{$i} days")))->id;
            $rows[] = [
                'StudentClassID' => $course->ID, 'ClassSessionID' => $sessionId, 'TeacherID' => $director['user']->id, 'Content' => 'x', 'Status' => 'approved',
                'SessionDate' => date('Y-m-d', strtotime("2024-01-01 +{$i} days")), 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('LearningRecord')->insert($rows);

        $types = array_column($this->withAuth($director['token'])->getJson("/api/v1/students/{$student->id}/learning-timeline")->json('items'), 'type');
        $this->assertSame(['evaluation' => 300], array_count_values($types));
    }

    /** @return array{token:string,user:User} */
    private function staff(string $type, int $campus): array
    {
        $user = User::create([
            'LoginName' => uniqid('tl-') . '@example.com', 'Name' => '測試員', 'PSW' => 'secret', 'type' => $type,
            'phone' => (string) random_int(900000000, 999999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $user->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return ['token' => $token, 'user' => $user];
    }

    private function student(int $campus): Student
    {
        return Student::create(['name' => '虛構學生', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function course(int $studentId, int $teacherId, int $campus): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $studentId, 'TeacherID' => $teacherId, 'GradeID' => 1, 'SubjectID' => 1, 'by1' => $campus,
            'StartDate' => '2026-08-01', 'TotalHours' => 20, 'Charge' => 1000, 'Pay' => 0, 'Paid' => 0, 'Rate' => 1000,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10, 'RemainingSessions' => 10,
            'UsedSessions' => 0, 'SessionDuration' => 60, 'ClassType' => 'one_on_one',
        ]);
    }

    private function lesson(StudentClass $course, string $date): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date, 'StartTime' => '16:00', 'EndTime' => '17:00',
            'Status' => 'completed', 'Note' => '',
        ]);
    }

    private function signIn(Student $student, StudentClass $course, ClassSession $session, string $status): void
    {
        DB::table('StudentSingIn')->insert([
            'StudentID' => $student->id, 'StudentClassID' => $course->ID, 'ClassSessionID' => $session->id,
            'Status' => $status, 'SignInDT' => $session->SessionDate . ' 16:00:00', 'MDT' => now(), 'PersonType' => 'S',
        ]);
    }

    private function withAuth(string $token)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }
}
