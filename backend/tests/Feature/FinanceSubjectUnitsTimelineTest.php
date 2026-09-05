<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinanceSubjectUnitsTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_keeps_teacher_date_campus_subject_dimensions_and_categories(): void
    {
        $campusA = Campus::factory()->create(['name' => '日粒度甲分校']);
        $campusB = Campus::factory()->create(['name' => '日粒度乙分校']);
        $director = $this->createUser('director-timeline@example.com', 'A', [$campusA->id, $campusB->id]);
        $teacher = $this->createUser('teacher-timeline@example.com', 'T', [$campusA->id, $campusB->id]);

        $regular = $this->course($campusA->id, $teacher['user_id'], 'one_on_one', 1);
        $regularSession = $this->makeSession($regular, '2026-08-03', '16:00:00', '18:00:00', 'completed');
        LearningRecord::create([
            'StudentClassID' => $regular->ID, 'ClassSessionID' => $regularSession->id,
            'TeacherID' => $teacher['user_id'], 'Content' => '已核准正課', 'Subject' => 'Math',
            'Status' => 'approved', 'ApprovedBy' => $director['user_id'], 'ApprovedAt' => now(),
            'SessionDate' => '2026-08-03', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00',
            'SessionDeducted' => true,
        ]);

        // No LearningRecord: the production attendance fallback must still show
        // the regular lesson on its own date and campus.
        $fallback = $this->course($campusB->id, $teacher['user_id'], 'one_on_two', 2);
        $fallbackSession = $this->makeSession($fallback, '2026-08-04', '17:00:00', '19:00:00', 'attended');
        StudentSignIn::create([
            'StudentClassID' => $fallback->ID, 'StudentID' => $fallback->StudentID,
            'TeacherID' => $teacher['user_id'], 'ClassSessionID' => $fallbackSession->id,
            'Status' => 'present', 'SignInDT' => '2026-08-04 17:00:00',
        ]);

        // Legacy completed tutoring session without a sign-in is still a valid
        // special lesson, but must not be duplicated by another source.
        $tutoring = $this->course($campusA->id, $teacher['user_id'], 'tutoring', 1);
        $this->makeSession($tutoring, '2026-08-04', '19:00:00', '21:00:00', 'completed');

        $trial = $this->course($campusB->id, $teacher['user_id'], 'trial', 2);
        $trialSession = $this->makeSession($trial, '2026-08-05', '15:00:00', '17:00:00', 'trial');
        StudentSignIn::create([
            'StudentClassID' => $trial->ID, 'StudentID' => $trial->StudentID,
            'TeacherID' => $teacher['user_id'], 'ClassSessionID' => $trialSession->id,
            'Status' => 'trial', 'SignInDT' => '2026-08-05 15:00:00',
        ]);

        $response = $this->withHeaders($this->authHeaders($director['token']))
            ->getJson('/api/v1/finance/subject-units/timeline?start=2026-08-03&end=2026-08-05')
            ->assertOk();

        $entries = collect($response->json('entries'));
        $this->assertCount(4, $entries);
        $this->assertSame(['2026-08-03', '2026-08-04', '2026-08-05'], $response->json('days.*.date'));
        $this->assertSame((int) $campusA->id, $entries->firstWhere('date', '2026-08-03')['campus_id']);

        $approved = $entries->firstWhere('date', '2026-08-03');
        $this->assertEqualsWithDelta(0.375, $approved['regular_subject_count'], 0.0001);
        $this->assertSame(0.0, (float) $approved['tutoring_trial_subject_count']);
        $this->assertEqualsWithDelta(0.375, $approved['payroll_subject_count'], 0.0001);

        $fallbackRow = $entries->first(fn ($row) => $row['date'] === '2026-08-04' && $row['campus_id'] === (int) $campusB->id);
        $this->assertSame((int) $campusB->id, $fallbackRow['campus_id']);
        $this->assertEqualsWithDelta(0.1875, $fallbackRow['regular_subject_count'], 0.0001);
        $this->assertEqualsWithDelta(0.125, $entries->filter(fn ($row) => $row['date'] === '2026-08-04')->sum('tutoring_trial_subject_count'), 0.0001);
        $this->assertEqualsWithDelta(0.3125, $response->json('days.1.payroll_subject_count'), 0.0001);
    }

    public function test_timeline_deduplicates_approved_record_and_attendance_for_one_session(): void
    {
        $campus = Campus::factory()->create(['name' => '日粒度去重分校']);
        $director = $this->createUser('director-timeline-dedupe@example.com', 'A', [$campus->id]);
        $teacher = $this->createUser('teacher-timeline-dedupe@example.com', 'T', [$campus->id]);
        $course = $this->course($campus->id, $teacher['user_id'], 'one_on_one', 1);
        $session = $this->makeSession($course, '2026-08-06', '16:00:00', '18:00:00', 'completed');
        LearningRecord::create([
            'StudentClassID' => $course->ID, 'ClassSessionID' => $session->id,
            'TeacherID' => $teacher['user_id'], 'Content' => '去重評量', 'Subject' => 'Math',
            'Status' => 'approved', 'ApprovedBy' => $director['user_id'], 'ApprovedAt' => now(),
            'SessionDate' => '2026-08-06', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00',
            'SessionDeducted' => true,
        ]);
        StudentSignIn::create([
            'StudentClassID' => $course->ID, 'StudentID' => $course->StudentID,
            'TeacherID' => $teacher['user_id'], 'ClassSessionID' => $session->id,
            'Status' => 'present', 'SignInDT' => '2026-08-06 16:00:00',
        ]);

        $entry = $this->withHeaders($this->authHeaders($director['token']))
            ->getJson('/api/v1/finance/subject-units/timeline?start=2026-08-06&end=2026-08-06')
            ->assertOk()->json('entries.0');

        $this->assertSame(1, $entry['session_count']);
        $this->assertSame(2.0, (float) $entry['regular_hours']);
        $this->assertEqualsWithDelta(0.375, $entry['payroll_subject_count'], 0.0001);
    }

    public function test_teacher_timeline_is_limited_to_self_and_authorized_campus(): void
    {
        $campus = Campus::factory()->create(['name' => '日粒度權限分校']);
        $outside = Campus::factory()->create(['name' => '日粒度未授權分校']);
        $teacherA = $this->createUser('teacher-timeline-self@example.com', 'T', [$campus->id]);
        $teacherB = $this->createUser('teacher-timeline-other@example.com', 'T', [$campus->id]);
        $courseA = $this->course($campus->id, $teacherA['user_id'], 'one_on_one', 1);
        $courseB = $this->course($campus->id, $teacherB['user_id'], 'one_on_one', 1);
        $this->sessionWithApprovedRecord($courseA, $teacherA['user_id'], '2026-08-07');
        $this->sessionWithApprovedRecord($courseB, $teacherB['user_id'], '2026-08-07');

        $rows = $this->withHeaders($this->authHeaders($teacherA['token']))
            ->getJson('/api/v1/finance/subject-units/timeline?start=2026-08-07&end=2026-08-07')
            ->assertOk()->json('entries');
        $this->assertCount(1, $rows);
        $this->assertSame($teacherA['user_id'], $rows[0]['teacher_id']);

        $this->withHeaders($this->authHeaders($teacherA['token']))
            ->getJson('/api/v1/finance/subject-units/timeline?branch_id=' . $outside->id . '&start=2026-08-07&end=2026-08-07')
            ->assertForbidden();
    }

    private function createUser(string $loginName, string $type, array $campusIds): array
    {
        $user = User::create([
            'LoginName' => $loginName, 'Name' => $type === 'A' ? '測試主任' : '測試老師',
            'PSW' => 'secret', 'type' => $type, 'phone' => '0911000000',
            'MustChangePassword' => false,
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['user_id' => (int) $user->id, 'token' => $token];
    }

    private function course(int $campusId, int $teacherId, string $type, int $subjectId): StudentClass
    {
        $student = Student::create([
            'name' => '日粒度學生', 'CampusID' => $campusId, 'ClassID' => 7,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        return StudentClass::create([
            'StudentID' => $student->id, 'TeacherID' => $teacherId, 'GradeID' => 7,
            'SubjectID' => $subjectId, 'ClassType' => $type, 'by1' => 1,
            'ScheduleMode' => 'count', 'SessionCount' => 8, 'RemainingSessions' => 6,
            'UsedSessions' => 2, 'Rate' => 500, 'Charge' => 4000, 'Pay' => 0, 'Paid' => 0,
            'Period' => 4, 'SessionDuration' => 120, 'TotalHours' => 16,
            'StartDate' => '2026-08-01', 'EndDate' => '2026-08-31', 'week' => 1,
            'time' => '16:00:00', 'Stop' => 0, 'MDate' => now(),
        ]);
    }

    private function makeSession(StudentClass $course, string $date, string $start, string $end, string $status): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date,
            'StartTime' => $start, 'EndTime' => $end, 'Status' => $status, 'Note' => '',
        ]);
    }

    private function sessionWithApprovedRecord(StudentClass $course, int $teacherId, string $date): void
    {
        $session = $this->makeSession($course, $date, '16:00:00', '18:00:00', 'completed');
        LearningRecord::create([
            'StudentClassID' => $course->ID, 'ClassSessionID' => $session->id,
            'TeacherID' => $teacherId, 'Content' => '權限評量', 'Subject' => 'Math',
            'Status' => 'approved', 'ApprovedBy' => $teacherId, 'ApprovedAt' => now(),
            'SessionDate' => $date, 'StartTime' => '16:00:00', 'EndTime' => '18:00:00',
            'SessionDeducted' => true,
        ]);
    }

    private function authHeaders(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
