<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\FrontendSubjectIdResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * in-app #409: when 課程設定 blocks a subject / teacher / slot change on a count-mode
 * course partway through, the 422 names 轉課 as its one next step (next_step) with the cutover date. The block
 * itself (and the course) stays unchanged.
 */
class StudentClassUpdateTransferHintTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Taipei'));
        $this->token = $this->directorToken();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_subject_change_block_offers_transfer_from_first_unmarked_lesson(): void
    {
        $course = $this->course();
        $this->makeSession($course, '2026-09-23', 'attended');
        $this->makeSession($course, '2026-10-14', 'scheduled');
        $this->makeSession($course, '2026-10-07', 'scheduled');

        $this->putCourse($course, ['subject' => 'Math'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'subject_change_requires_transfer')
            ->assertJsonPath('suggested_actions', ['split_contract'])
            ->assertJsonPath('next_step', 'course_transfer')
            ->assertJsonPath('transfer_hint.start_date', '2026-10-07')
            ->assertJsonPath('transfer_hint.changes', ['subject']);

        $this->assertSame($this->subjectId('English'), (int) $course->fresh()->SubjectID);
    }

    public function test_locked_contract_with_teacher_and_slot_change_offers_transfer(): void
    {
        $course = $this->course();
        $this->makeSession($course, '2026-09-23', 'attended');
        $this->makeSession($course, '2026-10-07', 'scheduled');
        $this->ledger($course);
        $teacher = $this->user('T', 0);

        $this->putCourse($course, ['sessions_purchased' => 12, 'teacher_id' => $teacher, 'days_of_week' => [5], 'start_time' => '15:00'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'billing_contract_locked')
            ->assertJsonPath('next_step', 'course_transfer')
            ->assertJsonPath('transfer_hint.changes', ['teacher', 'slots'])
            ->assertJsonPath('transfer_hint.not_carried', ['SessionCount']);

        $this->assertSame(8, (int) $course->fresh()->SessionCount);
    }

    public function test_locked_contract_without_subject_teacher_or_slot_change_has_no_transfer_hint(): void
    {
        $course = $this->course();
        $this->makeSession($course, '2026-09-23', 'attended');
        $this->makeSession($course, '2026-10-07', 'scheduled');
        $this->ledger($course);

        $this->assertMissingKeys($this->putCourse($course, ['sessions_purchased' => 12, 'days_of_week' => [3], 'start_time' => '16:00'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'billing_contract_locked'), 'transfer_hint');
    }

    public function test_monthly_course_keeps_todays_message(): void
    {
        $course = $this->course(['ScheduleMode' => 'date', 'EndDate' => '2026-12-31']);
        $this->makeSession($course, '2026-09-23', 'attended');
        $this->makeSession($course, '2026-10-07', 'scheduled');

        $this->assertMissingKeys($this->putCourse($course, ['subject' => 'Math'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'subject_change_requires_transfer')
            ->assertJsonPath('suggested_actions', ['split_contract']), 'next_step', 'transfer_hint');
    }

    public function test_no_remaining_unmarked_lesson_means_no_transfer_hint(): void
    {
        $course = $this->course();
        $this->makeSession($course, '2026-09-23', 'attended');

        $this->assertMissingKeys($this->putCourse($course, ['subject' => 'Math'])
            ->assertStatus(422), 'transfer_hint');
    }

    public function test_course_the_transfer_endpoint_would_reject_gets_no_hint(): void
    {
        // A past lesson left unmarked: no observed usage, so split-contract would fail.
        $course = $this->course();
        $this->makeSession($course, '2026-09-23', 'scheduled');
        $this->makeSession($course, '2026-10-07', 'scheduled');

        $this->assertMissingKeys($this->putCourse($course, ['subject' => 'Math'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'subject_change_requires_transfer'), 'transfer_hint');
    }

    public function test_stale_unmarked_lesson_before_taught_lessons_withholds_the_hint(): void
    {
        // 9/16 never marked, 9/23 marked: a 9/16 cutover would move the marked lesson.
        $course = $this->course();
        $this->makeSession($course, '2026-09-09', 'attended');
        $this->makeSession($course, '2026-09-16', 'scheduled');
        $this->makeSession($course, '2026-09-23', 'leave_adjusted');
        $this->makeSession($course, '2026-10-07', 'scheduled');

        $this->assertMissingKeys($this->putCourse($course, ['subject' => 'Math'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'subject_change_requires_transfer'), 'next_step', 'transfer_hint');
    }

    /** Laravel 8 TestResponse has no assertJsonMissingPath(). */
    private function assertMissingKeys($response, string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->assertArrayNotHasKey($key, (array) $response->json());
        }
    }

    private function putCourse(StudentClass $course, array $payload)
    {
        return $this->withToken($this->token)->putJson("/api/v1/student-classes/{$course->ID}", $payload);
    }

    private function subjectId(string $name): int
    {
        return (int) FrontendSubjectIdResolver::resolve($name);
    }

    private function makeSession(StudentClass $course, string $date, string $status): void
    {
        ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date,
            'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => $status,
        ]);
    }

    private function ledger(StudentClass $course): void
    {
        DB::table('session_deduction_ledger')->insert([
            'student_class_id' => $course->ID, 'class_session_id' => 1, 'event_type' => 'deduct',
            'source' => 'attendance', 'minutes' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function course(array $overrides = []): StudentClass
    {
        $student = Student::create([
            'name' => '轉課提示測試生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '',
        ]);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => $this->subjectId('English'),
            'TeacherID' => $this->user('T', 0), 'by1' => 1, 'Period' => 8, 'StartDate' => '2026-09-01',
            'TotalHours' => 16, 'Charge' => 0, 'Rate' => 1000, 'SessionCount' => 8,
            'RemainingSessions' => 8, 'SessionDuration' => 120, 'week' => 3,
            'time' => '16:00:00', 'class_type' => 'one_on_one', 'ScheduleMode' => 'count',
            'Stop' => 0, 'Paid' => 0, 'MDT' => now(),
        ], $overrides));
    }

    private function directorToken(): string
    {
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $this->user('A', 1), 'token' => $tok, 'expires_at' => now()->addDay()]);

        return $tok;
    }

    private function user(string $type, int $admin): int
    {
        $login = 'hint-' . uniqid('', true) . '@example.com';
        $u = User::create(['LoginName' => $login, 'Name' => '測試', 'PSW' => 'secret', 'type' => $type, 'phone' => '0912' . substr(md5($login), 0, 6)]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $u->id, 'Admin' => $admin, 'Approved' => 1]);

        return (int) $u->id;
    }
}
