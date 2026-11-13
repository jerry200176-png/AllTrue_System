<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\CurriculumTemplate;
use App\Models\CurriculumTemplateUnit;
use App\Models\SchoolExamPeriod;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** 學生學習計畫 API (#4970 step 4a). Fake data only; "today" is frozen at 2026-11-13 (ROC 115, semester 1). */
class StudentLearningPlanApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(string $type, int $campus): array
    {
        $u = User::create([
            'LoginName' => $type . random_int(1, 999999) . '@example.com', 'Name' => 'Staff',
            'PSW' => 'secret', 'type' => $type, 'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $u->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        $t = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $u->id, 'token' => $t, 'expires_at' => now()->addDay()]);

        return ['token' => $t, 'id' => (int) $u->id];
    }

    private function as(array $staff)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $staff['token'], 'Accept' => 'application/json']);
    }

    private function student(int $campus, ?string $school = '甲測試國中'): Student
    {
        return Student::create(['name' => '虛構學生', 'CampusID' => $campus, 'ClassID' => 3, 'SchoolName' => $school,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function course(Student $student, int $teacherId): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $student->id, 'TeacherID' => $teacherId, 'GradeID' => 1, 'SubjectID' => 1, 'by1' => 1, // by1 is the class-type code, not a campus,
            'StartDate' => '2026-08-01', 'TotalHours' => 20, 'Charge' => 1000, 'Pay' => 0, 'Paid' => 0, 'Rate' => 1000,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10, 'RemainingSessions' => 10,
            'UsedSessions' => 0, 'SessionDuration' => 60, 'ClassType' => 'one_on_one',
        ]);
    }

    /** Units A,B -> 段考一, C -> 段考二, D -> 期末. */
    private function template(int $campus, string $status = 'active'): CurriculumTemplate
    {
        $t = CurriculumTemplate::create(['campus_id' => $campus, 'subject' => 'Math', 'grade' => 'J1', 'title' => '七上數學', 'status' => $status]);
        foreach ([['A', 1], ['B', 1], ['C', 2], ['D', 3]] as $i => [$title, $seq]) {
            CurriculumTemplateUnit::create(['template_id' => $t->id, 'sort_order' => $i + 1, 'title' => $title, 'exam_sequence' => $seq]);
        }

        return $t;
    }

    private function exams(int $campus, string $school = '甲測試國中'): void
    {
        foreach ([[1, '2026-10-20'], [2, '2026-12-01'], [3, '2027-01-04']] as [$seq, $date]) {
            SchoolExamPeriod::create(['campus_id' => $campus, 'school_name' => $school, 'academic_year' => 115, 'semester' => 1,
                'seq' => $seq, 'label' => "第{$seq}次段考", 'start_date' => $date, 'end_date' => $date, 'grade_ids' => null, 'grade_key' => 'all']);
        }
    }

    private function create(array $staff, Student $student, StudentClass $course, CurriculumTemplate $tpl, array $o = [])
    {
        return $this->as($staff)->postJson("/api/v1/students/{$student->id}/learning-plans", $o + [
            'student_class_id' => $course->ID, 'template_id' => $tpl->id, 'plan_start' => '2026-09-01',
        ]);
    }

    private function ranges(array $units): array
    {
        return array_map(fn ($u) => $u['title'] . ' ' . $u['planned_start'] . '..' . $u['planned_end'], $units);
    }

    public function test_create_copies_template_units_and_dates_follow_the_school_exam_dates(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $campus);
        $student = $this->student($campus);
        $course = $this->course($student, $this->staff('T', $campus)['id']);
        $tpl = $this->template($campus);
        $this->exams($campus);

        $res = $this->create($dir, $student, $course, $tpl)->assertCreated()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.title', '七上數學')
            ->assertJsonPath('data.source_template_id', $tpl->id)->assertJsonPath('data.plan_end', '2027-01-05');

        $this->assertSame(['A 2026-09-01..2026-09-25', 'B 2026-09-26..2026-10-19', 'C 2026-10-20..2026-11-30', 'D 2026-12-01..2027-01-03'],
            $this->ranges($res->json('data.units')));
        // Content is copied: editing the template afterwards does not touch the plan.
        CurriculumTemplateUnit::query()->where('template_id', $tpl->id)->delete();
        $this->as($dir)->getJson("/api/v1/students/{$student->id}/learning-plans")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonCount(4, 'data.0.units');
    }

    public function test_student_without_school_or_periods_is_scheduled_without_exams(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $campus);
        $student = $this->student($campus, null);
        $course = $this->course($student, $this->staff('T', $campus)['id']);
        $this->exams($campus); // belongs to a school this student does not attend

        $res = $this->create($dir, $student, $course, $this->template($campus), ['plan_end' => '2026-09-08'])->assertCreated();

        $this->assertSame(['A 2026-09-01..2026-09-02', 'B 2026-09-03..2026-09-04', 'C 2026-09-05..2026-09-06', 'D 2026-09-07..2026-09-08'],
            $this->ranges($res->json('data.units')));
        $this->assertNull($res->json('data.next_exam'));
    }

    public function test_derived_fields_percent_done_units_behind_and_next_exam(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $campus);
        $student = $this->student($campus);
        $course = $this->course($student, $this->staff('T', $campus)['id']);
        $this->exams($campus);
        $plan = $this->create($dir, $student, $course, $this->template($campus))->json('data');

        // A and B ended before today (2026-11-13) and are not done.
        $this->assertSame(0, $plan['percent_done']);
        $this->assertSame(2, $plan['units_behind']);
        $this->assertSame(['sequence' => 2, 'label' => '第2次段考', 'start_date' => '2026-12-01', 'academic_year' => 115, 'semester' => 1, 'days_left' => 18], $plan['next_exam']);

        $a = $plan['units'][0]['id'];
        $res = $this->as($dir)->patchJson("/api/v1/learning-plans/{$plan['id']}/units/$a", ['completed' => true])->assertOk();
        $this->assertSame(25, $res->json('data.percent_done'));
        $this->assertSame(1, $res->json('data.units_behind'));
        $this->assertNotNull($res->json('data.units.0.completed_at'));
        $this->assertSame($dir['id'], $res->json('data.units.0.completed_by'));

        $res = $this->as($dir)->patchJson("/api/v1/learning-plans/{$plan['id']}/units/$a", ['completed' => false])->assertOk();
        $this->assertSame(0, $res->json('data.percent_done'));
        $this->assertNull($res->json('data.units.0.completed_at'));
        $this->assertNull($res->json('data.units.0.completed_by'));
    }

    public function test_duplicate_open_plan_is_refused_until_the_first_is_archived(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $campus);
        $student = $this->student($campus);
        $course = $this->course($student, $this->staff('T', $campus)['id']);
        $tpl = $this->template($campus);

        $first = $this->create($dir, $student, $course, $tpl)->assertCreated()->json('data.id');
        $this->create($dir, $student, $course, $tpl)->assertStatus(422);

        $this->as($dir)->patchJson("/api/v1/learning-plans/$first", ['status' => 'archived'])->assertOk();
        $second = $this->create($dir, $student, $course, $tpl)->assertCreated()->json('data.id');
        // Restoring the archived plan would make two open plans for one course.
        $this->as($dir)->patchJson("/api/v1/learning-plans/$first", ['status' => 'active'])->assertStatus(422);
        $this->as($dir)->getJson("/api/v1/students/{$student->id}/learning-plans")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second);
        $this->as($dir)->getJson("/api/v1/students/{$student->id}/learning-plans?status=archived")->assertJsonCount(1, 'data');
    }

    public function test_draft_archived_or_other_campus_template_and_foreign_course_are_refused(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $b = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $student = $this->student($a);
        $teacher = $this->staff('T', $a)['id'];
        $course = $this->course($student, $teacher);

        foreach (['draft', 'archived'] as $status) {
            $this->create($dir, $student, $course, $this->template($a, $status))->assertStatus(422);
        }
        $this->create($dir, $student, $course, $this->template($b))->assertStatus(422);
        $otherCourse = $this->course($this->student($a), $teacher);
        $this->create($dir, $student, $otherCourse, $this->template($a))->assertStatus(422);
        $this->create($dir, $student, $course, $this->template($a), ['plan_end' => '2026-08-01'])->assertStatus(422);
        $this->assertDatabaseCount('student_learning_plans', 0);
    }

    public function test_manual_date_override_survives_recalculation(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $campus);
        $student = $this->student($campus);
        $course = $this->course($student, $this->staff('T', $campus)['id']);
        $this->exams($campus);
        $plan = $this->create($dir, $student, $course, $this->template($campus))->json('data');
        $c = $plan['units'][2]['id'];

        $this->as($dir)->patchJson("/api/v1/learning-plans/{$plan['id']}/units/$c", ['planned_start' => '2026-11-02', 'planned_end' => '2026-11-10'])
            ->assertOk()->assertJsonPath('data.units.2.dates_manual', true);
        $res = $this->as($dir)->patchJson("/api/v1/learning-plans/{$plan['id']}", ['plan_end' => '2027-02-01'])->assertOk();

        $this->assertSame('C 2026-11-02..2026-11-10', $this->ranges($res->json('data.units'))[2]);
        $this->assertSame('2027-02-01', $res->json('data.plan_end'));
        $this->assertSame('D 2026-12-01..2027-01-03', $this->ranges($res->json('data.units'))[3]);

        // dates_manual:false hands the unit back to the scheduler.
        $res = $this->as($dir)->patchJson("/api/v1/learning-plans/{$plan['id']}/units/$c", ['dates_manual' => false])->assertOk();
        $this->assertSame('C 2026-10-20..2026-11-30', $this->ranges($res->json('data.units'))[2]);
        $this->assertFalse($res->json('data.units.2.dates_manual'));
    }

    public function test_unit_validation(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $campus);
        $student = $this->student($campus);
        $course = $this->course($student, $this->staff('T', $campus)['id']);
        $tpl = $this->template($campus);
        $plan = $this->create($dir, $student, $course, $tpl)->json('data');
        $unit = $plan['units'][0]['id'];
        $url = "/api/v1/learning-plans/{$plan['id']}/units/$unit";

        $this->as($dir)->patchJson($url, ['planned_start' => '2026-09-10', 'planned_end' => '2026-09-05'])->assertStatus(422);
        $this->as($dir)->patchJson($url, ['planned_start' => '2026-08-31', 'planned_end' => '2026-09-05'])->assertStatus(422);
        $this->as($dir)->patchJson($url, ['planned_start' => '2026-09-01', 'planned_end' => '2027-01-06'])->assertStatus(422);
        $this->as($dir)->patchJson($url, ['planned_end' => '2026-09-05', 'dates_manual' => false])->assertStatus(422);
        $this->as($dir)->patchJson($url, ['title' => '新名稱', 'note' => ''])->assertOk()
            ->assertJsonPath('data.units.0.title', '新名稱')->assertJsonPath('data.units.0.note', null);
        $this->as($dir)->patchJson("/api/v1/learning-plans/{$plan['id']}", ['plan_start' => '2027-02-01'])->assertStatus(422);

        // A unit id from another plan is not addressable through this plan.
        $other = $this->course($student, $this->staff('T', $campus)['id']);
        $otherPlan = $this->create($dir, $student, $other, $tpl)->json('data');
        $this->as($dir)->patchJson("/api/v1/learning-plans/{$plan['id']}/units/{$otherPlan['units'][0]['id']}", ['title' => 'x'])->assertNotFound();

        $this->as($dir)->patchJson("/api/v1/learning-plans/{$plan['id']}", ['status' => 'archived'])->assertOk();
        $this->as($dir)->patchJson($url, ['title' => 'x'])->assertStatus(422);
    }

    public function test_campus_isolation_on_every_route(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $b = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $a);
        $student = $this->student($a);
        $course = $this->course($student, $this->staff('T', $a)['id']);
        $tpl = $this->template($a);
        $plan = $this->create($dir, $student, $course, $tpl)->json('data');
        $unit = $plan['units'][0]['id'];

        foreach ([$this->staff('A', $b), $this->staff('T', $b)] as $outsider) {
            $this->as($outsider)->getJson("/api/v1/students/{$student->id}/learning-plans")->assertForbidden();
            $this->as($outsider)->postJson("/api/v1/students/{$student->id}/learning-plans", [
                'student_class_id' => $course->ID, 'template_id' => $tpl->id, 'plan_start' => '2026-09-01'])->assertForbidden();
            $this->as($outsider)->patchJson("/api/v1/learning-plans/{$plan['id']}", ['title' => 'x'])->assertForbidden();
            $this->as($outsider)->patchJson("/api/v1/learning-plans/{$plan['id']}/units/$unit", ['completed' => true])->assertForbidden();
        }
        $this->assertDatabaseHas('student_learning_plans', ['id' => $plan['id'], 'title' => '七上數學']);
        $this->assertDatabaseHas('student_plan_units', ['id' => $unit, 'completed_at' => null]);
    }

    public function test_teacher_reads_and_writes_only_courses_visible_to_them(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $campus);
        $mine = $this->staff('T', $campus);
        $other = $this->staff('T', $campus);
        $student = $this->student($campus);
        $myCourse = $this->course($student, $mine['id']);
        $theirCourse = $this->course($student, $other['id']);
        $tpl = $this->template($campus);
        $theirPlan = $this->create($dir, $student, $theirCourse, $tpl)->json('data');

        // Teacher creates a plan on their own course, but not on a colleague's.
        $this->create($mine, $student, $theirCourse, $tpl)->assertForbidden();
        $myPlan = $this->create($mine, $student, $myCourse, $tpl)->assertCreated()->json('data');

        $this->as($mine)->getJson("/api/v1/students/{$student->id}/learning-plans")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $myPlan['id']);
        $this->as($mine)->patchJson("/api/v1/learning-plans/{$myPlan['id']}", ['title' => '我的計畫'])->assertOk();
        $this->as($mine)->patchJson("/api/v1/learning-plans/{$myPlan['id']}/units/{$myPlan['units'][0]['id']}", ['completed' => true])->assertOk();

        $this->as($mine)->patchJson("/api/v1/learning-plans/{$theirPlan['id']}", ['title' => 'x'])->assertForbidden();
        $this->as($mine)->patchJson("/api/v1/learning-plans/{$theirPlan['id']}/units/{$theirPlan['units'][0]['id']}", ['completed' => true])->assertForbidden();

        // A teacher with no course for this student sees nothing.
        $stranger = $this->staff('T', $campus);
        $this->as($stranger)->getJson("/api/v1/students/{$student->id}/learning-plans")->assertForbidden();
    }

    public function test_works_for_a_campus_whose_id_is_not_the_class_type_code(): void
    {
        CampusFactory::new()->create(); // burn id 1 so the real campus id differs from by1 = 1
        $campus = (int) CampusFactory::new()->create()->id;
        $this->assertNotSame(1, $campus);
        $student = $this->student($campus);
        $course = $this->course($student, $this->staff('T', $campus)['id']);

        $this->create($this->staff('A', $campus), $student, $course, $this->template($campus))->assertCreated();
    }

    public function test_stopped_course_is_refused(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $student = $this->student($campus);
        $course = $this->course($student, $this->staff('T', $campus)['id']);
        \Illuminate\Support\Facades\DB::table('StudentClass')->where('ID', $course->ID)->update(['Stop' => 1]);

        $this->create($this->staff('A', $campus), $student, $course, $this->template($campus))->assertStatus(422);
        $this->assertDatabaseCount('student_learning_plans', 0);
    }

    public function test_archived_plan_accepts_only_a_status_change(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $campus);
        $student = $this->student($campus);
        $course = $this->course($student, $this->staff('T', $campus)['id']);
        $id = $this->create($dir, $student, $course, $this->template($campus))->json('data.id');
        $this->as($dir)->patchJson("/api/v1/learning-plans/$id", ['status' => 'archived'])->assertOk();

        $this->as($dir)->patchJson("/api/v1/learning-plans/$id", ['title' => 'x'])->assertStatus(422);
        $this->as($dir)->patchJson("/api/v1/learning-plans/$id", ['status' => 'active', 'plan_end' => '2027-03-01'])->assertStatus(422);
        $this->as($dir)->patchJson("/api/v1/learning-plans/$id", ['status' => 'active'])->assertOk();
    }

    public function test_shrinking_the_range_is_refused_while_a_manual_unit_would_fall_outside(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $campus);
        $student = $this->student($campus);
        $course = $this->course($student, $this->staff('T', $campus)['id']);
        $plan = $this->create($dir, $student, $course, $this->template($campus))->json('data');
        $d = $plan['units'][3]['id'];
        $this->as($dir)->patchJson("/api/v1/learning-plans/{$plan['id']}/units/$d", ['planned_start' => '2026-12-20', 'planned_end' => '2027-01-02'])->assertOk();

        $this->as($dir)->patchJson("/api/v1/learning-plans/{$plan['id']}", ['plan_end' => '2026-12-31'])
            ->assertStatus(422)->assertJsonPath('message', '有 1 個手動排好日期的單元會落在新的計畫期間外，請先調整這些單元的日期');
        $this->assertDatabaseHas('student_learning_plans', ['id' => $plan['id'], 'plan_end' => '2027-01-05']);
        $this->as($dir)->patchJson("/api/v1/learning-plans/{$plan['id']}/units/$d", ['dates_manual' => false])->assertOk();
        $this->as($dir)->patchJson("/api/v1/learning-plans/{$plan['id']}", ['plan_end' => '2026-12-31'])->assertOk();
    }

    public function test_recalculating_next_term_finds_the_same_exams(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $campus);
        $student = $this->student($campus);
        $course = $this->course($student, $this->staff('T', $campus)['id']);
        $this->exams($campus);
        $plan = $this->create($dir, $student, $course, $this->template($campus))->json('data');

        Carbon::setTestNow('2027-03-01 10:00:00'); // a different term (115 / 2)
        $res = $this->as($dir)->patchJson("/api/v1/learning-plans/{$plan['id']}", ['plan_start' => '2026-09-01'])->assertOk();

        $this->assertSame($this->ranges($plan['units']), $this->ranges($res->json('data.units')));
    }

    public function test_a_plan_crossing_two_terms_sees_both_terms_exams(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $campus);
        $student = $this->student($campus);
        $course = $this->course($student, $this->staff('T', $campus)['id']);
        foreach ([[1, 1, '2026-10-20'], [1, 3, '2027-01-04'], [2, 1, '2027-03-20']] as [$sem, $seq, $date]) {
            SchoolExamPeriod::create(['campus_id' => $campus, 'school_name' => '甲測試國中', 'academic_year' => 115, 'semester' => $sem,
                'seq' => $seq, 'label' => "{$sem}-{$seq}", 'start_date' => $date, 'end_date' => $date, 'grade_ids' => null, 'grade_key' => 'all']);
        }
        $tpl = CurriculumTemplate::create(['campus_id' => $campus, 'subject' => 'Math', 'grade' => 'J1', 'title' => '跨學期', 'status' => 'active']);
        foreach ([['X', 1], ['Y', 3], ['Z', 1]] as $i => [$title, $seq]) {
            CurriculumTemplateUnit::create(['template_id' => $tpl->id, 'sort_order' => $i + 1, 'title' => $title, 'exam_sequence' => $seq]);
        }

        $res = $this->create($dir, $student, $course, $tpl, ['plan_end' => '2027-04-30'])->assertCreated();

        // Z is tagged 1 again, so it takes the second term's exam 1, not the first term's.
        $this->assertSame(['X 2026-09-01..2026-10-19', 'Y 2026-10-20..2027-01-03', 'Z 2027-01-04..2027-03-19'], $this->ranges($res->json('data.units')));
        $this->assertSame(['start_date' => '2027-01-04', 'academic_year' => 115, 'semester' => 1],
            array_intersect_key($res->json('data.next_exam'), array_flip(['start_date', 'academic_year', 'semester'])));
    }

    public function test_super_admin_can_manage_plans_and_actor_ids_are_recorded(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $other = (int) CampusFactory::new()->create()->id;
        $admin = $this->staff('S', $other);
        $student = $this->student($campus);
        $course = $this->course($student, $this->staff('T', $campus)['id']);

        $plan = $this->create($admin, $student, $course, $this->template($campus))->assertCreated()
            ->assertJsonPath('data.created_by', $admin['id'])->json('data');
        $unit = $plan['units'][0]['id'];
        $this->as($admin)->patchJson("/api/v1/learning-plans/{$plan['id']}/units/$unit", ['completed' => true])
            ->assertOk()->assertJsonPath('data.units.0.completed_by', $admin['id']);
        $this->as($admin)->getJson("/api/v1/students/{$student->id}/learning-plans")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_date_format_is_strict(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $campus);
        $student = $this->student($campus);
        $course = $this->course($student, $this->staff('T', $campus)['id']);
        $tpl = $this->template($campus);

        $this->create($dir, $student, $course, $tpl, ['plan_start' => '2026/09/01'])->assertStatus(422);
        $this->create($dir, $student, $course, $tpl, ['plan_start' => '2026-09-01 10:00:00'])->assertStatus(422);
        $this->create($dir, $student, $course, $tpl, ['plan_start' => '2026-02-30'])->assertStatus(422);
    }

    public function test_substitute_teacher_can_read_but_not_write(): void
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $dir = $this->staff('A', $campus);
        $sub = $this->staff('T', $campus);
        $student = $this->student($campus);
        $course = $this->course($student, $this->staff('T', $campus)['id']);
        $plan = $this->create($dir, $student, $course, $this->template($campus))->json('data');
        // A scheduled makeup lesson makes the course visible to the substitute (StudentClass::visibleToTeacher).
        \Illuminate\Support\Facades\DB::table('schedules')->insert([
            'student_id' => $student->id, 'teacher_id' => $sub['id'], 'day_of_week' => 1, 'start_time' => '16:00', 'end_time' => '17:00',
            'branch_id' => $campus, 'status' => 'scheduled', 'student_course_id' => $course->ID, 'original_schedule_id' => 1,
        ]);

        $this->as($sub)->getJson("/api/v1/students/{$student->id}/learning-plans")->assertOk()->assertJsonCount(1, 'data');
        $this->as($sub)->patchJson("/api/v1/learning-plans/{$plan['id']}", ['title' => 'x'])->assertForbidden();
        $this->as($sub)->patchJson("/api/v1/learning-plans/{$plan['id']}/units/{$plan['units'][0]['id']}", ['completed' => true])->assertForbidden();
    }
}
