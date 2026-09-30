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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 月出勤依 ClassSession 判斷遲到／缺卡：有課沒刷的老師要出現；被代課的堂由代課老師負責。 */
class TeacherAttendanceDerivedStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_missed_late_and_substitute(): void
    {
        Carbon::setTestNow('2026-08-31 12:00:00');
        $campusId = 1;
        $token = $this->director($campusId);
        $regular = $this->user('正班老師', 'T', $campusId);
        $sub = $this->user('代課老師', 'T', $campusId);

        $student = Student::create([
            'name' => '學生甲', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $regular->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-08-01', 'TotalHours' => 16,
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        foreach (['2026-08-03', '2026-08-04'] as $date) {
            ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => $date, 'StartTime' => '10:00', 'EndTime' => '12:00', 'Status' => 'scheduled']);
        }
        // 08-04 由代課老師上
        $anchor = Schedule::create($this->schedule($student->id, $regular->id, $sc->ID, $campusId, 'rescheduled'));
        Schedule::create($this->schedule($student->id, $sub->id, $sc->ID, $campusId, 'scheduled') + ['original_schedule_id' => $anchor->id]);
        DB::table('TeacherSingIn')->insert([
            'TeacherID' => $sub->id, 'CampusID' => $campusId, 'SignInDT' => '2026-08-04 10:20:00',
            'SignOutDT' => '2026-08-04 12:00:00', 'MDT' => now(),
        ]);

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/teacher-attendance/monthly?year_month=2026-08')
            ->assertOk();

        $teachers = collect($res->json('teachers'))->keyBy('teacher_name');
        $day = fn (string $name, string $date) => collect($teachers[$name]['days'])->firstWhere('date', $date);

        $this->assertSame('missed', $day('正班老師', '2026-08-03')['status']);   // 有課沒刷，整月沒刷卡也列出來
        $this->assertNull($day('正班老師', '2026-08-04')['status']);             // 被代課，不用到
        $this->assertSame(['late', 20], [$day('代課老師', '2026-08-04')['status'], $day('代課老師', '2026-08-04')['late_minutes']]);
        $this->assertSame(1, $teachers['正班老師']['totals']['missed_days']);
        $this->assertSame(1, $teachers['代課老師']['totals']['late_days']);
    }

    private function schedule(int $studentId, int $teacherId, int $courseId, int $campusId, string $status): array
    {
        return [
            'student_id' => $studentId, 'teacher_id' => $teacherId, 'subject' => '數學',
            'day_of_week' => 2, 'start_time' => '10:00', 'end_time' => '12:00', 'duration_hours' => 2,
            'class_type' => 'one_on_one', 'status' => $status, 'type' => 'regular', 'deduction' => 1,
            'branch_id' => $campusId, 'schedule_date' => '2026-08-04', 'student_course_id' => $courseId,
        ];
    }

    private function user(string $name, string $type, int $campusId, int $admin = 0): User
    {
        $user = User::create([
            'LoginName' => uniqid('derived-') . '@example.com', 'Name' => $name, 'PSW' => 'x',
            'type' => $type, 'phone' => '0900000002', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => $admin, 'Approved' => 1]);

        return $user;
    }

    private function director(int $campusId): string
    {
        $user = $this->user('主任', 'A', $campusId, 1);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }
}
