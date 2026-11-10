<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #5175: the post-deploy acceptance run reads GET /class-sessions/projection on production
 * with a window of two or more days and relies on that read creating nothing
 * (frontend/e2e/fixtures/classSessionReadResponder.js, nonMaterializingProjectionPath).
 *
 * Read-side materialization is limited to one-day windows
 * (ClassSessionController::authorizeAndMaterializeClassSessionIndex). If this test fails,
 * that acceptance read writes to production: stop the acceptance read before changing this test.
 */
class ClassSessionsMultiDayReadDoesNotMaterializeTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_day_list_and_projection_reads_create_no_class_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 09:00:00', 'Asia/Taipei'));
        try {
            $teacher = User::create([
                'LoginName' => 'teacher-multi-day-read@example.com',
                'Name' => '多日讀取老師', 'PSW' => 'secret', 'type' => 'T',
                'phone' => '0911000441', 'MustChangePassword' => false,
            ]);
            $contractTeacher = User::create([
                'LoginName' => 'teacher-multi-day-contract@example.com',
                'Name' => '多日讀取原任老師', 'PSW' => 'secret', 'type' => 'T',
                'phone' => '0911000442', 'MustChangePassword' => false,
            ]);
            $director = User::create([
                'LoginName' => 'director-multi-day-read@example.com',
                'Name' => '多日讀取主任', 'PSW' => 'secret', 'type' => 'A',
                'phone' => '0911000443', 'MustChangePassword' => false,
            ]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $contractTeacher->id, 'Admin' => 0, 'Approved' => 1]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);

            $teacherToken = bin2hex(random_bytes(16));
            AuthToken::create(['user_id' => $teacher->id, 'token' => $teacherToken, 'expires_at' => now()->addDay()]);
            $directorToken = bin2hex(random_bytes(16));
            AuthToken::create(['user_id' => $director->id, 'token' => $directorToken, 'expires_at' => now()->addDay()]);

            $student = Student::create([
                'name' => '多日讀取學生', 'CampusID' => 1, 'ClassID' => 1,
                'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
            ]);
            $course = StudentClass::create([
                'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
                'TeacherID' => $contractTeacher->id, 'by1' => 1, 'Period' => 4,
                'StartDate' => '2026-06-01', 'TotalHours' => 0, 'Charge' => 0,
                'Paid' => 1, 'Rate' => 500, 'MDate' => now(), 'Stop' => 0,
                'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120,
                'RemainingSessions' => 8, 'UsedSessions' => 0, 'ClassType' => 'one_on_one',
            ]);

            // A scheduled exception with no ClassSession: a one-day read repairs it (the control below).
            $anchor = Schedule::create([
                'student_id' => $student->id, 'teacher_id' => $contractTeacher->id,
                'subject' => '數學', 'day_of_week' => 6, 'start_time' => '10:00',
                'end_time' => '12:00', 'duration_hours' => 2, 'class_type' => 'one_on_one',
                'status' => 'rescheduled', 'type' => 'normal', 'deduction' => 1,
                'branch_id' => 1, 'schedule_date' => '2026-06-20',
                'student_course_id' => $course->ID,
            ]);
            Schedule::create([
                'student_id' => $student->id, 'teacher_id' => $teacher->id,
                'subject' => '數學', 'day_of_week' => 6, 'start_time' => '10:00',
                'end_time' => '12:00', 'duration_hours' => 2, 'class_type' => 'one_on_one',
                'status' => 'scheduled', 'type' => 'normal', 'deduction' => 1,
                'branch_id' => 1, 'schedule_date' => '2026-06-20',
                'student_course_id' => $course->ID, 'original_schedule_id' => $anchor->id,
            ]);

            $window = 'start=2026-06-19&end=2026-06-21';
            $multiDayReads = [
                [$teacherToken, '/api/v1/class-sessions?teacher_id=' . $teacher->id . '&' . $window . '&per_page=100'],
                [$teacherToken, '/api/v1/class-sessions/projection?' . $window],
                [$directorToken, '/api/v1/class-sessions?branch_id=1&' . $window . '&per_page=100'],
                [$directorToken, '/api/v1/class-sessions/projection?branch_id=1&' . $window],
            ];
            foreach ($multiDayReads as [$token, $uri]) {
                $this->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ])->getJson($uri)->assertOk();
                $this->assertSame(0, ClassSession::count(), 'a multi-day read must not create a class session: ' . $uri);
            }

            // Control: the same data with a one-day window is materialized, so the loop above
            // was not passing only because there was nothing to create.
            $this->withHeaders([
                'Authorization' => 'Bearer ' . $teacherToken,
                'Accept' => 'application/json',
            ])->getJson('/api/v1/class-sessions?teacher_id=' . $teacher->id
                . '&start=2026-06-20&end=2026-06-20&per_page=100')->assertOk();
            $this->assertDatabaseHas('ClassSession', [
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-06-20',
                'StartTime' => '10:00:00',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
