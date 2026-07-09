<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceSubjectNameResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_subject_name_resolved_from_student_class(): void
    {
        [$token, $student, $teacherId, $subject] = $this->scaffold();

        $course = $this->createCourse($student->id, $teacherId, $subject->id);
        $session = $this->createSession($course->ID);

        StudentSignIn::create([
            'StudentID'      => $student->id,
            'StudentClassID' => $course->ID,
            'TeacherID'      => $teacherId,
            'SubjectID'      => $subject->id,
            'SignInDT'       => now()->toDateTimeString(),
            'Status'         => 'present',
            'ClassSessionID' => $session->id,
            'CampusID'       => 1,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/attendance?date=' . now()->toDateString() . '&branch_id=1');

        $res->assertOk();
        $row = collect($res->json('data'))->first();
        $this->assertNotNull($row);
        $this->assertSame('English', $row['subject_name']);
    }

    public function test_subject_name_falls_back_to_sign_in_when_contract_has_orphaned_subject(): void
    {
        [$token, $student, $teacherId, $subject] = $this->scaffold();

        // SubjectID 9999 doesn't exist in Subject table, simulating orphaned legacy ID
        $course = $this->createCourse($student->id, $teacherId, 9999);
        $session = $this->createSession($course->ID);

        StudentSignIn::create([
            'StudentID'      => $student->id,
            'StudentClassID' => $course->ID,
            'TeacherID'      => $teacherId,
            'SubjectID'      => $subject->id,
            'SignInDT'       => now()->toDateTimeString(),
            'Status'         => 'present',
            'ClassSessionID' => $session->id,
            'CampusID'       => 1,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/attendance?date=' . now()->toDateString() . '&branch_id=1');

        $res->assertOk();
        $row = collect($res->json('data'))->first();
        $this->assertNotNull($row);
        $this->assertSame('English', $row['subject_name'], 'Should fall back to StudentSingIn.SubjectID');
    }

    public function test_subject_name_blank_when_both_sides_orphaned(): void
    {
        [$token, $student, $teacherId] = $this->scaffold();

        // Both sides reference non-existent Subject IDs
        $course = $this->createCourse($student->id, $teacherId, 9999);
        $session = $this->createSession($course->ID);

        StudentSignIn::create([
            'StudentID'      => $student->id,
            'StudentClassID' => $course->ID,
            'TeacherID'      => $teacherId,
            'SubjectID'      => 8888,
            'SignInDT'       => now()->toDateTimeString(),
            'Status'         => 'present',
            'ClassSessionID' => $session->id,
            'CampusID'       => 1,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->getJson('/api/v1/attendance?date=' . now()->toDateString() . '&branch_id=1');

        $res->assertOk();
        $row = collect($res->json('data'))->first();
        $this->assertNotNull($row);
        $this->assertSame('', $row['subject_name']);
    }

    // ── scaffolding ──

    private function scaffold(): array
    {
        Campus::firstOrCreate(['id' => 1], ['name' => '測試分校', 'code' => 'T1', 'Current' => 1]);

        $subject = Subject::create([
            'School_id'    => 1,
            'Grade_no'     => 0,
            'Subject_Name' => 'English',
        ]);

        $user = User::create([
            'LoginName'          => 'dir-subj-test@example.com',
            'Name'               => '主任',
            'PSW'                => 'secret',
            'type'               => 'A',
            'phone'              => '0900000000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);

        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        $teacher = User::create([
            'LoginName'          => 'tch-subj-test@example.com',
            'Name'               => '老師',
            'PSW'                => 'secret',
            'type'               => 'T',
            'phone'              => '0911000000',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name'     => '科目解析測試生',
            'CampusID' => 1,
            'ClassID'  => 1,
            'enable'   => 1,
            'MDT'      => now(),
            'Notify_Token' => '',
        ]);

        return [$raw, $student, (int) $teacher->id, $subject];
    }

    private function createCourse(int $studentId, int $teacherId, ?int $subjectId): StudentClass
    {
        return StudentClass::create([
            'StudentID'         => $studentId,
            'GradeID'           => 1,
            'SubjectID'         => $subjectId,
            'TeacherID'         => $teacherId,
            'by1'               => 1,
            'Period'            => 4,
            'StartDate'         => '2026-03-01',
            'TotalHours'        => 20,
            'Memo'              => 'subject-test',
            'Paid'              => 0,
            'Stop'              => 0,
            'ScheduleMode'      => 'count',
            'SessionCount'      => 8,
            'RemainingSessions' => 8,
            'UsedSessions'      => 0,
            'SessionDuration'   => 120,
            'ClassType'         => 'one_on_one',
            'MDate'             => now(),
            'Rate'              => 500,
        ]);
    }

    private function createSession(int $courseId): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate'    => now()->toDateString(),
            'StartTime'      => '14:00',
            'EndTime'        => '16:00',
            'Status'         => 'completed',
            'Note'           => '',
        ]);
    }
}
