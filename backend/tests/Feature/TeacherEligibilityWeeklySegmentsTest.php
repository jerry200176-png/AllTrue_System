<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherEligibilityWeeklySegmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_sees_attendance_backed_weekly_segments_and_traceable_courses(): void
    {
        $director = $this->createDirector();
        $teacher = $this->createTeacher();

        // 8 x 2h regular = 8 segments; 1v2 and 1v3 x 4h = 4 segments;
        // 5 attended trials = 5 fixed segments; total = 17. Tutoring is 0.
        foreach (['08:00', '08:10', '08:20', '08:30', '08:40', '08:50', '09:00', '09:10'] as $start) {
            $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-03', $start, 120);
        }
        $this->createAttendedSession($teacher->id, 'one_on_two', '2026-08-04', '10:00', 240);
        $this->createAttendedSession($teacher->id, 'one_on_three', '2026-08-05', '10:00', 240);
        foreach (['08:00', '08:10', '08:20', '08:30', '08:40'] as $start) {
            $this->createAttendedSession($teacher->id, 'trial', '2026-08-06', $start, 120, 'trial', 'trial');
        }
        $this->createAttendedSession($teacher->id, 'tutoring', '2026-08-07', '10:00', 120, 'tutoring_attend', 'tutoring');

        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-07', '14:00', 120, 'cancelled', 'present');
        $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-07', '16:00', 120, 'leave', 'leave');
        $voided = $this->createAttendedSession($teacher->id, 'one_on_one', '2026-08-07', '18:00', 120);
        DB::table('StudentSingIn')->where('ClassSessionID', $voided->id)->update([
            'VoidedAt' => now(),
            'VoidReason' => 'fixture',
        ]);

        DB::table('fulltime_salary_profiles')->insert([
            'teacher_id' => $teacher->id,
            'branch_id' => 1,
            'base_salary' => 33000,
            'effective_from' => '2026-08-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders($this->auth($director['token']))
            ->getJson('/api/v1/finance/teacher-eligibility?period=week&start=2026-08-03&end=2026-08-09&branch_id=1');

        $response->assertOk();
        $response->assertJsonPath('teachers.0.teacher_id', $teacher->id);
        $response->assertJsonPath('teachers.0.components.weekly_16_segments.status', 'qualifies');
        $response->assertJsonPath('teachers.0.components.weekly_16_segments.metrics.regular_segments', 12);
        $response->assertJsonPath('teachers.0.components.weekly_16_segments.metrics.trial_segments', 5);
        $response->assertJsonPath('teachers.0.components.weekly_16_segments.metrics.total_segments', 17);
        $response->assertJsonPath('teachers.0.components.weekly_16_segments.metrics.meets_16_segments', true);
        $response->assertJsonPath('teachers.0.settlement.calculated_payout', 34000);
        $response->assertJsonPath('teachers.0.settlement.calculation_status', 'partial');
        $response->assertJsonPath('teachers.0.review_required', true);
        $this->assertContains('holiday_16_hours', array_column($response->json('teachers.0.settlement.pending_items'), 'code'));

        $sessions = $response->json('teachers.0.components.weekly_16_segments.metrics.course_sessions');
        $this->assertCount(16, $sessions);
        $this->assertSame(12, collect($sessions)->where('segment_type', 'regular_duration')->sum('segments'));
        $this->assertSame(5, collect($sessions)->where('segment_type', 'trial_fixed')->count());
        $this->assertSame(1, collect($sessions)->where('segment_type', 'tutoring_excluded')->count());
        $this->assertFalse($response->json('teachers.0.components.weekly_16_segments.metrics.course_sessions.0.class_session_id') === null);

        $monthResponse = $this->withHeaders($this->auth($director['token']))
            ->getJson('/api/v1/finance/teacher-eligibility?period=month&start=2026-08-01&end=2026-08-31&branch_id=1');
        $monthResponse->assertOk();
        $monthResponse->assertJsonPath('teachers.0.settlement.calculated_payout', 34000);
        $monthResponse->assertJsonPath('teachers.0.components.weekly_16_segments.metrics.total_segments', 17);
        $this->assertTrue(collect($monthResponse->json('teachers.0.components.weekly_16_segments.metrics.weeks'))
            ->contains(fn ($week) => ($week['metrics']['meets_16_segments'] ?? false) === true));
    }

    private function createDirector(): array
    {
        $user = User::create([
            'LoginName' => 'weekly-director@test.com', 'Name' => '薪資主任', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0911000000', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['token' => $token];
    }

    private function createTeacher(): User
    {
        $teacher = User::create([
            'LoginName' => 'weekly-teacher@test.com', 'Name' => '每週段數老師', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922000000', 'MustChangePassword' => false,
            'employment_type' => 'full_time',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        return $teacher;
    }

    private function createAttendedSession(
        int $teacherId,
        string $classType,
        string $date,
        string $start,
        int $minutes,
        string $sessionStatus = 'completed',
        string $attendanceStatus = 'present'
    ): ClassSession {
        $student = Student::create([
            'name' => '段數案例學生', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'TeacherID' => $teacherId,
            'GradeID' => 1, 'SubjectID' => 1, 'ClassType' => $classType, 'by1' => 1,
            'ScheduleMode' => 'count', 'SessionCount' => 20, 'RemainingSessions' => 20,
            'Rate' => 500, 'Charge' => 5000, 'Paid' => 0, 'Period' => 4,
            'SessionDuration' => $minutes, 'TotalHours' => 40,
            'StartDate' => '2026-08-01', 'EndDate' => '2026-08-31',
            'RoomID' => '', 'MDate' => now(),
        ]);
        $end = date('H:i', strtotime($start . ':00 +' . $minutes . ' minutes'));
        $session = ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date,
            'StartTime' => $start . ':00', 'EndTime' => $end . ':00',
            'Status' => $sessionStatus, 'Note' => '',
        ]);
        StudentSignIn::create([
            'StudentClassID' => $course->ID, 'StudentID' => $student->id,
            'TeacherID' => $teacherId, 'ClassSessionID' => $session->id,
            'Status' => $attendanceStatus, 'SignInDT' => $date . ' ' . $start . ':00',
            'SignOutDT' => $date . ' ' . $end . ':00',
        ]);
        return $session;
    }

    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
