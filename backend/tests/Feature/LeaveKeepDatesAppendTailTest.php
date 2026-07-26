<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\CourseLeaveCascadeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Founder Decision 2026-07-26 / §R82 — ordinary leave keeps future dates.
 */
class LeaveKeepDatesAppendTailTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_leaves_append_two_tails_without_moving_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00', 'Asia/Taipei'));
        $token = $this->createDirectorToken([1], 'director-two-leaves@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-two-leaves@example.com');
        $student = $this->createStudent(1, '兩次請假');

        $dates = [
            '2026-07-07', '2026-07-14', '2026-07-21', '2026-07-28',
            '2026-08-04', '2026-08-11', '2026-08-18', '2026-08-25',
        ];
        $this->createCourseViaBatchApi($token, $student->id, $teacherId, [
            'total_classes' => 8,
            'confirmed_dates' => [],
            'future_dates' => $dates,
            'days_of_week' => [2],
            'start_time' => '19:00',
        ])->assertCreated();

        $courseId = (int) DB::table('StudentClass')->where('StudentID', $student->id)->max('ID');
        $beforeById = ClassSession::where('StudentClassID', $courseId)
            ->get()
            ->mapWithKeys(fn ($s) => [(int) $s->id => Carbon::parse($s->SessionDate)->toDateString()])
            ->all();

        foreach (['2026-07-21', '2026-08-04'] as $leaveDate) {
            $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->postJson('/api/v1/schedules', [
                'student_id' => $student->id,
                'teacher_id' => $teacherId,
                'subject' => 'Math',
                'day_of_week' => 2,
                'start_time' => '19:00',
                'end_time' => '21:00',
                'duration_hours' => 2,
                'class_type' => 'one_on_one',
                'status' => 'leave',
                'type' => 'normal',
                'deduction' => 0,
                'branch_id' => 1,
                'schedule_date' => $leaveDate,
                'student_course_id' => $courseId,
            ])->assertCreated();
        }

        foreach ($beforeById as $id => $date) {
            $row = ClassSession::findOrFail($id);
            $this->assertSame($date, Carbon::parse($row->SessionDate)->toDateString(), "id {$id} must not move");
        }

        $this->assertSame(10, ClassSession::where('StudentClassID', $courseId)->count());
        $this->assertSame(2, ClassSession::where('StudentClassID', $courseId)->where('Status', 'leave')->count());
        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-07-28',
            'Status' => 'scheduled',
        ]);
    }

    public function test_undo_keep_dates_removes_provenance_tail_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00', 'Asia/Taipei'));
        $token = $this->createDirectorToken([1], 'director-undo-keep@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-undo-keep@example.com');
        $student = $this->createStudent(1, '撤銷保留日期');

        $dates = ['2026-07-07', '2026-07-14', '2026-07-21', '2026-07-28'];
        $this->createCourseViaBatchApi($token, $student->id, $teacherId, [
            'total_classes' => 4,
            'confirmed_dates' => [],
            'future_dates' => $dates,
            'days_of_week' => [2],
            'start_time' => '19:00',
        ])->assertCreated();
        $courseId = (int) DB::table('StudentClass')->where('StudentID', $student->id)->max('ID');

        $leaveRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'day_of_week' => 2,
            'start_time' => '19:00',
            'end_time' => '21:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'leave',
            'type' => 'normal',
            'deduction' => 0,
            'branch_id' => 1,
            'schedule_date' => '2026-07-21',
            'student_course_id' => $courseId,
        ])->assertCreated();

        $scheduleId = (int) $leaveRes->json('schedule.id');
        $this->assertSame(5, ClassSession::where('StudentClassID', $courseId)->count());
        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-07-28',
            'Status' => 'scheduled',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/schedules/{$scheduleId}/undo-leave")->assertOk();

        $this->assertSame(4, ClassSession::where('StudentClassID', $courseId)->count());
        $leave = ClassSession::where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '2026-07-21')
            ->firstOrFail();
        $this->assertSame('scheduled', strtolower((string) $leave->Status));
        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-07-28',
            'Status' => 'scheduled',
        ]);
    }

    public function test_repair_leave_vacated_weeks_dry_run_does_not_write(): void
    {
        $courseId = $this->seedLegacyShiftCourse();
        $before = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate')
            ->get()
            ->map(fn ($s) => Carbon::parse($s->SessionDate)->toDateString() . ':' . $s->Status)
            ->all();

        $exit = Artisan::call('repair:leave-vacated-weeks', [
            '--dry-run' => true,
            '--course-id' => $courseId,
            '--limit' => 50,
        ]);
        $this->assertSame(0, $exit);

        $after = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate')
            ->get()
            ->map(fn ($s) => Carbon::parse($s->SessionDate)->toDateString() . ':' . $s->Status)
            ->all();
        $this->assertSame($before, $after);
    }

    /** @param  array<int>  $campusIds */
    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
            'MustChangePassword' => false,
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);
        return $token;
    }

    private function createTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName,
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922000000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);
        return (int) $teacher->id;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function createCourseViaBatchApi(string $token, int $studentId, int $teacherId, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'branch_id' => 1,
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 8,
            'confirmed_dates' => [],
            'future_dates' => [],
            'days_of_week' => [3],
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
            'start_time' => '16:00',
        ], $overrides);

        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', $payload);
    }

    private function seedLegacyShiftCourse(): int
    {
        DB::table('Student')->insert([
            'id' => 88901,
            'name' => 'Vacated Week Fixture',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
        ]);
        $courseId = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => 88901,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 88902,
            'by1' => 1,
            'Period' => 4,
            'TotalHours' => 0,
            'Charge' => 0,
            'Pay' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'ClassType' => 'one_on_one',
            'StartDate' => '2026-08-01',
            'EndDate' => '2026-09-12',
            'SessionCount' => 4,
            'SessionDuration' => 120,
            'RemainingSessions' => 4,
            'UsedSessions' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'week' => 2,
            'time' => '19:00:00',
        ]);

        DB::table('ClassSession')->insert([
            [
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-08-04',
                'StartTime' => '19:00:00',
                'EndTime' => '21:00:00',
                'Status' => 'leave',
                'Note' => 'leave; leave-policy-shift',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-08-18',
                'StartTime' => '19:00:00',
                'EndTime' => '21:00:00',
                'Status' => 'scheduled',
                'Note' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-08-25',
                'StartTime' => '19:00:00',
                'EndTime' => '21:00:00',
                'Status' => 'scheduled',
                'Note' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-09-01',
                'StartTime' => '19:00:00',
                'EndTime' => '21:00:00',
                'Status' => 'scheduled',
                'Note' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-09-08',
                'StartTime' => '19:00:00',
                'EndTime' => '21:00:00',
                'Status' => 'scheduled',
                'Note' => 'auto-extended-after-leave',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $leaveId = (int) DB::table('ClassSession')
            ->where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '2026-08-04')
            ->value('id');
        DB::table('ClassSession')
            ->where('StudentClassID', $courseId)
            ->whereDate('SessionDate', '2026-09-08')
            ->update([
                'Note' => CourseLeaveCascadeService::buildAutoExtendedNote('2026-08-04', $leaveId) . '; leave-policy-shift',
            ]);

        return $courseId;
    }
}
