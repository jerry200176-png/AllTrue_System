<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\LearningRecord;
use App\Models\PackageSessionLedger;
use App\Models\SessionDeductionLedger;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * #4455 class C: a lesson with ledger rows was deleted by the start-date rebuild (its guard only saw a
 * live sign-in or an approved record), then re-created undebited, stranding the debit. Calendar
 * attendance and package creation write the ledger alone. A ledger-bearing or attendance-marked lesson
 * is now kept, and the duplicate cleanup never deletes one.
 */
class LessonDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Thursday; the 16:00 lessons on 2026-07-30 have ended, those from 2026-08-13 have not.
        Carbon::setTestNow(Carbon::parse('2026-08-06 12:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_start_date_edit_keeps_a_debited_package_lesson_with_a_pending_record(): void
    {
        $student = $this->makeStudent();
        $pkg = $this->makePackage($student->id);
        $course = $this->makeCourse($student->id, '2026-07-30', ['PackageID' => $pkg->id, 'PackageTotalSessions' => 10, 'PackageName' => $pkg->name]);
        $lesson = $this->makeLesson($course->ID, '2026-07-30', 'completed');
        $record = $this->makePendingRecord($course->ID, $lesson);
        $this->assertTrue(SessionDeductionService::deductForSession($course->ID, $lesson->id, 'attendance'));
        $this->assertSame(9, (int) $pkg->refresh()->remaining_sessions);
        $courseRows = SessionDeductionLedger::where('class_session_id', $lesson->id)->pluck('id')->all();
        $packageRows = PackageSessionLedger::where('class_session_id', $lesson->id)->pluck('id')->all();

        $res = $this->editStartDate($course, '2026-07-23');

        $res->assertOk();
        $this->assertSame('partial_rebuild', $res->json('session_sync.reason'));
        $this->assertDatabaseHas('ClassSession', ['id' => $lesson->id, 'Status' => 'completed']);
        $this->assertDatabaseHas('LearningRecord', ['id' => $record->id, 'ClassSessionID' => $lesson->id, 'Status' => 'pending']);
        $this->assertSame($courseRows, SessionDeductionLedger::where('class_session_id', $lesson->id)->pluck('id')->all());
        $this->assertSame($packageRows, PackageSessionLedger::where('class_session_id', $lesson->id)->pluck('id')->all());
        $this->assertSame(-1, (int) PackageSessionLedger::where('class_session_id', $lesson->id)->sum('delta'));
        $this->assertSame(9, (int) $pkg->refresh()->remaining_sessions, 'the package balance is unchanged');
        $this->assertSame(1, ClassSession::where('StudentClassID', $course->ID)->whereDate('SessionDate', '2026-07-30')->count(),
            'the date is not re-created beside the kept lesson');
    }

    public function test_start_date_edit_keeps_a_calendar_attended_lesson(): void
    {
        $course = $this->makeCourse($this->makeStudent()->id, '2026-07-30');
        $lesson = $this->makeLesson($course->ID, '2026-07-30', 'attended');
        // Calendar attendance (ClassSessionController scheduled -> attended): a course ledger debit, no sign-in.
        $this->assertTrue(SessionDeductionService::deductOnAttendance($course, null, (int) $lesson->id));

        $res = $this->editStartDate($course, '2026-07-23');

        $res->assertOk();
        $this->assertFalse((bool) $res->json('session_sync.rebuilt'));
        $this->assertDatabaseHas('ClassSession', ['id' => $lesson->id, 'Status' => 'attended']);
        $this->assertSame(1, SessionDeductionLedger::where('class_session_id', $lesson->id)->where('event_type', 'deduct')->count());
        $this->assertSame(1, (int) $course->refresh()->UsedSessions);
    }

    public function test_start_date_edit_still_rebuilds_an_undebited_course(): void
    {
        $course = $this->makeCourse($this->makeStudent()->id, '2026-08-13', ['SessionCount' => 2, 'RemainingSessions' => 2]);
        $first = $this->makeLesson($course->ID, '2026-08-13', 'scheduled');
        $second = $this->makeLesson($course->ID, '2026-08-20', 'scheduled');

        $res = $this->editStartDate($course, '2026-08-20');

        $res->assertOk();
        $this->assertTrue((bool) $res->json('session_sync.rebuilt'));
        $this->assertSame('start_date_changed', $res->json('session_sync.reason'));
        $this->assertDatabaseMissing('ClassSession', ['id' => $first->id]);
        $this->assertDatabaseMissing('ClassSession', ['id' => $second->id]);
        $this->assertSame(['2026-08-20', '2026-08-27'], ClassSession::where('StudentClassID', $course->ID)
            ->orderBy('SessionDate')->get()->map(fn ($s) => substr((string) $s->SessionDate, 0, 10))->all());
    }

    public function test_cleanup_keeps_the_ledger_bearing_duplicate_and_the_ledger_totals(): void
    {
        $student = $this->makeStudent();
        $pkg = $this->makePackage($student->id);
        $course = $this->makeCourse($student->id, '2026-07-30', ['PackageID' => $pkg->id, 'PackageTotalSessions' => 10, 'PackageName' => $pkg->name]);
        $plain = $this->makeLesson($course->ID, '2026-07-30', 'completed');
        $debited = $this->makeLesson($course->ID, '2026-07-30', 'completed');
        SessionDeductionService::deductForSession($course->ID, $debited->id, 'attendance');

        $this->assertSame(0, $this->runCleanup($course));

        // Same score, so the old keeper was the lowest id and the debited row was deleted.
        $this->assertDatabaseMissing('ClassSession', ['id' => $plain->id]);
        $this->assertDatabaseHas('ClassSession', ['id' => $debited->id]);
        $this->assertSame(1, SessionDeductionLedger::netCount($course->ID));
        $this->assertSame(9, (int) $pkg->refresh()->remaining_sessions);
        $this->assertLedgerRowsPointAtLiveLessons($course);
    }

    public function test_cleanup_refuses_to_delete_a_second_debited_duplicate(): void
    {
        $student = $this->makeStudent();
        $pkg = $this->makePackage($student->id);
        $course = $this->makeCourse($student->id, '2026-07-30', ['PackageID' => $pkg->id, 'PackageTotalSessions' => 10, 'PackageName' => $pkg->name]);
        $a = $this->makeLesson($course->ID, '2026-07-30', 'completed');
        $b = $this->makeLesson($course->ID, '2026-07-30', 'completed');
        SessionDeductionService::deductForSession($course->ID, $a->id, 'attendance');
        SessionDeductionService::deductForSession($course->ID, $b->id, 'attendance');
        $packageNet = PackageSessionLedger::netDelta($pkg->id);

        $this->assertSame(0, $this->runCleanup($course));

        $this->assertStringContainsString('SKIP ClassSession id=' . $b->id, Artisan::output());
        $this->assertDatabaseHas('ClassSession', ['id' => $a->id]);
        $this->assertDatabaseHas('ClassSession', ['id' => $b->id]);
        $this->assertSame(2, SessionDeductionLedger::netCount($course->ID));
        $this->assertSame($packageNet, PackageSessionLedger::netDelta($pkg->id));
        $this->assertLedgerRowsPointAtLiveLessons($course);
    }

    private function assertLedgerRowsPointAtLiveLessons(StudentClass $course): void
    {
        $live = ClassSession::where('StudentClassID', $course->ID)->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach (SessionDeductionLedger::where('student_class_id', $course->ID)->pluck('class_session_id') as $id) {
            $this->assertContains((int) $id, $live);
        }
        foreach (PackageSessionLedger::where('student_class_id', $course->ID)->pluck('class_session_id') as $id) {
            $this->assertContains((int) $id, $live);
        }
    }

    private function runCleanup(StudentClass $course): int
    {
        $snapshot = storage_path('app/lesson-delete-guard-' . $course->ID . '.json');
        $code = Artisan::call('classsession:cleanup-intra-duplicates', [
            '--execute' => true,
            '--student_class_id' => $course->ID,
            '--snapshot' => $snapshot,
        ]);
        @unlink($snapshot);

        return $code;
    }

    private function editStartDate(StudentClass $course, string $date)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->createDirectorToken(),
            'Accept' => 'application/json',
        ])->putJson("/api/v1/student-classes/{$course->ID}", ['first_class_date' => $date]);
    }

    private function makeStudent(): Student
    {
        return Student::create([
            'name' => '刪堂守門學生' . uniqid(), 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
    }

    private function makePackage(int $studentId): CoursePackage
    {
        return CoursePackage::create([
            'student_id' => $studentId, 'campus_id' => 1, 'name' => '刪堂守門方案',
            'total_sessions' => 10, 'remaining_sessions' => 10, 'used_sessions' => 0,
            'rate' => 500, 'rate_unit' => 'session', 'class_type' => 'one_on_one',
            'paid' => false, 'stop' => false, 'enabled' => true,
        ]);
    }

    private function makeCourse(int $studentId, string $startDate, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99,
            'by1' => 1, 'Period' => 4, 'StartDate' => $startDate, 'TotalHours' => 0,
            'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Rate' => 500, 'rate_unit' => 'session',
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count',
            'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 10, 'UsedSessions' => 0,
            'ClassType' => 'one_on_one', 'week' => 4, 'time' => '16:00:00',
        ], $overrides));
    }

    private function makeLesson(int $courseId, string $date, string $status): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $courseId, 'SessionDate' => $date,
            'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => $status,
        ]);
    }

    private function makePendingRecord(int $courseId, ClassSession $lesson): LearningRecord
    {
        return LearningRecord::create([
            'StudentClassID' => $courseId, 'ClassSessionID' => $lesson->id, 'TeacherID' => 99,
            'Content' => '', 'Subject' => '數學', 'SessionDate' => $lesson->SessionDate,
            'StartTime' => $lesson->StartTime, 'EndTime' => $lesson->EndTime, 'Status' => 'pending',
        ]);
    }

    private function createDirectorToken(): string
    {
        $user = User::create([
            'LoginName' => 'director-delete-guard-' . bin2hex(random_bytes(4)) . '@test.com',
            'Name' => '主任測試', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }
}
