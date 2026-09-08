<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GlobalSearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_and_short_queries_are_bounded_without_querying_entities(): void
    {
        $headers = $this->authHeaders($this->staff('director', 1));

        $this->withHeaders($headers)->getJson('/api/v1/global-search')->assertOk()
            ->assertJsonPath('groups', [])
            ->assertJsonPath('min_query_length', 1);
        $this->withHeaders($headers)->getJson('/api/v1/global-search?q=%20')->assertOk()
            ->assertJsonPath('groups', []);
    }

    public function test_director_search_is_campus_scoped_and_ranks_exact_prefix_contains(): void
    {
        $campusA = Campus::factory()->create(['name' => '台北校']);
        $campusB = Campus::factory()->create(['name' => '台中校']);
        $director = $this->staff('director', $campusA->id);

        foreach (['王', '王小明', '小王', '王小華', '王小安'] as $name) {
            Student::factory()->create(['name' => $name, 'CampusID' => $campusA->id]);
        }
        Student::factory()->create(['name' => '王校外', 'CampusID' => $campusB->id]);

        $response = $this->withHeaders($this->authHeaders($director))
            ->getJson('/api/v1/global-search?q=' . urlencode('王') . '&limit=50')
            ->assertOk();
        $students = $response->json('groups.0.items');

        $this->assertCount(5, $students);
        $this->assertSame('王', $students[0]['title'], 'Exact matches must rank before prefix matches.');
        $this->assertEqualsCanonicalizing(
            ['王', '王小安', '王小華', '王小明', '小王'],
            array_column($students, 'title')
        );
        $this->assertSame('小王', $students[4]['title'], 'Contains matches must rank after prefixes.');
        $this->assertNotContains('王校外', array_column($students, 'title'));
        $this->assertSame('分校：台北校', $students[0]['meta']);
    }

    public function test_teacher_and_course_results_reuse_existing_scopes_and_include_session_context(): void
    {
        $campusA = Campus::factory()->create(['name' => '南港校']);
        $campusB = Campus::factory()->create(['name' => '內湖校']);
        $teacher = $this->staff('teacher', $campusA->id, '自己的老師');
        $otherTeacher = $this->staff('teacher', $campusA->id, '同校老師');
        $outsideTeacher = $this->staff('teacher', $campusB->id, '校外老師');
        $student = Student::factory()->create(['name' => '課程學生', 'CampusID' => $campusA->id]);
        $outsideStudent = Student::factory()->create(['name' => '校外學生', 'CampusID' => $campusB->id]);
        $subjectId = DB::table('Subject')->insertGetId([
            'School_id' => 1,
            'Grade_no' => 1,
            'Subject_Name' => '搜尋英文',
        ]);

        $ownCourse = $this->course($student->id, $teacher->id, $subjectId);
        $this->course($student->id, $otherTeacher->id, $subjectId);
        $this->course($outsideStudent->id, $outsideTeacher->id, $subjectId);
        $session = ClassSession::create([
            'StudentClassID' => $ownCourse->ID,
            'SessionDate' => now()->addDay()->toDateString(),
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $headers = $this->authHeaders($teacher);
        $teachers = $this->withHeaders($headers)->getJson('/api/v1/global-search?q=' . urlencode('老師'))->assertOk()->json('groups.1.items');
        $this->assertEqualsCanonicalizing(['自己的老師', '同校老師'], array_column($teachers, 'title'));
        $this->assertNotContains('校外老師', array_column($teachers, 'title'));

        $courses = $this->withHeaders($headers)->getJson('/api/v1/global-search?q=' . urlencode('搜尋英文'))->assertOk()->json('groups.2.items');
        $this->assertCount(1, $courses);
        $this->assertSame($ownCourse->ID, $courses[0]['course_id']);
        $this->assertSame($student->name, $courses[0]['student_name']);
        $this->assertStringContainsString(now()->addDay()->toDateString(), $courses[0]['meta']);
        $this->assertSame($session->id, $courses[0]['session_id']);

        $byDate = $this->withHeaders($headers)->getJson('/api/v1/global-search?q=' . now()->addDay()->toDateString())->assertOk()->json('groups.2.items');
        $this->assertSame($ownCourse->ID, $byDate[0]['course_id']);
        $this->assertSame($session->SessionDate, $courses[0]['session_date']);
    }

    public function test_global_search_query_count_stays_bounded_and_unauthorized_campus_is_not_discoverable(): void
    {
        $campusA = Campus::factory()->create();
        $campusB = Campus::factory()->create();
        $director = $this->staff('director', $campusA->id);
        Student::factory()->create(['name' => '可見學生', 'CampusID' => $campusA->id]);
        Student::factory()->create(['name' => '不可見學生', 'CampusID' => $campusB->id]);

        DB::enableQueryLog();
        $response = $this->withHeaders($this->authHeaders($director))
            ->getJson('/api/v1/global-search?q=' . urlencode('學生'))
            ->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(25, $queries, 'Global search should use bounded set queries, not N+1 entity loading.');
        $this->assertSame(['可見學生'], array_column($response->json('groups.0.items'), 'title'));
    }

    private function staff(string $role, int $campusId, string $name = '測試人員'): User
    {
        $user = User::create([
            'LoginName' => strtolower(str_replace(' ', '-', $name)) . '-' . uniqid() . '@example.com',
            'Name' => $name,
            'PSW' => 'test-password',
            'type' => $role === 'teacher' ? 'T' : 'D',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => $role === 'director' ? 1 : 0, 'Approved' => 1]);
        return $user;
    }

    private function authHeaders(User $user): array
    {
        $token = 'global-search-' . $user->id . '-' . uniqid();
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    private function course(int $studentId, int $teacherId, int $subjectId): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $studentId,
            'GradeID' => 7,
            'SubjectID' => $subjectId,
            'TeacherID' => $teacherId,
            'by1' => 0,
            'StartDate' => now()->subDay(),
            'TotalHours' => 10,
            'RoomID' => '',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'date',
            'SessionCount' => 8,
            'SessionDuration' => 120,
        ]);
    }
}
