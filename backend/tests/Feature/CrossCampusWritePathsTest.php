<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ScheduleGuardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Write paths outside the capacity guard still refuse a teacher at two campuses at once (#5588). */
class CrossCampusWritePathsTest extends TestCase
{
    use RefreshDatabase;

    public function test_retiming_one_lesson_onto_another_campus_lesson_is_refused(): void
    {
        $teacher = $this->teacher();
        $date = Carbon::today()->addDays(7)->toDateString();
        $this->lesson($this->course($teacher, 2, '甲'), $date, '18:00', '20:00');
        $mine = $this->lesson($this->course($teacher, 1, '乙'), $date, '14:00', '16:00');
        $api = $this->withHeaders(['Authorization' => 'Bearer ' . $this->directorToken(1), 'Accept' => 'application/json']);

        $api->patchJson("/api/v1/class-sessions/{$mine}", ['status' => 'scheduled', 'start_time' => '19:00', 'end_time' => '21:00'])
            ->assertStatus(409)->assertJsonPath('code', 'teacher_schedule_conflict')->assertJsonPath('conflicts.0.type', 'cross_campus');
        $this->assertSame('14:00', substr((string) ClassSession::findOrFail($mine)->StartTime, 0, 5), 'nothing written');

        $api->patchJson("/api/v1/class-sessions/{$mine}", ['status' => 'scheduled', 'start_time' => '20:00', 'end_time' => '22:00'])
            ->assertOk();

        // An existing clash never blocks a save that resends the same times (e.g. a note edit).
        $clash = $this->lesson($this->course($teacher, 1, '丁'), $date, '18:30', '20:30');
        $api->patchJson("/api/v1/class-sessions/{$clash}", ['status' => 'scheduled', 'start_time' => '18:30', 'end_time' => '20:30', 'note' => '備註'])
            ->assertOk();
    }

    public function test_lesson_helper_uses_the_occurrence_teacher_and_skips_ended_lessons(): void
    {
        $teacher = $this->teacher();
        $guard = app(ScheduleGuardService::class);
        $mine = $this->course($teacher, 1, '乙');
        $date = Carbon::today()->addDays(7)->toDateString();
        $this->lesson($this->course($teacher, 2, '甲'), $date, '18:00', '20:00');

        $this->assertSame('cross_campus', $guard->crossCampusConflictForLesson($mine, $date, '19:00', '21:00')['type'] ?? null);
        $this->assertNull($guard->crossCampusConflictForLesson($mine, $date, '20:00', '22:00'));

        $past = Carbon::today()->subDays(2)->toDateString();
        $this->lesson($this->course($teacher, 2, '丙'), $past, '18:00', '20:00');
        $this->assertNull($guard->crossCampusConflictForLesson($mine, $past, '18:00', '20:00'));
    }

    public function test_weekly_helper_for_new_package_courses(): void
    {
        $teacher = $this->teacher();
        $this->course($teacher, 2, '甲', ['week' => 3, 'time' => '16:00']);
        $guard = app(ScheduleGuardService::class);
        $slot = fn (int $day, string $start) => [['day_of_week' => $day, 'start_time' => $start, 'end_time' => Carbon::parse($start)->addHours(2)->format('H:i')]];
        $today = Carbon::today()->toDateString();

        $this->assertSame('cross_campus', $guard->crossCampusWeeklyConflict($teacher, 1, $slot(3, '17:00'), null, $today, null)['type'] ?? null);
        $this->assertNull($guard->crossCampusWeeklyConflict($teacher, 1, $slot(4, '17:00'), null, $today, null));
        $this->assertNull($guard->crossCampusWeeklyConflict($teacher, 2, $slot(3, '17:00'), null, $today, null), 'same campus is not cross-campus');
    }

    public function test_makeup_candidates_skip_times_taught_at_another_campus(): void
    {
        $teacher = $this->teacher();
        $day = Carbon::today()->addDays(7)->toDateString();
        $this->lesson($this->course($teacher, 2, '甲'), $day, '13:00', '15:00');
        $mine = $this->course($teacher, 1, '乙');
        $missed = $this->lesson($mine, Carbon::today()->addDays(6)->toDateString(), '09:00', '11:00');
        $workflow = \App\Models\ExceptionWorkflow::create([
            'source_key' => "parent_leave:class_session:{$missed}", 'campus_id' => 1, 'student_id' => $mine->StudentID,
            'student_class_id' => $mine->ID, 'class_session_id' => $missed, 'type' => 'student_leave', 'status' => 'open',
        ]);

        $candidates = app(\App\Services\ExceptionWorkflowCandidateGenerator::class)->generate($workflow, $day, $day);

        $this->assertNotEmpty($candidates);
        foreach ($candidates as $c) {
            $this->assertFalse($c['start_time'] < '15:00' && $c['end_time'] > '13:00', "offered {$c['start_time']} clashes at campus 2");
        }
        $this->assertSame(range(1, count($candidates)), array_column($candidates, 'rank'), 'ranks stay consecutive');
    }

    private function teacher(): int
    {
        $teacher = User::create(['LoginName' => 'write-path-teacher@example.com', 'Name' => '老師', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922000000', 'MustChangePassword' => false]);
        foreach ([1, 2] as $campus) {
            UserCampus::create(['CampusID' => $campus, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        }

        return (int) $teacher->id;
    }

    private function directorToken(int $campus): string
    {
        $director = User::create(['LoginName' => 'write-path-director@example.com', 'Name' => '主任', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0912345678', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function course(int $teacher, int $campus, string $student, array $extra = []): StudentClass
    {
        $studentId = Student::create(['name' => $student, 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => ''])->id;
        $id = DB::table('StudentClass')->insertGetId($extra + [
            'StudentID' => $studentId, 'TeacherID' => $teacher, 'ClassType' => 'one_on_one',
            'GradeID' => 1, 'SubjectID' => 1, 'by1' => 1, 'Period' => 4, 'StartDate' => Carbon::today()->subMonth()->toDateString(),
            'TotalHours' => 16, 'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 8,
            'UsedSessions' => 0, 'Charge' => 500, 'Pay' => 0, 'Paid' => 0, 'Rate' => 500, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);

        return StudentClass::findOrFail($id);
    }

    private function lesson(StudentClass $course, string $date, string $start, string $end): int
    {
        return (int) ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => $date,
            'StartTime' => $start . ':00', 'EndTime' => $end . ':00', 'Status' => 'scheduled'])->id;
    }
}
