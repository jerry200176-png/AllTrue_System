<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #985 / #1822: director fill-rate report must credit the substitute teacher.
 */
class DirectorTeacherLearningFillRatesSubstituteTest extends TestCase
{
    use RefreshDatabase;

    public function test_attended_session_credits_substitute_teacher_not_original(): void
    {
        $campusId = 1;

        $director = User::create([
            'LoginName' => 'dir-fillrate@example.com', 'Name' => '主任', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0910000002', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        $t1 = User::create([
            'LoginName' => 't1-fillrate@example.com', 'Name' => '契約老師T1', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0911003001', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $t1->id, 'Admin' => 0, 'Approved' => 1]);

        $t2 = User::create([
            'LoginName' => 't2-fillrate@example.com', 'Name' => '代課老師T2', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0911003002', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $t2->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => '填寫率代課測試生', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $t1->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->toDateString(), 'TotalHours' => 20,
            'SessionCount' => 10, 'SessionDuration' => 120,
            'RemainingSessions' => 10, 'UsedSessions' => 1,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);

        $sessionDate = now()->toDateString();
        $cs = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => $sessionDate,
            'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'attended',
        ]);

        $anchor = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $t1->id,
            'day_of_week' => 5,
            'start_time' => '14:00',
            'end_time' => '16:00',
            'status' => 'rescheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => $campusId,
            'schedule_date' => $sessionDate,
            'student_course_id' => $sc->ID,
        ]);

        Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $t2->id,
            'day_of_week' => 5,
            'start_time' => '14:00',
            'end_time' => '16:00',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => $campusId,
            'schedule_date' => $sessionDate,
            'student_course_id' => $sc->ID,
            'original_schedule_id' => $anchor->id,
        ]);

        LearningRecord::create([
            'ClassSessionID' => $cs->id,
            'StudentClassID' => $sc->ID,
            'TeacherID' => $t2->id,
            'Content' => '本堂已完成第 5 章練習',
            'Progress' => '本堂已完成第 5 章練習',
            'Status' => 'pending',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/reports/teacher-learning-fill-rates?' . http_build_query([
            'branch_id' => $campusId,
            'days' => 14,
        ]));

        $res->assertOk();
        $rows = collect($res->json('teachers') ?? []);
        $t1Row = $rows->firstWhere('teacher_id', (int) $t1->id);
        $t2Row = $rows->firstWhere('teacher_id', (int) $t2->id);

        $this->assertNull($t1Row, '契約老師 T1 被代課後不應計入其填寫率統計');
        $this->assertNotNull($t2Row, '代課老師 T2 應計入填寫率統計');
        $this->assertSame(1, (int) $t2Row['sessions_attended']);
        $this->assertSame(1, (int) $t2Row['learning_records_filled']);
    }
}
