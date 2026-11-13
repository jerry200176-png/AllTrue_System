<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentLearningPlan;
use App\Models\StudentPlanUnit;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** 「這堂上完的單元」(#4970 step 5). Fake data only; "today" is frozen at 2026-11-13. */
class StudentPlanUnitLessonApiTest extends TestCase
{
    use RefreshDatabase;

    private int $campus;
    private array $dir;
    private array $teacher;
    private StudentClass $course;
    private StudentLearningPlan $plan;
    /** @var array<int, StudentPlanUnit> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-13 10:00:00', 'Asia/Taipei'));
        $this->campus = (int) CampusFactory::new()->create()->id;
        $this->dir = $this->staff('A', $this->campus);
        $this->teacher = $this->staff('T', $this->campus);
        $student = Student::create(['name' => '虛構學生', 'CampusID' => $this->campus, 'ClassID' => 3, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $this->course = $this->makeCourse($student, $this->teacher['id']);
        $this->plan = $this->makePlan($student, $this->course);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(string $type, int $campus): array
    {
        $u = User::create(['LoginName' => $type . random_int(1, 999999) . '@example.com', 'Name' => 'Staff', 'PSW' => 'secret', 'type' => $type,
            'phone' => (string) random_int(900000000, 999999999), 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $u->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        $t = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $u->id, 'token' => $t, 'expires_at' => now()->addDay()]);

        return ['token' => $t, 'id' => (int) $u->id];
    }

    private function as(array $staff)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $staff['token'], 'Accept' => 'application/json']);
    }

    private function makeCourse(Student $student, int $teacherId): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $student->id, 'TeacherID' => $teacherId, 'GradeID' => 1, 'SubjectID' => 1, 'by1' => 1,
            'StartDate' => '2026-08-01', 'TotalHours' => 20, 'Charge' => 1000, 'Pay' => 0, 'Paid' => 0, 'Rate' => 1000,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10, 'RemainingSessions' => 10,
            'UsedSessions' => 0, 'SessionDuration' => 60, 'ClassType' => 'one_on_one',
        ]);
    }

    /** Ten units, one week each from 2026-10-05; the lesson on 2026-11-09 falls in unit 6 (11-09..11-15). */
    private function makePlan(Student $student, StudentClass $course, string $status = 'active'): StudentLearningPlan
    {
        $plan = StudentLearningPlan::create(['campus_id' => $student->CampusID, 'student_id' => $student->id, 'student_class_id' => $course->ID,
            'title' => '七上數學', 'status' => $status, 'plan_start' => '2026-10-05', 'plan_end' => '2026-12-13']);
        foreach (range(1, 10) as $n) {
            $start = Carbon::parse('2026-10-05')->addWeeks($n - 1);
            $unit = StudentPlanUnit::create(['plan_id' => $plan->id, 'sort_order' => $n, 'title' => "單元{$n}",
                'planned_start' => $start->toDateString(), 'planned_end' => $start->copy()->addDays(6)->toDateString()]);
            $this->units[$n] = $unit;
        }

        return $plan;
    }

    private function session(?StudentClass $course = null, string $date = '2026-11-09'): ClassSession
    {
        return ClassSession::create(['StudentClassID' => ($course ?? $this->course)->ID, 'SessionDate' => $date,
            'StartTime' => '16:00:00', 'EndTime' => '17:00:00', 'Status' => 'attended']);
    }

    private function record(ClassSession $s, string $status = 'pending', ?int $teacherId = null): LearningRecord
    {
        return LearningRecord::create(['StudentClassID' => $s->StudentClassID, 'ClassSessionID' => $s->id,
            'TeacherID' => $teacherId ?? $this->teacher['id'], 'Subject' => 'Math', 'Content' => '評量', 'Progress' => '第三章', 'Status' => $status,
            'SessionDate' => $s->SessionDate, 'StartTime' => '16:00:00', 'EndTime' => '17:00:00']);
    }

    private function ids(array $unitNumbers): array
    {
        return array_map(fn ($n) => $this->units[$n]->id, $unitNumbers);
    }

    private function candidates(array $who, ClassSession $s, string $extra = '')
    {
        return $this->as($who)->getJson("/api/v1/learning-plan-units/candidates?class_session_id={$s->id}{$extra}");
    }

    private function sync(array $who, LearningRecord $lr, array $unitNumbers)
    {
        return $this->as($who)->putJson("/api/v1/learning-records/{$lr->id}/plan-units", ['unit_ids' => $this->ids($unitNumbers)]);
    }

    public function test_no_active_plan_gives_nothing(): void
    {
        $this->plan->update(['status' => 'archived']);

        $this->candidates($this->teacher, $this->session())->assertOk()->assertJsonPath('data.plan', null)->assertJsonCount(0, 'data.units');
    }

    public function test_candidates_put_units_around_the_lesson_date_first_and_cap_at_eight(): void
    {
        $res = $this->candidates($this->teacher, $this->session())->assertOk()
            ->assertJsonPath('data.can_tick', true)->assertJsonPath('data.total', 10)->assertJsonCount(8, 'data.units');
        $titles = array_column($res->json('data.units'), 'title');

        $this->assertSame('單元6', $titles[0], 'the unit planned for the lesson week comes first');
        $this->assertSame(['單元6', '單元5', '單元4', '單元7', '單元3', '單元8', '單元2', '單元9'], $titles);
        $this->assertTrue($res->json('data.units.1.behind'), 'planned end before the lesson date and not done = behind');
        $this->assertFalse($res->json('data.units.0.behind'));

        $this->candidates($this->teacher, $this->session(), '&all=1')->assertJsonCount(10, 'data.units');
    }

    public function test_tick_sets_lesson_date_actor_source_and_percent_done_follows(): void
    {
        $s = $this->session();
        $lr = $this->record($s);

        $this->sync($this->teacher, $lr, [5, 6])->assertOk()->assertJsonCount(2, 'data.unit_ids');

        $u = $this->units[6]->fresh();
        $this->assertSame('2026-11-09', $u->completed_at->toDateString());
        $this->assertSame($this->teacher['id'], (int) $u->completed_by);
        $this->assertSame($s->id, (int) $u->completed_session_id);
        $this->assertSame($lr->id, (int) $u->completed_record_id);
        $this->as($this->dir)->getJson("/api/v1/students/{$this->plan->student_id}/learning-plans")
            ->assertJsonPath('data.0.units.5.completed_on', '2026-11-09')->assertJsonPath('data.0.percent_done', 20)->assertJsonPath('data.0.units_behind', 4); // units 1-4 ended before today and are not done
    }

    public function test_sync_is_idempotent_and_untick_clears_only_this_lessons_units(): void
    {
        $s = $this->session();
        $lr = $this->record($s);
        $this->sync($this->teacher, $lr, [6])->assertOk();
        $first = $this->units[6]->fresh()->completed_at;
        $this->sync($this->teacher, $lr, [6])->assertOk();
        $this->assertEquals($first, $this->units[6]->fresh()->completed_at);

        $this->sync($this->teacher, $lr, [])->assertOk()->assertJsonCount(0, 'data.unit_ids');
        $u = $this->units[6]->fresh();
        $this->assertNull($u->completed_at);
        $this->assertNull($u->completed_by);
        $this->assertNull($u->completed_session_id);
        $this->assertNull($u->completed_record_id);
    }

    public function test_candidates_keep_units_ticked_from_this_lesson_but_drop_those_done_elsewhere(): void
    {
        $s1 = $this->session(null, '2026-11-02');
        $lr1 = $this->record($s1);
        $this->sync($this->teacher, $lr1, [5])->assertOk();
        $s2 = $this->session();
        $lr2 = $this->record($s2);
        $this->sync($this->teacher, $lr2, [6])->assertOk();

        $titles = array_column($this->candidates($this->teacher, $s2, '&all=1')->json('data.units'), 'title');
        $this->assertContains('單元6', $titles);
        $this->assertNotContains('單元5', $titles, 'done in another lesson');
        $checked = array_column(array_filter($this->candidates($this->teacher, $s2, '&all=1')->json('data.units'), fn ($u) => $u['checked']), 'title');
        $this->assertSame(['單元6'], array_values($checked));
    }

    public function test_a_unit_completed_by_another_lesson_is_not_taken_or_cleared(): void
    {
        $lr1 = $this->record($this->session(null, '2026-11-02'));
        $this->sync($this->teacher, $lr1, [5])->assertOk();
        $lr2 = $this->record($this->session());

        $this->sync($this->teacher, $lr2, [5, 6])->assertOk();
        $this->assertSame($lr1->id, (int) $this->units[5]->fresh()->completed_record_id, 'not stolen');
        $this->assertSame($lr2->id, (int) $this->units[6]->fresh()->completed_record_id);

        $this->sync($this->teacher, $lr2, [])->assertOk();
        $this->assertNotNull($this->units[5]->fresh()->completed_at, 'untick from lesson 2 must not clear lesson 1');
        $this->assertNull($this->units[6]->fresh()->completed_at);
    }

    public function test_units_of_another_plan_or_course_are_refused(): void
    {
        $student = Student::create(['name' => '另一位', 'CampusID' => $this->campus, 'ClassID' => 3, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $otherCourse = $this->makeCourse($student, $this->teacher['id']);
        $other = StudentPlanUnit::query()->where('plan_id', $this->makePlan($student, $otherCourse)->id)->first();
        $lr = $this->record($this->session());

        $this->as($this->teacher)->putJson("/api/v1/learning-records/{$lr->id}/plan-units", ['unit_ids' => [$other->id]])->assertStatus(422);
        $this->assertNull($other->fresh()->completed_at);
    }

    public function test_other_teacher_other_campus_and_substitute_are_refused(): void
    {
        $lr = $this->record($this->session());
        $stranger = $this->staff('T', $this->campus);
        $this->sync($stranger, $lr, [6])->assertForbidden();
        $this->candidates($stranger, $this->session())->assertForbidden();

        $otherCampus = (int) CampusFactory::new()->create()->id;
        $this->sync($this->staff('A', $otherCampus), $lr, [6])->assertForbidden();
        $this->candidates($this->staff('A', $otherCampus), $this->session())->assertForbidden();
        $this->assertNull($this->units[6]->fresh()->completed_at);
    }

    public function test_an_evaluation_the_teacher_can_no_longer_edit_refuses_ticks_but_the_director_may(): void
    {
        $lr = $this->record($this->session(), 'approved');

        $this->sync($this->teacher, $lr, [6])->assertStatus(409);
        $this->assertNull($this->units[6]->fresh()->completed_at);
        $this->sync($this->dir, $lr, [6])->assertOk();
        $this->assertNotNull($this->units[6]->fresh()->completed_at);
    }

    public function test_a_future_lesson_and_a_voided_record_are_refused(): void
    {
        $this->sync($this->teacher, $this->record($this->session(null, '2026-11-20')), [6])->assertStatus(422);
        $voided = $this->record($this->session());
        $voided->forceFill(['VoidedAt' => now()])->save();
        $this->sync($this->teacher, $voided, [6])->assertStatus(422);
    }

    public function test_a_lesson_later_today_that_has_not_started_is_refused(): void
    {
        $s = $this->session(null, '2026-11-13'); // frozen now is 10:00, the lesson starts at 16:00
        $lr = $this->record($s);

        $this->sync($this->teacher, $lr, [6])->assertStatus(422);
        $this->assertNull($this->units[6]->fresh()->completed_at);
    }

    public function test_delete_and_refill_keeps_the_lessons_ticks_owned_by_the_session(): void
    {
        $s = $this->session();
        $old = $this->record($s);
        $this->sync($this->teacher, $old, [6])->assertOk();
        $old->delete(); // LearningRecordController::destroy hard-deletes
        $new = $this->record($s);

        $checked = array_values(array_filter($this->candidates($this->teacher, $s)->json('data.units'), fn ($u) => $u['checked']));
        $this->assertSame(['單元6'], array_column($checked, 'title'));

        // Ticking again re-points the unit to the current evaluation.
        $this->sync($this->teacher, $new, [6])->assertOk()->assertJsonPath('data.unit_ids.0', $this->units[6]->id);
        $this->assertSame($new->id, (int) $this->units[6]->fresh()->completed_record_id);

        // Unticking clears it.
        $this->sync($this->teacher, $new, [])->assertOk()->assertJsonCount(0, 'data.unit_ids');
        $u = $this->units[6]->fresh();
        $this->assertNull($u->completed_at);
        $this->assertNull($u->completed_session_id);
        $this->assertNull($u->completed_record_id);
    }

    public function test_untick_after_the_record_was_deleted_and_refilled_clears_the_unit(): void
    {
        $s = $this->session();
        $old = $this->record($s);
        $this->sync($this->teacher, $old, [6])->assertOk();
        $old->delete();
        $new = $this->record($s);

        $this->sync($this->teacher, $new, [])->assertOk();
        $this->assertNull($this->units[6]->fresh()->completed_at);
        $this->assertNull($this->units[6]->fresh()->completed_record_id);
    }

    public function test_a_plan_unit_manually_unticked_on_the_gantt_forgets_its_source(): void
    {
        $lr = $this->record($this->session());
        $this->sync($this->teacher, $lr, [6])->assertOk();

        $this->as($this->dir)->patchJson("/api/v1/learning-plans/{$this->plan->id}/units/{$this->units[6]->id}", ['completed' => false])->assertOk();

        $u = $this->units[6]->fresh();
        $this->assertNull($u->completed_session_id);
        $this->assertNull($u->completed_record_id);
    }
}
