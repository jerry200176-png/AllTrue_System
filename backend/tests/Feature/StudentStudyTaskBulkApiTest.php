<?php

namespace Tests\Feature;

use App\Http\Controllers\StudentStudyTaskBulkController;
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

/** 讀書任務快速工具: 複製上週、套用到同班 (#5269 step 3). Fake data only; "today" is frozen at 2026-11-13. */
class StudentStudyTaskBulkApiTest extends TestCase
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

    private function course(Student $student, int $teacherId, int $subject = 1): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $student->id, 'TeacherID' => $teacherId, 'GradeID' => 1, 'SubjectID' => $subject, 'by1' => 1, // by1 is the class-type code, not a campus
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

    private function unit(Student $student): StudentPlanUnit
    {
        $plan = StudentLearningPlan::create(['campus_id' => $student->CampusID, 'student_id' => $student->id,
            'student_class_id' => (int) StudentClass::query()->where('StudentID', $student->id)->value('ID'),
            'title' => '七上數學', 'status' => 'active', 'plan_start' => '2026-09-01', 'plan_end' => '2027-01-01']);

        return StudentPlanUnit::create(['plan_id' => $plan->id, 'sort_order' => 1, 'title' => 'A',
            'planned_start' => '2026-11-09', 'planned_end' => '2026-11-15']);
    }

    /** @return array{0: int, 1: array, 2: array, 3: Student, 4: Student} campus, director, teacher, student, a classmate (same teacher, subject, grade) */
    private function world(): array
    {
        $campus = (int) CampusFactory::new()->create()->id;
        $teacher = $this->staff('T', $campus);
        $student = $this->student($campus);
        $this->course($student, $teacher['id']);
        $mate = $this->student($campus);
        $this->course($mate, $teacher['id']);

        return [$campus, $this->staff('A', $campus), $teacher, $student, $mate];
    }

    private function copy(array $who, Student $s, string $weekStart = '2026-11-16')
    {
        return $this->as($who)->postJson("/api/v1/students/{$s->id}/study-tasks/copy-week", ['week_start' => $weekStart]);
    }

    private function apply(array $who, StudentStudyTask $t, array $ids)
    {
        return $this->as($who)->postJson("/api/v1/study-tasks/{$t->id}/apply-to-students", ['student_ids' => $ids]);
    }

    private function taskCount(Student $s): int
    {
        return StudentStudyTask::query()->where('student_id', $s->id)->count();
    }

    private function cap(string $name): int
    {
        return (int) (new \ReflectionClassConstant(StudentStudyTaskBulkController::class, $name))->getValue();
    }

    public function test_copy_week_copies_last_week_to_the_same_weekdays_as_new_undone_tasks(): void
    {
        [, , $teacher, $student] = $this->world();
        $unit = $this->unit($student);
        $a = $this->task($student, ['due_date' => '2026-11-09', 'minutes' => 30, 'note' => '帶課本', 'plan_unit_id' => $unit->id, 'done_at' => now(), 'done_by' => 99]);
        $this->task($student, ['title' => '單字', 'kind' => 'review', 'due_date' => '2026-11-15']);
        $this->task($student, ['title' => '更早', 'due_date' => '2026-11-08']);
        $this->task($student, ['title' => '本週已有', 'due_date' => '2026-11-16']);

        $res = $this->copy($teacher, $student)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('skipped', 0);

        $this->assertSame(['2026-11-16', '2026-11-22'], array_column($res->json('data'), 'due_date'));
        $first = $res->json('data.0');
        $this->assertSame([$a->title, 'homework', 30, '帶課本', $unit->id, null, null, $teacher['id']],
            [$first['title'], $first['kind'], $first['minutes'], $first['note'], $first['plan_unit_id'], $first['done_at'], $first['done_by'], $first['created_by']]);
        $this->assertSame('單字', $res->json('data.1.title'));
        $this->assertNotNull($a->fresh()->done_at, 'the source task is not touched');
        $this->assertSame(6, $this->taskCount($student));
    }

    public function test_copy_week_twice_does_not_duplicate(): void
    {
        [, , $teacher, $student] = $this->world();
        $this->task($student, ['due_date' => '2026-11-09']);
        $this->task($student, ['due_date' => '2026-11-09']); // an identical source row collapses too
        $this->task($student, ['title' => '另一件', 'due_date' => '2026-11-10']);

        $this->copy($teacher, $student)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('skipped', 1);
        $this->copy($teacher, $student)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('skipped', 3);

        $this->assertSame(5, $this->taskCount($student));
    }

    public function test_copy_week_skips_only_a_day_with_the_same_title_and_kind(): void
    {
        [, , $teacher, $student] = $this->world();
        $this->task($student, ['title' => '習題', 'kind' => 'homework', 'due_date' => '2026-11-09']);
        $this->task($student, ['title' => '習題', 'kind' => 'homework', 'due_date' => '2026-11-16']);
        $this->task($student, ['title' => '複習', 'kind' => 'review', 'due_date' => '2026-11-10']);
        $this->task($student, ['title' => '複習', 'kind' => 'homework', 'due_date' => '2026-11-17']); // same title, other kind: not a duplicate

        $this->copy($teacher, $student)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('skipped', 1);
    }

    public function test_copy_week_needs_a_monday_and_a_date_and_rejects_oversized_weeks(): void
    {
        [, , $teacher, $student] = $this->world();

        $this->copy($teacher, $student, '2026-11-17')->assertStatus(422);
        $this->as($teacher)->postJson("/api/v1/students/{$student->id}/study-tasks/copy-week", [])->assertStatus(422);
        $cap = $this->cap('MAX_COPY_ROWS');
        for ($i = 0; $i <= $cap; $i++) {
            StudentStudyTask::create(['campus_id' => $student->CampusID, 'student_id' => $student->id, 'title' => "t{$i}", 'kind' => 'other', 'due_date' => '2026-11-10']);
        }
        $this->copy($teacher, $student)->assertStatus(422);
        $this->assertSame($cap + 1, $this->taskCount($student));
    }

    public function test_copy_week_is_refused_for_everyone_who_may_not_write_for_the_student(): void
    {
        [$campus, , , $student] = $this->world();
        $this->task($student, ['due_date' => '2026-11-09']);
        $otherTeacher = $this->staff('T', $campus);
        $foreignTeacher = $this->staff('T', (int) CampusFactory::new()->create()->id);
        $foreignDirector = $this->staff('A', (int) CampusFactory::new()->create()->id);

        foreach ([$otherTeacher, $foreignTeacher, $foreignDirector] as $who) {
            $this->copy($who, $student)->assertForbidden();
        }
        $this->assertSame(1, $this->taskCount($student));
    }

    public function test_copy_week_works_for_director_and_super_admin(): void
    {
        [, $director, , $student] = $this->world();
        $this->task($student, ['due_date' => '2026-11-09']);
        $admin = $this->staff('S', (int) CampusFactory::new()->create()->id);

        $this->copy($director, $student)->assertOk()->assertJsonCount(1, 'data');
        $this->copy($admin, $student, '2026-11-23')->assertOk()->assertJsonCount(1, 'data'); // copies the task the director just created
    }

    public function test_apply_creates_the_task_for_each_classmate_without_plan_unit_or_done_state(): void
    {
        [, , $teacher, $student, $mate] = $this->world();
        $task = $this->task($student, ['minutes' => 20, 'note' => '備註', 'plan_unit_id' => $this->unit($student)->id, 'done_at' => now()]);

        $this->apply($teacher, $task, [$mate->id])->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('data.refused', 0)->assertJsonPath('data.results.0.status', 'created');

        $copy = StudentStudyTask::query()->where('student_id', $mate->id)->firstOrFail();
        $this->assertSame([$task->title, 'homework', '2026-11-18', 20, '備註', null, null, $teacher['id'], (int) $mate->CampusID],
            [$copy->title, $copy->kind, $copy->due_date->toDateString(), $copy->minutes, $copy->note, $copy->plan_unit_id, $copy->done_at, $copy->created_by, $copy->campus_id]);
    }

    public function test_apply_twice_reports_skipped_and_does_not_duplicate(): void
    {
        [, , $teacher, $student, $mate] = $this->world();
        $task = $this->task($student);

        $this->apply($teacher, $task, [$mate->id])->assertOk()->assertJsonPath('data.created', 1);
        $this->apply($teacher, $task, [$mate->id, $mate->id])->assertOk()->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.skipped', 1)->assertJsonCount(1, 'data.results');

        $this->assertSame(1, $this->taskCount($mate));
    }

    public function test_apply_refuses_non_classmates_other_campus_and_unknown_ids_without_blocking_the_rest(): void
    {
        [$campus, , $teacher, $student, $mate] = $this->world();
        $task = $this->task($student);
        $stranger = $this->student($campus); // same campus, no shared course
        $otherTeacherMate = $this->student($campus); // same subject and grade, but taught by someone else
        $this->course($otherTeacherMate, $this->staff('T', $campus)['id']);
        $foreign = $this->student((int) CampusFactory::new()->create()->id);
        $this->course($foreign, $teacher['id']);

        $res = $this->apply($teacher, $task, [$stranger->id, $mate->id, $otherTeacherMate->id, $foreign->id, 999999])->assertOk();

        $this->assertSame(['refused', 'created', 'refused', 'refused', 'refused'], array_column($res->json('data.results'), 'status'));
        $this->assertSame(1, $res->json('data.created'));
        $this->assertSame(4, $res->json('data.refused'));
        foreach ([$stranger, $otherTeacherMate, $foreign] as $s) {
            $this->assertSame(0, $this->taskCount($s));
        }
    }

    public function test_apply_refuses_a_student_whose_course_with_this_teacher_is_stopped(): void
    {
        [$campus, , $teacher, $student] = $this->world();
        $task = $this->task($student);
        $stopped = $this->student($campus);
        $course = $this->course($stopped, $teacher['id']);
        StudentClass::query()->where('ID', $course->ID)->update(['Stop' => 1]);

        $this->apply($teacher, $task, [$stopped->id])->assertOk()->assertJsonPath('data.refused', 1);
        $this->assertSame(0, $this->taskCount($stopped));
    }

    public function test_apply_is_refused_for_callers_who_may_not_write_for_the_source_student(): void
    {
        [$campus, , , $student, $mate] = $this->world();
        $task = $this->task($student);
        $otherTeacher = $this->staff('T', $campus);
        $foreignTeacher = $this->staff('T', (int) CampusFactory::new()->create()->id);
        $foreignDirector = $this->staff('A', (int) CampusFactory::new()->create()->id);

        foreach ([$otherTeacher, $foreignTeacher, $foreignDirector] as $who) {
            $this->apply($who, $task, [$mate->id])->assertForbidden();
        }
        $this->assertSame(0, $this->taskCount($mate));
    }

    public function test_apply_ignores_the_source_student_and_duplicate_ids(): void
    {
        [, , $teacher, $student, $mate] = $this->world();
        $task = $this->task($student);

        $res = $this->apply($teacher, $task, [$student->id, $mate->id, $mate->id])->assertOk();

        $this->assertSame(['refused', 'created'], array_column($res->json('data.results'), 'status'));
        $this->assertSame(1, $this->taskCount($student)); // only the source task itself
        $this->assertSame(1, $this->taskCount($mate));
    }

    public function test_a_teacher_only_gets_classmates_through_courses_they_own_but_a_director_sees_all(): void
    {
        [$campus, $director, $teacher, $student, $mate] = $this->world();
        $other = $this->staff('T', $campus);
        $this->course($student, $other['id'], 2); // the source student also studies subject 2 with another teacher
        $shared = $this->student($campus);
        $this->course($shared, $other['id'], 2);   // shares only the other teacher's course ...
        $this->course($shared, $teacher['id'], 9); // ... and has an unrelated course with this teacher, so they may write for them
        $task = $this->task($student);

        $this->as($teacher)->getJson("/api/v1/students/{$student->id}/study-classmates")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mate->id); // not $shared
        $this->apply($teacher, $task, [$shared->id])->assertOk()->assertJsonPath('data.refused', 1);
        $this->assertSame(0, $this->taskCount($shared));
        $this->as($director)->getJson("/api/v1/students/{$student->id}/study-classmates")->assertOk()->assertJsonCount(2, 'data');
        $this->apply($director, $task, [$shared->id])->assertOk()->assertJsonPath('data.created', 1);
    }

    public function test_a_substitute_teacher_who_can_see_but_not_own_the_student_is_refused_everywhere(): void
    {
        [$campus, , , $student, $mate] = $this->world();
        $task = $this->task($student);
        $sub = $this->staff('T', $campus);
        $course = StudentClass::query()->where('StudentID', $student->id)->first();
        DB::table('schedules')->insert([
            'student_id' => $student->id, 'teacher_id' => $sub['id'], 'day_of_week' => 1, 'start_time' => '16:00', 'end_time' => '17:00',
            'branch_id' => $campus, 'status' => 'scheduled', 'student_course_id' => $course->ID, 'original_schedule_id' => 1,
        ]);

        $this->as($sub)->getJson("/api/v1/students/{$student->id}/study-classmates")->assertForbidden();
        $this->copy($sub, $student)->assertForbidden();
        $this->apply($sub, $task, [$mate->id])->assertForbidden();
        $this->assertSame(0, $this->taskCount($mate));
    }

    public function test_apply_is_refused_for_a_classmate_in_another_campus_even_with_a_shared_course(): void
    {
        [, , $teacher, $student] = $this->world();
        $task = $this->task($student);
        $foreign = $this->student((int) CampusFactory::new()->create()->id);
        $this->course($foreign, $teacher['id']);

        $this->apply($teacher, $task, [$foreign->id])->assertOk()->assertJsonPath('data.refused', 1);
        $this->assertSame(0, $this->taskCount($foreign));
    }

    public function test_apply_works_for_director_and_super_admin(): void
    {
        [, $director, , $student, $mate] = $this->world();
        $task = $this->task($student);
        $admin = $this->staff('S', (int) CampusFactory::new()->create()->id);

        $this->apply($director, $task, [$mate->id])->assertOk()->assertJsonPath('data.created', 1);
        StudentStudyTask::query()->where('student_id', $mate->id)->delete();
        $this->apply($admin, $task, [$mate->id])->assertOk()->assertJsonPath('data.created', 1);
    }

    public function test_apply_validates_ids_and_caps_the_number_of_targets_with_the_controllers_constant(): void
    {
        [, , $teacher, $student] = $this->world();
        $task = $this->task($student);
        $cap = $this->cap('MAX_TARGETS');

        $this->apply($teacher, $task, [])->assertStatus(422);
        $this->apply($teacher, $task, ['x'])->assertStatus(422);
        $this->apply($teacher, $task, range(1000, 1000 + $cap))->assertStatus(422); // cap + 1 ids
        $this->apply($teacher, $task, range(1000, 1000 + $cap - 1))->assertOk()->assertJsonPath('data.refused', $cap);
    }

    public function test_classmates_lists_only_writable_enabled_classmates_of_the_same_campus(): void
    {
        [$campus, $director, $teacher, $student, $mate] = $this->world();
        $disabled = $this->student($campus);
        $disabled->update(['enable' => 0]);
        $this->course($disabled, $teacher['id']);
        $this->student($campus); // no shared course

        $this->as($teacher)->getJson("/api/v1/students/{$student->id}/study-classmates")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mate->id)->assertJsonPath('data.0.name', '虛構學生')
            ->assertJsonPath('max_targets', $this->cap('MAX_TARGETS'));
        $this->as($director)->getJson("/api/v1/students/{$student->id}/study-classmates")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_classmates_is_refused_for_other_teachers_and_other_campuses(): void
    {
        [$campus, , , $student] = $this->world();

        $this->as($this->staff('T', $campus))->getJson("/api/v1/students/{$student->id}/study-classmates")->assertForbidden();
        $this->as($this->staff('T', (int) CampusFactory::new()->create()->id))->getJson("/api/v1/students/{$student->id}/study-classmates")->assertForbidden();
        $this->as($this->staff('A', (int) CampusFactory::new()->create()->id))->getJson("/api/v1/students/{$student->id}/study-classmates")->assertForbidden();
    }

    public function test_anonymous_callers_are_refused(): void
    {
        [, , , $student] = $this->world();
        $task = $this->task($student);

        $this->postJson("/api/v1/students/{$student->id}/study-tasks/copy-week", ['week_start' => '2026-11-16'])->assertUnauthorized();
        $this->postJson("/api/v1/study-tasks/{$task->id}/apply-to-students", ['student_ids' => [1]])->assertUnauthorized();
        $this->getJson("/api/v1/students/{$student->id}/study-classmates")->assertUnauthorized();
    }
}
