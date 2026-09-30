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
use Tests\TestCase;

/**
 * Past lessons are history: subject / teacher / slot edits must not rewrite them.
 * Seeded subjects: 1 Chinese, 2 English, 3 Math.
 */
class CourseSubjectChangeGuardTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-09-30 10:00:00';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_subject_change_is_blocked_when_contract_has_past_sessions(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TODAY, 'Asia/Taipei'));
        [$token, $course] = $this->fixture(2); // English
        $this->makeSession($course, '2026-09-23', 'attended');

        $this->withToken($token)->putJson("/api/v1/student-classes/{$course->ID}", ['subject' => 'Math'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'subject_change_requires_transfer');

        $this->assertSame(2, (int) $course->fresh()->SubjectID);
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => 'student_class.edit_blocked',
            'outcome' => 'blocked',
        ]);
    }

    public function test_same_subject_resave_and_untouched_contract_subject_change_are_allowed(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TODAY, 'Asia/Taipei'));
        [$token, $course] = $this->fixture(2);
        $this->makeSession($course, '2026-09-23', 'attended');
        $this->withToken($token)->putJson("/api/v1/student-classes/{$course->ID}", ['subject' => 'English'])->assertOk();

        [$token2, $fresh] = $this->fixture(2);
        $this->makeSession($fresh, '2026-10-07', 'scheduled'); // future only
        $this->withToken($token2)->putJson("/api/v1/student-classes/{$fresh->ID}", ['subject' => 'Math'])->assertOk();
        $this->assertSame(3, (int) $fresh->fresh()->SubjectID);
    }

    public function test_teacher_change_defaults_to_keeping_untaught_past_sessions_on_former_teacher(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TODAY, 'Asia/Taipei'));
        [$token, $course, $old, $new] = $this->fixture(2);
        $past = $this->makeSession($course, '2026-09-23', 'scheduled'); // no attendance evidence

        $this->withToken($token)->putJson("/api/v1/student-classes/{$course->ID}", ['teacher_id' => $new])->assertOk();

        $this->assertSame($old, $this->teacherOf($token, $course, $past->id));
    }

    public function test_teacher_effective_date_splits_old_and_new_teacher(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TODAY, 'Asia/Taipei'));
        [$token, $course, $old, $new] = $this->fixture(2);
        $before = $this->makeSession($course, '2026-09-16', 'scheduled');
        $onAfter = $this->makeSession($course, '2026-09-23', 'scheduled');

        $this->withToken($token)->putJson("/api/v1/student-classes/{$course->ID}", [
            'teacher_id' => $new,
            'teacher_effective_date' => '2026-09-20',
        ])->assertOk();

        $this->assertSame($old, $this->teacherOf($token, $course, $before->id));
        $this->assertSame($new, $this->teacherOf($token, $course, $onAfter->id));
    }

    public function test_future_teacher_effective_date_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TODAY, 'Asia/Taipei'));
        [$token, $course, , $new] = $this->fixture(2);
        $this->withToken($token)->putJson("/api/v1/student-classes/{$course->ID}", [
            'teacher_id' => $new,
            'teacher_effective_date' => '2026-10-15',
        ])->assertStatus(422);
    }

    public function test_slot_change_never_rebuilds_or_moves_past_sessions(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TODAY, 'Asia/Taipei'));
        [$token, $course] = $this->fixture(2);
        $past = $this->makeSession($course, '2026-09-23', 'scheduled'); // Wed, no sign-in / LR

        $this->withToken($token)->putJson("/api/v1/student-classes/{$course->ID}", [
            'StudentID' => $course->StudentID,
            'week' => 3,
            'time' => '15:00:00',
        ])->assertOk();

        $kept = ClassSession::find($past->id);
        $this->assertNotNull($kept, 'past session must survive a slot edit');
        $this->assertSame('2026-09-23', substr((string) $kept->SessionDate, 0, 10));
        $this->assertSame('16:00', substr((string) $kept->StartTime, 0, 5));
    }

    private function teacherOf(string $token, StudentClass $course, int $sessionId): int
    {
        $res = $this->withToken($token)
            ->getJson("/api/v1/class-sessions?branch_id=1&student_class_id={$course->ID}&per_page=100")
            ->assertOk();
        return (int) collect($res->json('data'))->firstWhere('id', $sessionId)['teacher_id'];
    }

    private function makeSession(StudentClass $course, string $date, string $status): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => $date,
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => $status,
        ]);
    }

    /** @return array{0:string,1:StudentClass,2:int,3:int} */
    private function fixture(int $subjectId): array
    {
        $token = $this->directorToken();
        $old = $this->teacher('old-' . uniqid() . '@example.com', 'Ruth');
        $new = $this->teacher('new-' . uniqid() . '@example.com', '李維');
        $student = Student::create([
            'name' => '護欄測試生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => $subjectId,
            'TeacherID' => $old, 'by1' => 1, 'Period' => 8, 'StartDate' => '2026-09-01',
            'TotalHours' => 16, 'Charge' => 0, 'Rate' => 1000, 'SessionCount' => 8,
            'RemainingSessions' => 8, 'SessionDuration' => 120, 'week' => 3,
            'time' => '16:00:00', 'class_type' => 'one_on_one', 'ScheduleMode' => 'count',
            'Stop' => 0, 'Paid' => 0, 'MDT' => now(),
        ]);

        return [$token, $course, $old, $new];
    }

    private function directorToken(): string
    {
        $user = User::create([
            'LoginName' => 'dir-guard-' . uniqid() . '@test.com', 'Name' => '主任',
            'PSW' => 'secret', 'type' => 'A', 'phone' => '0912000001',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $tok, 'expires_at' => now()->addDay()]);

        return $tok;
    }

    private function teacher(string $login, string $name): int
    {
        $user = User::create([
            'LoginName' => $login, 'Name' => $name, 'PSW' => 'secret', 'type' => 'T',
            'phone' => '0912' . substr(md5($login), 0, 6),
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);

        return (int) $user->id;
    }
}
