<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * in-app #207: editing StudentClass.TeacherID must not rewrite who taught past attended sessions.
 */
class ContractTeacherChangePreservesHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_contract_teacher_keeps_past_attended_session_on_former_teacher(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21 10:00:00', 'Asia/Taipei'));
        try {
            $token = $this->directorToken([1]);
            $oldTeacherId = $this->teacher(1, 'coco-history@example.com', 'Coco');
            $newTeacherId = $this->teacher(1, 'zou-history@example.com', '鄒宇旻');

            $student = Student::create([
                'name' => '陳宇斯測試',
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
                'MDT' => now(),
                'Notify_Token' => '',
            ]);

            $course = StudentClass::create([
                'StudentID' => $student->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => $oldTeacherId,
                'by1' => 1,
                'Period' => 8,
                'StartDate' => '2026-06-01',
                'TotalHours' => 16,
                'Charge' => 0,
                'Rate' => 1000,
                'SessionCount' => 8,
                'RemainingSessions' => 5,
                'SessionDuration' => 120,
                'week' => 5,
                'time' => '16:00:00',
                'class_type' => 'one_on_one',
                'ScheduleMode' => 'count',
                'Stop' => 0,
                'Paid' => 1,
                'MDT' => now(),
            ]);

            $past = ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-07-18',
                'StartTime' => '16:00:00',
                'EndTime' => '18:00:00',
                'Status' => 'attended',
            ]);
            StudentSignIn::create([
                'StudentClassID' => $course->ID,
                'StudentID' => $student->id,
                'TeacherID' => $oldTeacherId,
                'GradeID' => 1,
                'SubjectID' => 1,
                'CampusID' => 1,
                'SignInDT' => '2026-07-18 16:05:00',
                'MDT' => now(),
                'ClassSessionID' => $past->id,
                'Status' => 'present',
                'SessionDeducted' => 1,
            ]);
            LearningRecord::create([
                'StudentClassID' => $course->ID,
                'ClassSessionID' => $past->id,
                'TeacherID' => $oldTeacherId,
                'Status' => 'approved',
                'Content' => 'past',
                'SessionDate' => '2026-07-18',
                'StartTime' => '16:00',
                'EndTime' => '18:00',
            ]);

            $future = ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => '2026-07-25',
                'StartTime' => '16:00:00',
                'EndTime' => '18:00:00',
                'Status' => 'scheduled',
            ]);
            Schedule::create([
                'student_id' => $student->id,
                'teacher_id' => $oldTeacherId,
                'subject' => 'Math',
                'day_of_week' => 5,
                'start_time' => '16:00',
                'end_time' => '18:00',
                'duration_hours' => 2,
                'class_type' => 'one_on_one',
                'status' => 'scheduled',
                'type' => 'normal',
                'deduction' => 1,
                'branch_id' => 1,
                'schedule_date' => '2026-07-25',
                'student_course_id' => $course->ID,
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->putJson("/api/v1/student-classes/{$course->ID}", [
                'teacher_id' => $newTeacherId,
            ]);
            $res->assertOk();

            $course->refresh();
            $this->assertSame($newTeacherId, (int) $course->TeacherID);

            $futureSched = Schedule::where('student_course_id', $course->ID)
                ->whereDate('schedule_date', '2026-07-25')
                ->where('status', 'scheduled')
                ->whereNull('original_schedule_id')
                ->first();
            $this->assertNotNull($futureSched);
            $this->assertSame($newTeacherId, (int) $futureSched->teacher_id);

            $list = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->getJson("/api/v1/class-sessions?branch_id=1&student_class_id={$course->ID}&per_page=100");
            $list->assertOk();
            $pastHit = collect($list->json('data'))->firstWhere('id', $past->id);
            $futureHit = collect($list->json('data'))->firstWhere('id', $future->id);
            $this->assertNotNull($pastHit);
            $this->assertNotNull($futureHit);

            $this->assertSame($oldTeacherId, (int) ($pastHit['teacher_id'] ?? 0), 'in-app #207: past attended must stay on former teacher');
            $this->assertSame('Coco', $pastHit['teacher_name'] ?? '');
            $this->assertSame($newTeacherId, (int) ($futureHit['teacher_id'] ?? 0), 'future sessions follow new contract teacher');
            $this->assertSame('鄒宇旻', $futureHit['teacher_name'] ?? '');
        } finally {
            Carbon::setTestNow();
        }
    }

    private function directorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director-207-' . bin2hex(random_bytes(4)) . '@test.com',
            'Name' => '主任測試207',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912000207',
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }
        $tok = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $tok,
            'expires_at' => now()->addDay(),
        ]);

        return $tok;
    }

    private function teacher(int $campusId, string $login, string $name): int
    {
        $user = User::create([
            'LoginName' => $login,
            'Name' => $name,
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0912' . substr(md5($login), 0, 6),
        ]);
        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $user->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        return (int) $user->id;
    }
}
