<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Room;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Calendar branch filter must match StudentClassController: room campus OR (no room + student campus).
 * ClassSession is the calendar projection source — must not be hidden when the contract room is at the branch.
 */
class ClassSessionBranchCampusFilterTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION_DATE = '2026-06-27';

    public function test_branch_filter_includes_session_when_room_at_branch_but_student_campus_differs(): void
    {
        $suffix = substr(uniqid(), -6);
        $xinzhuang = Campus::factory()->create(['name' => '新莊分校', 'code' => 'xz' . $suffix]);
        $other = Campus::factory()->create(['name' => '大安分校', 'code' => 'da' . $suffix]);

        $room = Room::create([
            'name' => '新莊教室A',
            'campus_id' => $xinzhuang->id,
            'capacity' => 4,
            'is_active' => true,
        ]);

        $teacherId = $this->createTeacher((int) $xinzhuang->id);
        $student = Student::create([
            'name' => '轉校學生',
            'CampusID' => $other->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $courseId = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'ClassType' => 'one_on_one',
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 500,
            'LearnTimeID' => null,
            'RoomID' => 'R1',
            'room_id' => $room->id,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'StartDate' => '2026-01-01',
            'by1' => $teacherId,
            'Period' => 4,
            'TotalHours' => 0,
        ]);

        $session = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => self::SESSION_DATE,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $token = $this->createDirectorToken([(int) $xinzhuang->id]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?branch_id=' . $xinzhuang->id
            . '&start=' . self::SESSION_DATE . '&end=' . self::SESSION_DATE . '&per_page=100');

        $res->assertOk();
        $ids = collect($res->json('data') ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains(
            (int) $session->id,
            $ids,
            'ClassSession at branch room must appear when querying that branch_id even if Student.CampusID differs'
        );
    }

    public function test_branch_date_query_returns_sessions_without_student_class_ids_param(): void
    {
        $campus = Campus::factory()->create();
        $teacherId = $this->createTeacher((int) $campus->id);
        $student = Student::create([
            'name' => '本地學生',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $courseId = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'ClassType' => 'one_on_one',
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 500,
            'LearnTimeID' => null,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 1,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'StartDate' => '2026-01-01',
            'by1' => $teacherId,
            'Period' => 4,
            'TotalHours' => 0,
        ]);

        $session = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => self::SESSION_DATE,
            'StartTime' => '10:00',
            'EndTime' => '12:00',
            'Status' => 'attended',
            'Note' => '',
        ]);

        $token = $this->createDirectorToken([(int) $campus->id]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?branch_id=' . $campus->id
            . '&start=' . self::SESSION_DATE . '&end=' . self::SESSION_DATE . '&per_page=100');

        $res->assertOk();
        $hit = collect($res->json('data') ?? [])->firstWhere('id', $session->id);
        $this->assertNotNull($hit, 'Stopped/inactive contract sessions must still load by branch+date for calendar projection');
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-branch-filter-' . uniqid() . '@test.com',
            'Name' => '主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0911111111',
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

    private function createTeacher(int $campusId): int
    {
        $teacher = User::create([
            'LoginName' => 'teacher-branch-' . uniqid() . '@test.com',
            'Name' => '老師',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922111111',
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
}
