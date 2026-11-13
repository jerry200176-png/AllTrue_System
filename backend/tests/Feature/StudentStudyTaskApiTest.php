<?php

namespace Tests\Feature;

use App\Http\Controllers\StudentStudyTaskController;
use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentLearningPlan;
use App\Models\StudentPlanUnit;
use App\Models\StudentStudyTask;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 學生讀書任務 API (#5269 step 1). Fake data only; "today" is frozen at 2026-11-13. */
class StudentStudyTaskApiTest extends TestCase
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

    private function student(int $campus): Student
    {
        return Student::create(['name' => '虛構學生', 'CampusID' => $campus, 'ClassID' => 3, 'SchoolName' => '甲測試國中',
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

    private function task(Student $student, array $o = []): StudentStudyTask
    {
        return StudentStudyTask::create($o + ['campus_id' => $student->CampusID, 'student_id' => $student->id,
            'title' => '第五單元習題', 'kind' => 'homework', 'due_date' => '2026-11-18']);
    }

    private function unit(Student $student, int $campus, int $courseId): StudentPlanUnit
    {
        $plan = StudentLearningPlan::create(['campus_id' => $campus, 'student_id' => $student->id, 'student_class_id' => $courseId,
            'title' => '七上數學', 'status' => 'active', 'plan_start' => '2026-09-01', 'plan_end' => '2027-01-01']);

        return StudentPlanUnit::create(['plan_id' => $plan->id, 'sort_order' => 1, 'title' => 'A',
            'planned_start' => '2026-11-09', 'planned_end' => '2026-11-15']);
    }

    /** @return array{0: int, 1: array, 2: array, 3: Student} campus, director, own teacher, student of that teacher */
    private function world(): array
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $teacher = $this->staff('T', $campus);
        $student = $this->student($campus);
        $this->course($student, $teacher['id']);

        return [$campus, $this->staff('A', $campus), $teacher, $student];
    }

    public function test_teacher_of_the_student_creates_lists_moves_ticks_and_deletes(): void
    {
        [, , $teacher, $student] = $this->world();

        $created = $this->as($teacher)->postJson("/api/v1/students/{$student->id}/study-tasks", [
            'title' => ' 第五單元習題 ', 'kind' => 'homework', 'due_date' => '2026-11-18', 'minutes' => 30, 'note' => '  ',
        ])->assertCreated()->assertJsonPath('data.title', '第五單元習題')->assertJsonPath('data.note', null)
            ->assertJsonPath('data.created_by', $teacher['id'])->assertJsonPath('data.done_at', null);
        $id = $created->json('data.id');

        $this->as($teacher)->getJson("/api/v1/students/{$student->id}/study-tasks")->assertOk()->assertJsonCount(1, 'data');

        $this->as($teacher)->patchJson("/api/v1/study-tasks/{$id}", ['due_date' => '2026-11-19', 'kind' => 'review', 'minutes' => null])
            ->assertOk()->assertJsonPath('data.due_date', '2026-11-19')->assertJsonPath('data.kind', 'review')->assertJsonPath('data.minutes', null);

        $done = $this->as($teacher)->patchJson("/api/v1/study-tasks/{$id}", ['done' => true])->assertOk();
        $this->assertNotNull($done->json('data.done_at'));
        $this->assertSame($teacher['id'], $done->json('data.done_by'));
        $this->as($teacher)->patchJson("/api/v1/study-tasks/{$id}", ['done' => false])
            ->assertOk()->assertJsonPath('data.done_at', null)->assertJsonPath('data.done_by', null);

        $this->as($teacher)->deleteJson("/api/v1/study-tasks/{$id}")->assertOk();
        $this->assertSame(0, StudentStudyTask::query()->count());
    }

    public function test_ticking_twice_keeps_the_first_done_time(): void
    {
        [, $director, , $student] = $this->world();
        $task = $this->task($student);

        $this->as($director)->patchJson("/api/v1/study-tasks/{$task->id}", ['done' => true])->assertOk();
        $first = $task->fresh()->done_at;
        Carbon::setTestNow('2026-11-14 09:00:00');
        $this->as($director)->patchJson("/api/v1/study-tasks/{$task->id}", ['done' => true])->assertOk();

        $this->assertTrue($first->equalTo($task->fresh()->done_at));
    }

    public function test_director_of_the_campus_has_full_access(): void
    {
        [, $director, , $student] = $this->world();
        $task = $this->task($student);

        $this->as($director)->postJson("/api/v1/students/{$student->id}/study-tasks", ['title' => '複習', 'due_date' => '2026-11-20'])
            ->assertCreated()->assertJsonPath('data.kind', 'other');
        $this->as($director)->getJson("/api/v1/students/{$student->id}/study-tasks")->assertOk()->assertJsonCount(2, 'data');
        $this->as($director)->patchJson("/api/v1/study-tasks/{$task->id}", ['title' => '訂正'])->assertOk()->assertJsonPath('data.title', '訂正');
        $this->as($director)->deleteJson("/api/v1/study-tasks/{$task->id}")->assertOk();
    }

    public function test_index_filters_by_due_date_range_and_orders_by_date(): void
    {
        [, $director, , $student] = $this->world();
        $this->task($student, ['title' => 'late', 'due_date' => '2026-11-20']);
        $this->task($student, ['title' => 'early', 'due_date' => '2026-11-16']);
        $this->task($student, ['title' => 'outside', 'due_date' => '2026-12-30']);

        $res = $this->as($director)->getJson("/api/v1/students/{$student->id}/study-tasks?from=2026-11-16&to=2026-11-22")->assertOk();
        $this->assertSame(['early', 'late'], array_column($res->json('data'), 'title'));
        $this->as($director)->getJson("/api/v1/students/{$student->id}/study-tasks?from=2026-11-22&to=2026-11-16")->assertStatus(422);
    }

    public function test_teacher_of_another_campus_is_refused_everywhere(): void
    {
        [, , , $student] = $this->world();
        $task = $this->task($student);
        $otherCampus = (int) CampusFactory::new()->create()->id;
        $outsider = $this->staff('T', $otherCampus);

        $this->as($outsider)->getJson("/api/v1/students/{$student->id}/study-tasks")->assertForbidden();
        $this->as($outsider)->postJson("/api/v1/students/{$student->id}/study-tasks", ['title' => 'x', 'due_date' => '2026-11-20'])->assertForbidden();
        $this->as($outsider)->patchJson("/api/v1/study-tasks/{$task->id}", ['title' => 'y'])->assertForbidden();
        $this->as($outsider)->deleteJson("/api/v1/study-tasks/{$task->id}")->assertForbidden();
        $this->assertSame('第五單元習題', $task->fresh()->title);
    }

    public function test_director_of_another_campus_is_refused_everywhere(): void
    {
        [, , , $student] = $this->world();
        $task = $this->task($student);
        $outsider = $this->staff('A', (int) CampusFactory::new()->create()->id);

        $this->as($outsider)->getJson("/api/v1/students/{$student->id}/study-tasks")->assertForbidden();
        $this->as($outsider)->postJson("/api/v1/students/{$student->id}/study-tasks", ['title' => 'x', 'due_date' => '2026-11-20'])->assertForbidden();
        $this->as($outsider)->patchJson("/api/v1/study-tasks/{$task->id}", ['done' => true])->assertForbidden();
        $this->as($outsider)->deleteJson("/api/v1/study-tasks/{$task->id}")->assertForbidden();
        $this->assertNull($task->fresh()->done_at);
        $this->assertSame(1, StudentStudyTask::query()->count());
    }

    public function test_teacher_of_the_same_campus_but_not_of_this_student_is_refused(): void
    {
        [$campus, , , $student] = $this->world();
        $task = $this->task($student);
        $other = $this->staff('T', $campus);

        $this->as($other)->getJson("/api/v1/students/{$student->id}/study-tasks")->assertForbidden();
        $this->as($other)->postJson("/api/v1/students/{$student->id}/study-tasks", ['title' => 'x', 'due_date' => '2026-11-20'])->assertForbidden();
        $this->as($other)->patchJson("/api/v1/study-tasks/{$task->id}", ['title' => 'y'])->assertForbidden();
        $this->as($other)->deleteJson("/api/v1/study-tasks/{$task->id}")->assertForbidden();
        $this->assertSame(1, StudentStudyTask::query()->count());
    }

    public function test_unauthorised_callers_do_not_learn_about_validation_errors(): void
    {
        [$campus, , , $student] = $this->world();
        $other = $this->staff('T', $campus);

        $this->as($other)->postJson("/api/v1/students/{$student->id}/study-tasks", ['title' => ''])->assertForbidden();
    }

    public function test_index_only_returns_rows_of_the_students_campus(): void
    {
        [, $director, , $student] = $this->world();
        $this->task($student);
        // A stray row with the same student id but another campus must never be listed.
        $this->task($student, ['campus_id' => $student->CampusID + 999, 'title' => 'stray']);

        $res = $this->as($director)->getJson("/api/v1/students/{$student->id}/study-tasks")->assertOk();
        $this->assertSame(['第五單元習題'], array_column($res->json('data'), 'title'));
    }

    public function test_plan_unit_must_belong_to_this_student(): void
    {
        [$campus, $director, $teacher, $student] = $this->world();
        $mine = $this->unit($student, $campus, (int) StudentClass::query()->where('StudentID', $student->id)->value('ID'));
        $stranger = $this->student($campus);
        $theirs = $this->unit($stranger, $campus, $this->course($stranger, $teacher['id'])->ID);

        $this->as($director)->postJson("/api/v1/students/{$student->id}/study-tasks", ['title' => 'x', 'due_date' => '2026-11-20', 'plan_unit_id' => $theirs->id])
            ->assertStatus(422);
        $this->as($director)->postJson("/api/v1/students/{$student->id}/study-tasks", ['title' => 'x', 'due_date' => '2026-11-20', 'plan_unit_id' => $mine->id])
            ->assertCreated()->assertJsonPath('data.plan_unit_id', $mine->id);
        $task = $this->task($student);
        $this->as($director)->patchJson("/api/v1/study-tasks/{$task->id}", ['plan_unit_id' => $theirs->id])->assertStatus(422);
        $this->assertNull($task->fresh()->plan_unit_id);
    }

    public function test_validation_rejects_bad_input(): void
    {
        [, $director, , $student] = $this->world();
        $url = "/api/v1/students/{$student->id}/study-tasks";

        $this->as($director)->postJson($url, ['due_date' => '2026-11-20'])->assertStatus(422);
        $this->as($director)->postJson($url, ['title' => 'x'])->assertStatus(422);
        $this->as($director)->postJson($url, ['title' => 'x', 'due_date' => '11/20'])->assertStatus(422);
        $this->as($director)->postJson($url, ['title' => 'x', 'due_date' => '2026-11-20', 'kind' => 'party'])->assertStatus(422);
        $this->as($director)->postJson($url, ['title' => 'x', 'due_date' => '2026-11-20', 'minutes' => 0])->assertStatus(422);
        $this->as($director)->postJson($url, ['title' => str_repeat('a', 121), 'due_date' => '2026-11-20'])->assertStatus(422);
        $this->assertSame(0, StudentStudyTask::query()->count());
    }

    public function test_unknown_task_is_404(): void
    {
        [, $director] = $this->world();

        $this->as($director)->patchJson('/api/v1/study-tasks/999999', ['done' => true])->assertNotFound();
        $this->as($director)->deleteJson('/api/v1/study-tasks/999999')->assertNotFound();
    }

    public function test_substitute_teacher_can_read_but_not_write(): void
    {
        [$campus, , , $student] = $this->world();
        $task = $this->task($student);
        $sub = $this->staff('T', $campus);
        $course = StudentClass::query()->where('StudentID', $student->id)->first();
        // A scheduled makeup lesson makes the course visible to the substitute (StudentClass::visibleToTeacher) without ownership.
        DB::table('schedules')->insert([
            'student_id' => $student->id, 'teacher_id' => $sub['id'], 'day_of_week' => 1, 'start_time' => '16:00', 'end_time' => '17:00',
            'branch_id' => $campus, 'status' => 'scheduled', 'student_course_id' => $course->ID, 'original_schedule_id' => 1,
        ]);

        $this->as($sub)->getJson("/api/v1/students/{$student->id}/study-tasks")->assertOk()->assertJsonCount(1, 'data');
        $this->as($sub)->postJson("/api/v1/students/{$student->id}/study-tasks", ['title' => 'x', 'due_date' => '2026-11-20'])->assertForbidden();
        $this->as($sub)->patchJson("/api/v1/study-tasks/{$task->id}", ['title' => 'y'])->assertForbidden();
        $this->as($sub)->deleteJson("/api/v1/study-tasks/{$task->id}")->assertForbidden();
        $this->assertSame(1, StudentStudyTask::query()->count());
        $this->assertSame('第五單元習題', $task->fresh()->title);
    }

    public function test_plan_unit_in_another_campus_is_refused_and_nothing_is_written(): void
    {
        [$campus, $director, , $student] = $this->world();
        $otherCampus = (int) CampusFactory::new()->create()->id;
        $foreign = $this->student($otherCampus);
        $theirs = $this->unit($foreign, $otherCampus, $this->course($foreign, $this->staff('T', $otherCampus)['id'])->ID);
        // Same student id but a plan stored under another campus must not count either.
        $mine = $this->student($campus);
        $crossCampus = $this->unit($mine, $otherCampus, $this->course($mine, $this->staff('T', $campus)['id'])->ID);

        foreach ([$theirs->id, $crossCampus->id] as $unitId) {
            $this->as($director)->postJson("/api/v1/students/{$mine->id}/study-tasks", ['title' => 'x', 'due_date' => '2026-11-20', 'plan_unit_id' => $unitId])
                ->assertStatus(422);
        }
        $this->assertSame(0, StudentStudyTask::query()->count());
        $task = $this->task($mine);
        $this->as($director)->patchJson("/api/v1/study-tasks/{$task->id}", ['plan_unit_id' => $theirs->id])->assertStatus(422);
        $this->assertNull($task->fresh()->plan_unit_id);
    }

    public function test_client_sent_ownership_and_done_fields_are_ignored(): void
    {
        [$campus, $director, , $student] = $this->world();
        $other = $this->student((int) CampusFactory::new()->create()->id);

        $id = $this->as($director)->postJson("/api/v1/students/{$student->id}/study-tasks", [
            'title' => 'x', 'due_date' => '2026-11-20', 'campus_id' => $other->CampusID, 'student_id' => $other->id,
            'created_by' => 999999, 'done_at' => '2026-01-01 00:00:00', 'done_by' => 999999,
        ])->assertCreated()->json('data.id');

        $row = StudentStudyTask::query()->findOrFail($id);
        $this->assertSame($campus, (int) $row->campus_id);
        $this->assertSame((int) $student->id, (int) $row->student_id);
        $this->assertSame($director['id'], (int) $row->created_by);
        $this->assertNull($row->done_at);
        $this->assertNull($row->done_by);

        $this->as($director)->patchJson("/api/v1/study-tasks/{$id}", [
            'title' => 'z', 'campus_id' => $other->CampusID, 'student_id' => $other->id, 'created_by' => 999999,
            'done_at' => '2026-01-01 00:00:00', 'done_by' => 999999,
        ])->assertOk();

        $row = $row->fresh();
        $this->assertSame('z', $row->title);
        $this->assertSame($campus, (int) $row->campus_id);
        $this->assertSame((int) $student->id, (int) $row->student_id);
        $this->assertSame($director['id'], (int) $row->created_by);
        $this->assertNull($row->done_at);
        $this->assertNull($row->done_by);
    }

    public function test_list_is_capped_at_the_controllers_max_rows(): void
    {
        [, $director, , $student] = $this->world();
        $cap = (new \ReflectionClassConstant(StudentStudyTaskController::class, 'MAX_ROWS'))->getValue();
        $now = now()->toDateTimeString();
        $rows = [];
        for ($i = 0; $i < $cap + 3; $i++) {
            $rows[] = ['campus_id' => $student->CampusID, 'student_id' => $student->id, 'title' => 't' . $i, 'kind' => 'other',
                'due_date' => '2026-11-18', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('student_study_tasks')->insert($chunk);
        }

        $res = $this->as($director)->getJson("/api/v1/students/{$student->id}/study-tasks?from=2026-11-16&to=2026-11-22")->assertOk();
        $this->assertCount($cap, $res->json('data'));
    }

    public function test_super_admin_can_read_and_write(): void
    {
        [, , , $student] = $this->world();
        $admin = $this->staff('S', (int) CampusFactory::new()->create()->id);

        $id = $this->as($admin)->postJson("/api/v1/students/{$student->id}/study-tasks", ['title' => 'x', 'due_date' => '2026-11-20'])
            ->assertCreated()->assertJsonPath('data.created_by', $admin['id'])->json('data.id');
        $this->as($admin)->getJson("/api/v1/students/{$student->id}/study-tasks")->assertOk()->assertJsonCount(1, 'data');
        $this->as($admin)->patchJson("/api/v1/study-tasks/{$id}", ['done' => true])->assertOk();
        $this->as($admin)->deleteJson("/api/v1/study-tasks/{$id}")->assertOk();
        $this->assertSame(0, StudentStudyTask::query()->count());
    }

    public function test_anonymous_callers_are_refused(): void
    {
        [, , , $student] = $this->world();

        $this->getJson("/api/v1/students/{$student->id}/study-tasks")->assertUnauthorized();
    }
}
