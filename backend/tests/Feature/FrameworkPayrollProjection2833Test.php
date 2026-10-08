<?php
namespace Tests\Feature;
use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Database\Factories\CampusFactory;
use Database\Factories\StudentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class FrameworkPayrollProjection2833Test extends TestCase
{
    use RefreshDatabase;
    public function test_approved_session_count_projection_preserves_pin_and_branch_scope(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-07 12:00:00'));
        try {
            $campus = CampusFactory::new()->create();
            $token = $this->createDirectorToken([$campus->id], 'framework-payroll-probe@example.com');

            $teacher = User::create([
                'LoginName' => 'completion-teacher@example.com',
                'Name' => '完成率老師',
                'PSW' => 'secret',
                'type' => 'T',
                'phone' => 912345678,
            ]);
            UserCampus::create([
                'CampusID' => $campus->id,
                'UserID' => $teacher->id,
                'Admin' => 0,
                'Approved' => 1,
            ]);

            $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
            $scId = DB::table('StudentClass')->insertGetId([
                'StudentID' => $student->id,
                'TeacherID' => $teacher->id,
                'GradeID' => 1,
                'SubjectID' => 1,
                'ClassType' => 'one_on_one',
                'ScheduleMode' => 'count',
                'RemainingSessions' => 8,
                'UsedSessions' => 0,
                'SessionCount' => 8,
                'SessionDuration' => 60,
                'TotalHours' => 8,
                'Rate' => 400,
                'Charge' => 3200,
                'Pay' => 3200,
                'Paid' => 0,
                'Stop' => 0,
                'StartDate' => '2026-06-01',
                'Period' => 4,
                'by1' => $teacher->id,
                'MDate' => now(),
            ]);

            $sessionDate = '2026-06-05';
            $attendedCsId = DB::table('ClassSession')->insertGetId([
                'StudentClassID' => $scId,
                'SessionDate' => $sessionDate,
                'StartTime' => '10:00',
                'EndTime' => '12:00',
                'Status' => 'attended',
            ]);
            $scheduledCsId = DB::table('ClassSession')->insertGetId([
                'StudentClassID' => $scId,
                'SessionDate' => $sessionDate,
                'StartTime' => '14:00',
                'EndTime' => '16:00',
                'Status' => 'scheduled',
            ]);

            foreach ([$attendedCsId, $scheduledCsId] as $csId) {
                DB::table('LearningRecord')->insert([
                    'StudentID' => $student->id,
                    'StudentClassID' => $scId,
                    'ClassSessionID' => $csId,
                    'TeacherID' => $teacher->id,
                    'Subject' => 'Math',
                    'SessionDate' => $sessionDate,
                    'StartTime' => '10:00',
                    'EndTime' => '12:00',
                    'Content' => '課堂內容',
                    'Progress' => '授課進度',
                    'Status' => 'approved',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
            $this->withHeaders($headers)->postJson('/api/v1/me/pin/set', ['pin' => '2468'])->assertOk();
            $this->withHeaders($headers)->postJson('/api/v1/me/pin/lock')->assertOk();
            $this->withHeaders($headers)->getJson('/api/v1/finance/teacher-payroll')
                ->assertStatus(423)->assertJsonPath('code', 'pin_required');
            $this->withHeaders($headers)->postJson('/api/v1/me/pin/verify', ['pin' => '2468'])->assertOk();
            $response = $this->withHeaders($headers)->getJson('/api/v1/finance/teacher-payroll')->assertOk();
            $this->assertCount(1, $response->json());
            $this->assertSame($teacher->id, $response->json('0.teacher_id'));
            $this->assertSame($teacher->Name, $response->json('0.teacher_name'));
            $this->assertSame(2, $response->json('0.session_count'));
            $otherCampus = CampusFactory::new()->create();
            $otherToken = $this->createDirectorToken([$otherCampus->id], 'framework-payroll-other@example.com');
            $this->withHeaders(['Authorization' => "Bearer {$otherToken}", 'Accept' => 'application/json'])
                ->getJson('/api/v1/finance/teacher-payroll')->assertOk()->assertExactJson([]);
        } finally {
            Carbon::setTestNow();
        }
    }
    public function test_duplicate_course_created_at_is_missing_not_modification_date(): void
    {
        $campus = CampusFactory::new()->create();
        $token = $this->createDirectorToken([$campus->id], 'duplicate-date-probe@example.com');
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('StudentClass', 'created_at'));
        $ids = [];
        foreach (['2026-06-01 10:00:00', '2026-06-02 10:00:00'] as $modifiedAt) {
            $ids[] = DB::table('StudentClass')->insertGetId([
                'StudentID' => $student->id, 'TeacherID' => 1, 'GradeID' => 1,
                'SubjectID' => 1, 'ClassType' => 'one_on_one', 'ScheduleMode' => 'count',
                'RemainingSessions' => 8, 'UsedSessions' => 0, 'SessionCount' => 8,
                'SessionDuration' => 60, 'TotalHours' => 8, 'Rate' => 400,
                'Charge' => 3200, 'Pay' => 3200, 'Paid' => 0, 'Stop' => 0,
                'StartDate' => '2026-06-01', 'Period' => 4, 'by1' => 1, 'MDate' => $modifiedAt,
            ]);
        }
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/finance/duplicate-courses?branch_id='.$campus->id)->assertOk();
        $response->assertJsonPath('count', 1);
        $courses = $response->json('duplicates.0.courses');
        $this->assertCount(2, $courses);
        foreach ($courses as $course) {
            $this->assertContains($course['course_id'], $ids);
            $this->assertArrayHasKey('created_at', $course);
            $this->assertNull($course['created_at']);
        }
        $outside = CampusFactory::new()->create();
        $outsideToken = $this->createDirectorToken([$outside->id], 'duplicate-date-other@example.com');
        $this->withHeaders(['Authorization' => "Bearer {$outsideToken}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/finance/duplicate-courses?branch_id='.$outside->id)
            ->assertOk()->assertJsonPath('count', 0)->assertJsonPath('duplicates', []);
    }

    public function test_duplicate_courses_looks_up_students_and_subjects_once_for_all_groups(): void
    {
        // Sentry N+1: one Student::find and one Subject lookup per duplicate group.
        $campus = CampusFactory::new()->create();
        $token = $this->createDirectorToken([$campus->id], 'duplicate-nplus1@example.com');
        $names = [];
        foreach (range(1, 3) as $i) {
            $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
            $names[$student->id] = $student->name;
            foreach ([1, 2] as $n) {
                DB::table('StudentClass')->insert([
                    'StudentID' => $student->id, 'TeacherID' => 1, 'GradeID' => 1,
                    'SubjectID' => 1, 'ClassType' => 'one_on_one', 'ScheduleMode' => 'count',
                    'RemainingSessions' => 8, 'UsedSessions' => 0, 'SessionCount' => 8,
                    'SessionDuration' => 60, 'TotalHours' => 8, 'Rate' => 400,
                    'Charge' => 3200, 'Pay' => 3200, 'Paid' => 0, 'Stop' => 0,
                    'StartDate' => '2026-06-01', 'Period' => 4, 'by1' => 1, 'MDate' => '2026-06-01 10:00:00',
                ]);
            }
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/finance/duplicate-courses?branch_id='.$campus->id)->assertOk();
        $sql = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $response->assertJsonPath('count', 3);
        foreach ($response->json('duplicates') as $row) {
            $this->assertSame($names[$row['student_id']], $row['student_name']);
        }
        $this->assertLessThanOrEqual(1, $sql->filter(fn ($q) => str_contains($q, 'from `Subject`'))->count());
        $this->assertLessThanOrEqual(3, $sql->filter(fn ($q) => str_contains($q, 'from `Student` where'))->count());
    }

    public function test_outstanding_uses_canonical_subject_label_and_preserves_filters(): void
    {
        $campus = CampusFactory::new()->create();
        $token = $this->createDirectorToken([$campus->id], 'outstanding-label-probe@example.com');
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        DB::table('Subject')->updateOrInsert(['id' => 1], ['School_id' => 1, 'Grade_no' => 1, 'Subject_Name' => '科目相容性測試']);
        $ids = [];
        foreach ([[0, 8, 'one_on_one', 1], [1, 1, 'one_on_one', 1], [0, 8, 'tutoring', 1], [1, 8, 'one_on_one', 1], [0, 8, 'one_on_one', 0]] as [$paid, $remaining, $classType, $subjectId]) {
            $ids[] = DB::table('StudentClass')->insertGetId([
                'StudentID' => $student->id, 'TeacherID' => 1, 'GradeID' => 1,
                'SubjectID' => $subjectId, 'ClassType' => $classType, 'ScheduleMode' => 'count',
                'RemainingSessions' => $remaining, 'UsedSessions' => 0, 'SessionCount' => 8,
                'SessionDuration' => 60, 'TotalHours' => 8, 'Rate' => 400,
                'Charge' => 3200, 'Pay' => 3200, 'Paid' => $paid, 'Stop' => 0,
                'StartDate' => '2026-06-01', 'Period' => 4, 'by1' => 1, 'MDate' => now(),
            ]);
        }
        $subjectQueries = [];
        DB::listen(function ($query) use (&$subjectQueries) {
            if (stripos($query->sql, 'from `Subject`') !== false || stripos($query->sql, 'from `BaseData`') !== false) {
                $subjectQueries[] = $query->sql;
            }
        });
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/finance/outstanding?branch_id='.$campus->id)->assertOk();
        $rows = collect($response->json())->keyBy('class_id');
        $this->assertCount(3, $rows);
        $this->assertSame('科目相容性測試', $rows[$ids[0]]['subject']);
        $this->assertSame('科目相容性測試', $rows[$ids[1]]['subject']);
        $this->assertSame('課程', $rows[$ids[4]]['subject']);
        $this->assertFalse($rows[$ids[0]]['paid']);
        $this->assertTrue($rows[$ids[1]]['paid']);
        $this->assertSame(1, $rows[$ids[1]]['remaining_sessions']);
        $this->assertFalse($rows->has($ids[2]));
        $this->assertFalse($rows->has($ids[3]));
        $this->assertLessThanOrEqual(1, count($subjectQueries), 'Repeated SubjectID must share one lookup; zero IDs require no fallback query.');
        DB::table('BaseData')->insert(['id' => 9001, 'Name' => '課程', 'Val' => '備援科目', 'OrderID' => 1]);
        DB::table('StudentClass')->where('ID', $ids[4])->update(['SubjectID' => 9001]);
        $subjectQueries = [];
        $fallbackResponse = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/finance/outstanding?branch_id='.$campus->id)->assertOk();
        $fallbackRows = collect($fallbackResponse->json())->keyBy('class_id');
        $this->assertSame('備援科目', $fallbackRows[$ids[4]]['subject']);
        $this->assertSame(2, count($subjectQueries), 'One Subject batch plus one missing-ID BaseData batch.');
        $model = \App\Models\StudentClass::findOrFail($ids[0]);
        $this->assertSame('科目相容性測試', $model->displaySubjectName());
        $this->assertSame('備援科目', \App\Models\StudentClass::findOrFail($ids[4])->displaySubjectName());
        $this->assertSame('課程', $model->displaySubjectName([]));
        $this->assertSame('', $model->displaySubjectName([1 => '']));
        $model->setAttribute('Subject', '既有標籤');
        $this->assertSame('既有標籤', $model->displaySubjectName([1 => '批次名稱']));
        $this->assertSame('既有標籤', $model->displaySubjectName());
        $outside = CampusFactory::new()->create();
        $outsideToken = $this->createDirectorToken([$outside->id], 'outstanding-label-other@example.com');
        $this->withHeaders(['Authorization' => "Bearer {$outsideToken}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/finance/outstanding?branch_id='.$outside->id)->assertOk()->assertExactJson([]);
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        return $this->createUserToken($campusIds, $loginName, 'A');
    }

    private function createUserToken(array $campusIds, string $loginName, string $type): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => 'Adoption 測試主任',
            'PSW' => 'secret',
            'type' => $type,
            'phone' => 923456789,
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
}
