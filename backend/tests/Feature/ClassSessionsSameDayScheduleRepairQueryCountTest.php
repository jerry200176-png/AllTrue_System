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

/**
 * in-app #319 (GH-3069): Teacher Home polls same-day GET /class-sessions every minute.
 * autoMaterializeScheduledExceptionsForRange used to run ~5 queries per schedule
 * exception of the day (campus-wide), even when every slot was already materialized.
 */
class ClassSessionsSameDayScheduleRepairQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_day_read_query_count_does_not_grow_with_materialized_schedule_exceptions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-08 10:00:00', 'Asia/Taipei'));
        try {
            $count2 = $this->queryCountWithExceptions(2);
            $count8 = $this->queryCountWithExceptions(8);

            // Before the fix: +6 exceptions added ~30 queries. Now only the response
            // rows grow (no per-schedule repair queries).
            $this->assertLessThanOrEqual(8, $count8 - $count2, "N+1 in schedule read-repair: 2={$count2}, 8={$count8}");
        } finally {
            Carbon::setTestNow();
        }
    }

    private function queryCountWithExceptions(int $n): int
    {
        $teacher = User::create([
            'LoginName' => 'teacher-319-' . uniqid() . '@example.com', 'Name' => '速度老師', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0911000319', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacher->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        for ($i = 0; $i < $n; $i++) {
            $student = Student::create([
                'name' => "速度學生{$i}", 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
            ]);
            $course = StudentClass::create([
                'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacher->id,
                'by1' => 1, 'Period' => 4, 'StartDate' => '2026-05-01', 'EndDate' => '2026-05-31',
                'TotalHours' => 20, 'Charge' => 0, 'Paid' => 1, 'Rate' => 500, 'MDate' => now(),
                'Stop' => 0, 'ScheduleMode' => 'date', 'SessionDuration' => 120, 'week' => 5, 'time' => '15:00:00',
            ]);
            ClassSession::create([
                'StudentClassID' => $course->ID, 'SessionDate' => '2026-05-08',
                'StartTime' => '15:00:00', 'EndTime' => '17:00:00', 'Status' => 'scheduled',
            ]);
            Schedule::create([
                'student_id' => $student->id, 'teacher_id' => $teacher->id, 'day_of_week' => 5,
                'start_time' => '15:00:00', 'end_time' => '17:00:00', 'status' => 'scheduled', 'type' => 'normal',
                'deduction' => 1, 'branch_id' => 1, 'schedule_date' => '2026-05-08', 'student_course_id' => $course->ID,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/class-sessions?start=2026-05-08&end=2026-05-08&per_page=200')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
