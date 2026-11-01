<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for CoursePackageController::createMultiSubject schedule wiring (PRD §FR-001~FR-003).
 *
 * Key assertions:
 *  - days_of_week (ISO 1=Mon … 7=Sun) is written VERBATIM to StudentClass.week / week1..week6
 *    (no ((int)$dow + 6) % 7 bug regression — GAP-01).
 *  - extendSessionsIfNeeded is invoked so ClassSession rows are auto-generated.
 *  - Members without schedule data produce 0 ClassSession and still return 201.
 *  - Monthly (date-billed) packages never trigger schedule extension.
 */
class PackageCreateWithScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-sched-' . uniqid() . '@test.com',
            'Name'      => '主任',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => 900000000,
        ]);

        foreach ($campusIds as $cid) {
            UserCampus::create([
                'CampusID' => $cid,
                'UserID'   => $user->id,
                'Admin'    => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function makeStudent(int $campusId = 1): Student
    {
        return Student::create([
            'name'         => '測試學生' . uniqid(),
            'CampusID'     => $campusId,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }

    private function makeTeacher(): User
    {
        return User::create([
            'LoginName' => 'teacher-sched-' . uniqid() . '@test.com',
            'Name'      => '老師' . uniqid(),
            'PSW'       => 'secret',
            'type'      => 'T',
            'phone'     => 911000000,
        ]);
    }

    private function seedSubjects(): void
    {
        DB::table('Subject')->insertOrIgnore([
            ['id' => 1, 'Subject_Name' => 'Math', 'School_id' => 1, 'Grade_no' => 1],
            ['id' => 2, 'Subject_Name' => 'English', 'School_id' => 1, 'Grade_no' => 1],
            ['id' => 3, 'Subject_Name' => 'Physics', 'School_id' => 1, 'Grade_no' => 1],
        ]);
    }

    private function postCreate(string $token, array $payload)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/course-packages/create-multi-subject', $payload);
    }

    private function futurePlanned(int $classId): int
    {
        return ClassSession::where('StudentClassID', $classId)->where('Status', 'scheduled')
            ->whereDate('SessionDate', '>=', now()->toDateString())->count();
    }

    /** Two subjects (Mon 16:00 / Wed 14:00) sharing $total lessons, starting tomorrow. */
    private function createMonWedPool(string $token, int $total): array
    {
        $student = $this->makeStudent(1);
        $this->seedSubjects();
        $start = now()->addDay()->toDateString();
        $res = $this->postCreate($token, [
            'student_id' => $student->id, 'branch_id' => 1, 'name' => '共用方案', 'total_sessions' => $total,
            'rate' => 500, 'class_type' => 'one_on_one',
            'subjects' => [
                ['subject_id' => 1, 'teacher_id' => $this->makeTeacher()->id, 'duration_hours' => 2, 'days_of_week' => [1], 'start_time' => '16:00', 'start_date' => $start],
                ['subject_id' => 2, 'teacher_id' => $this->makeTeacher()->id, 'duration_hours' => 2, 'days_of_week' => [3], 'start_time' => '14:00', 'start_date' => $start],
            ],
        ])->assertStatus(201);

        return [(int) $res->json('package_id'), StudentClass::where('PackageID', $res->json('package_id'))->orderBy('ID')->get()];
    }

    public function test_raising_the_pool_total_adds_only_the_new_lessons_across_subjects(): void
    {
        $token = $this->createDirectorToken([1]);
        [$pkgId, $members] = $this->createMonWedPool($token, 4);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/course-packages/{$pkgId}", ['total_sessions' => 6])->assertOk();

        $this->assertSame([3, 3], $members->map(fn ($m) => $this->futurePlanned($m->ID))->all());
    }

    /** Each member's scheduled dates, all members together in date order. */
    private function plannedDates($members): array
    {
        return ClassSession::whereIn('StudentClassID', $members->pluck('ID'))->where('Status', 'scheduled')
            ->orderBy('SessionDate')->pluck('SessionDate')->map(fn ($d) => substr((string) $d, 0, 10))->all();
    }

    /** A pre-#4730 pool: the old fill gave each member $extra more weekly lessons past its own. */
    private function overPlan($members, int $extra): void
    {
        foreach ($members as $m) {
            $last = ClassSession::where('StudentClassID', $m->ID)->orderByDesc('SessionDate')->first();
            foreach (range(1, $extra) as $w) {
                ClassSession::create(['StudentClassID' => $m->ID, 'SessionDate' => Carbon::parse($last->SessionDate)->addWeeks($w)->toDateString(),
                    'StartTime' => $last->StartTime, 'EndTime' => $last->EndTime, 'Status' => 'scheduled', 'Note' => '']);
            }
        }
    }

    private function excess(int $pkgId): array
    {
        return app(\App\Services\SharedPackagePlanningService::class)->sharedPoolExcess(CoursePackage::findOrFail($pkgId));
    }

    private function dates($rows): array
    {
        return $rows->map(fn ($r) => substr((string) $r->SessionDate, 0, 10))->sort()->values()->all();
    }

    public function test_pool_excess_keeps_the_earliest_and_lessons_with_history_and_a_renewal_refills_deleted_dates(): void
    {
        $token = $this->createDirectorToken([1]);
        [$pkgId, $members] = $this->createMonWedPool($token, 4);
        $this->overPlan($members, 2);
        $all = $this->plannedDates($members);
        $this->assertCount(8, $all);
        // A leave on the very last extra lesson: something points at it, so it stays and keeps its place.
        $last = ClassSession::whereIn('StudentClassID', $members->pluck('ID'))->orderByDesc('SessionDate')->first();
        StudentSignIn::create(['StudentClassID' => $last->StudentClassID, 'StudentID' => $members[0]->StudentID,
            'ClassSessionID' => $last->id, 'Status' => 'leave', 'SignInDT' => ($at = substr((string) $last->SessionDate, 0, 10) . ' 16:00:00'), 'SignOutDT' => $at]);

        // A substitute on the 7th lesson: the substitute action writes dated schedules rows by course and date, not lesson id.
        $sub = ClassSession::whereIn('StudentClassID', $members->pluck('ID'))->orderBy('SessionDate')->skip(6)->first();
        DB::table('schedules')->insert(['student_id' => $members[0]->StudentID, 'teacher_id' => $this->makeTeacher()->id, 'subject' => 'Math',
            'day_of_week' => Carbon::parse($sub->SessionDate)->dayOfWeekIso, 'start_time' => $sub->StartTime, 'end_time' => $sub->EndTime,
            'duration_hours' => 2, 'class_type' => 'one_on_one', 'status' => 'scheduled', 'type' => 'normal', 'deduction' => 1, 'branch_id' => 1,
            'schedule_date' => substr((string) $sub->SessionDate, 0, 10), 'student_course_id' => $sub->StudentClassID]);
        // A parent's feedback whose login session id happens to equal an extra lesson's id protects nothing (Codex P2).
        $coincidence = ClassSession::whereIn('StudentClassID', $members->pluck('ID'))->orderBy('SessionDate')->skip(5)->first();
        DB::table('learning_record_feedbacks')->insert(['learning_record_id' => 1, 'student_id' => $members[0]->StudentID, 'student_class_id' => 999,
            'teacher_id' => 1, 'campus_id' => 1, 'content' => 'x', 'parent_session_id' => $coincidence->id]);

        $x = $this->excess($pkgId);
        $this->assertSame(['remaining' => 4, 'planned' => 8, 'blocked' => 0], array_intersect_key($x, ['remaining' => 0, 'planned' => 0, 'blocked' => 0]));
        $this->assertSame(array_slice($all, 2, 4), $this->dates($x['rows']), 'keeps the earliest 2, the substituted lesson and the one with a leave');

        // The cleanup deletes through the model (the observer keeps each row in schedule_audit_logs).
        $x['rows']->each->delete();
        $this->assertSame([$all[0], $all[1], $all[6], $all[7]], $this->plannedDates($members));
        $this->assertSame(4, DB::table('schedule_audit_logs')->where('action_type', 'delete')->count());
        $this->assertTrue($this->excess($pkgId)['rows']->isEmpty());

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/course-packages/{$pkgId}", ['total_sessions' => 8])->assertOk();
        $this->assertSame($all, $this->plannedDates($members), 'a renewal re-fills the removed dates, no gap');
    }

    public function test_pool_excess_takes_a_whole_group_slot_from_every_member(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $this->seedSubjects();
        $spec = fn (int $subject) => ['subject_id' => $subject, 'teacher_id' => $this->makeTeacher()->id, 'duration_hours' => 2, 'days_of_week' => [1], 'start_time' => '16:00', 'start_date' => now()->addDay()->toDateString()];
        $pkgId = $this->postCreate($token, ['student_id' => $student->id, 'branch_id' => 1, 'name' => '團體共用', 'total_sessions' => 2,
            'rate' => 500, 'class_type' => 'one_on_two', 'subjects' => [$spec(1), $spec(2)]])->assertStatus(201)->json('package_id');
        $members = StudentClass::where('PackageID', $pkgId)->orderBy('ID')->get();
        $this->overPlan($members, 1);
        $this->assertCount(6, $this->plannedDates($members), '3 group lessons, 2 rows each');

        $x = $this->excess($pkgId);
        $this->assertSame([2, 3], [$x['remaining'], $x['planned']]);
        $this->assertCount(2, $x['rows'], 'the last group lesson, one row per member');
        $this->assertCount(1, array_unique($this->dates($x['rows'])));
        $x['rows']->each->delete();
        $this->assertCount(4, $this->plannedDates($members));
        $this->assertCount(2, array_unique($this->plannedDates($members)), 'both members keep the same 2 group lessons');
    }

    public function test_lowering_the_pool_total_cancels_the_latest_lessons_across_subjects(): void
    {
        $token = $this->createDirectorToken([1]);
        [$pkgId, $members] = $this->createMonWedPool($token, 6);
        $before = $this->plannedDates($members);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/course-packages/{$pkgId}", ['total_sessions' => 4])->assertOk();

        $this->assertSame(array_slice($before, 0, 4), $this->plannedDates($members));
        $this->assertSame(2, ClassSession::whereIn('StudentClassID', $members->pluck('ID'))->where('Status', 'cancelled')->count());
    }

    public function test_group_pool_joining_a_slot_a_sibling_already_planned_costs_nothing(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $this->seedSubjects();
        $start = now()->addDay()->toDateString();
        $spec = fn (int $subject) => ['subject_id' => $subject, 'teacher_id' => $this->makeTeacher()->id, 'duration_hours' => 2, 'days_of_week' => [1], 'start_time' => '16:00', 'start_date' => $start];
        $pkgId = $this->postCreate($token, [
            'student_id' => $student->id, 'branch_id' => 1, 'name' => '團體共用', 'total_sessions' => 4,
            'rate' => 500, 'class_type' => 'one_on_two', 'subjects' => [$spec(1), $spec(2)],
        ])->assertStatus(201)->json('package_id');
        [$a, $b] = StudentClass::where('PackageID', $pkgId)->orderBy('ID')->get()->all();
        $this->assertSame([4, 4], [$this->futurePlanned($a->ID), $this->futurePlanned($b->ID)]); // 4 shared slots

        // B is missing its first two rows; A still has them, so they are already in the pool's plan.
        ClassSession::where('StudentClassID', $b->ID)->orderBy('SessionDate')->limit(2)->get()->each->delete();
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/course-packages/{$pkgId}", ['total_sessions' => 6])->assertOk();

        $slots = ClassSession::whereIn('StudentClassID', [$a->ID, $b->ID])->where('Status', 'scheduled')
            ->get()->map(fn ($s) => substr((string) $s->SessionDate, 0, 10))->unique()->count();
        $this->assertSame(6, $slots, 'raising 4 → 6 plans 6 distinct group lessons');
    }

    /** @return array<string, array{int}> weeks A's 4th lesson moves; 16 is past B's whole candidate sequence (#4733) */
    public static function movedWeeks(): array
    {
        return ['two weeks' => [2], 'sixteen weeks' => [16]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('movedWeeks')]
    public function test_group_pool_with_a_moved_lesson_fills_the_selected_slots_not_a_count(int $weeks): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $this->seedSubjects();
        $start = now()->addDay()->toDateString();
        // B's lessons are one hour, A's two: a lesson B joins keeps B's own length (Codex P2 on #4737).
        $spec = fn (int $subject, int $hours) => ['subject_id' => $subject, 'teacher_id' => $this->makeTeacher()->id, 'duration_hours' => $hours, 'days_of_week' => [1], 'start_time' => '16:00', 'start_date' => $start];
        $pkgId = $this->postCreate($token, [
            'student_id' => $student->id, 'branch_id' => 1, 'name' => '團體共用', 'total_sessions' => 4,
            'rate' => 500, 'class_type' => 'one_on_two', 'subjects' => [$spec(1, 2), $spec(2, 1)],
        ])->assertStatus(201)->json('package_id');
        [$a, $b] = StudentClass::where('PackageID', $pkgId)->orderBy('ID')->get()->all();
        // A: weekly slots 1, 2, 3 and 4 + $weeks (its 4th lesson moved later); B: only slot 1. 4 distinct lessons planned.
        $rows = fn ($m) => ClassSession::where('StudentClassID', $m->ID)->orderBy('SessionDate')->get();
        $fourth = $rows($a)[3];
        $fourth->update(['SessionDate' => \Carbon\Carbon::parse($fourth->SessionDate)->addWeeks($weeks)->toDateString()]);
        $rows($b)->slice(1)->each->delete();

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/course-packages/{$pkgId}", ['total_sessions' => 5])->assertOk();

        $slots = ClassSession::whereIn('StudentClassID', [$a->ID, $b->ID])->where('Status', 'scheduled')
            ->get()->map(fn ($s) => substr((string) $s->SessionDate, 0, 10))->unique()->count();
        $this->assertSame(5, $slots, '5 bought = 5 distinct group lessons, even with a moved lesson');
        // Each member sits in every group lesson: weekly slots 1, 2, 3, 4 and the moved one (past B's first contract dates).
        $dates = fn ($m) => ClassSession::where('StudentClassID', $m->ID)->where('Status', 'scheduled')->orderBy('SessionDate')
            ->pluck('SessionDate')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $this->assertSame($dates($a), $dates($b));
        $this->assertCount(5, $dates($b));
        $this->assertSame(['17:00:00'], ClassSession::where('StudentClassID', $b->ID)->pluck('EndTime')->map(fn ($t) => substr((string) $t, 0, 8))->unique()->values()->all());
    }

    public function test_group_pool_never_joins_a_siblings_make_up_off_the_weekly_pattern(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $this->seedSubjects();
        $start = now()->addDay()->toDateString();
        $spec = fn (int $subject) => ['subject_id' => $subject, 'teacher_id' => $this->makeTeacher()->id, 'duration_hours' => 2, 'days_of_week' => [1], 'start_time' => '16:00', 'start_date' => $start];
        $pkgId = $this->postCreate($token, [
            'student_id' => $student->id, 'branch_id' => 1, 'name' => '團體共用', 'total_sessions' => 4,
            'rate' => 500, 'class_type' => 'one_on_two', 'subjects' => [$spec(1), $spec(2)],
        ])->assertStatus(201)->json('package_id');
        [$a, $b] = StudentClass::where('PackageID', $pkgId)->orderBy('ID')->get()->all();
        // A's 4th lesson becomes its own make-up on a Wednesday at 10:00 (not the group's Monday 16:00).
        $fourth = ClassSession::where('StudentClassID', $a->ID)->orderBy('SessionDate')->get()[3];
        $makeUp = \Carbon\Carbon::parse($fourth->SessionDate)->next(\Carbon\Carbon::WEDNESDAY)->toDateString();
        $fourth->update(['SessionDate' => $makeUp, 'StartTime' => '10:00:00', 'EndTime' => '12:00:00']);

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/course-packages/{$pkgId}", ['total_sessions' => 6])->assertOk();

        $this->assertFalse(ClassSession::where('StudentClassID', $b->ID)->whereDate('SessionDate', $makeUp)->exists(), 'B never joins A\'s make-up');
    }

    public function test_group_pool_never_joins_a_siblings_lesson_before_the_members_own_start(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $this->seedSubjects();
        $monday = now()->next(\Carbon\Carbon::MONDAY);
        $spec = fn (int $subject, string $start) => ['subject_id' => $subject, 'teacher_id' => $this->makeTeacher()->id, 'duration_hours' => 2, 'days_of_week' => [1], 'start_time' => '16:00', 'start_date' => $start];
        // A starts next Monday; B 16 weeks later (Codex P2 on #4737).
        $bStart = $monday->copy()->addWeeks(16)->toDateString();
        $pkgId = $this->postCreate($token, [
            'student_id' => $student->id, 'branch_id' => 1, 'name' => '團體共用', 'total_sessions' => 4,
            'rate' => 500, 'class_type' => 'one_on_two', 'subjects' => [$spec(1, $monday->toDateString()), $spec(2, $bStart)],
        ])->assertStatus(201)->json('package_id');
        [, $b] = StudentClass::where('PackageID', $pkgId)->orderBy('ID')->get()->all();

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/v1/course-packages/{$pkgId}", ['total_sessions' => 5])->assertOk();

        $this->assertFalse(ClassSession::where('StudentClassID', $b->ID)->whereDate('SessionDate', '<', $bStart)->exists(), 'B has nothing before its start');
    }

    public function test_auto_fill_never_plans_past_a_fully_planned_pool(): void
    {
        $token = $this->createDirectorToken([1]);
        [, $members] = $this->createMonWedPool($token, 4);
        $this->assertSame([2, 2], $members->map(fn ($m) => $this->futurePlanned($m->ID))->all());

        // Any other refill path (course edit, sync command, transfer) goes through the same function.
        app(\App\Services\Scheduling\ContractSessionSchedule::class)->extendSessionsIfNeeded($members[0]->fresh(), 4);

        $this->assertSame(2, $this->futurePlanned($members[0]->ID));
    }

    // ─── FR-001 / FR-003：含時段，自動補排 ───

    public function test_a_lesson_already_over_at_create_uses_one_of_the_bought_lessons(): void
    {
        // #4757: Sunday 17:00, start date today. Wed 14:00's first lesson (the start date) already ended and is backfilled as
        // completed; the pool used to plan 8 more on top of it (9 for 8 bought).
        $this->travelTo(Carbon::parse('2026-11-01 17:00:00'));
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $this->seedSubjects();

        $res = $this->postCreate($token, [
            'student_id' => $student->id, 'branch_id' => 1, 'name' => '排課方案', 'total_sessions' => 8, 'rate' => 500, 'class_type' => 'one_on_one',
            'subjects' => [
                ['subject_id' => 1, 'teacher_id' => $this->makeTeacher()->id, 'duration_hours' => 2, 'days_of_week' => [1], 'start_time' => '16:00'],
                ['subject_id' => 2, 'teacher_id' => $this->makeTeacher()->id, 'duration_hours' => 2, 'days_of_week' => [3], 'start_time' => '14:00'],
            ],
        ])->assertStatus(201);

        $members = StudentClass::where('PackageID', $res->json('package_id'))->pluck('ID');
        $statuses = ClassSession::whereIn('StudentClassID', $members)->pluck('Status')->countBy()->all();
        ksort($statuses);
        $this->assertSame(['completed' => 1, 'scheduled' => 7], $statuses, 'one already taught + 7 planned = the 8 bought');
    }

    public function test_a_group_lesson_already_over_at_create_uses_one_bought_lesson_for_all_members(): void
    {
        // Codex P2 on #4762: two subjects share the ended 14:00 group slot; it is one of the 8 bought, not two.
        $this->travelTo(Carbon::parse('2026-11-01 17:00:00'));
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $this->seedSubjects();
        $spec = fn (int $subject) => ['subject_id' => $subject, 'teacher_id' => $this->makeTeacher()->id, 'duration_hours' => 2, 'days_of_week' => [1], 'start_time' => '14:00'];

        $pkgId = $this->postCreate($token, ['student_id' => $student->id, 'branch_id' => 1, 'name' => '團體共用', 'total_sessions' => 8,
            'rate' => 500, 'class_type' => 'one_on_two', 'subjects' => [$spec(1), $spec(2)]])->assertStatus(201)->json('package_id');

        $rows = ClassSession::whereIn('StudentClassID', StudentClass::where('PackageID', $pkgId)->pluck('ID'))->get();
        $slots = $rows->map(fn ($r) => substr((string) $r->SessionDate, 0, 10) . ' ' . $r->Status)->unique()->countBy(fn ($k) => explode(' ', $k)[1])->all();
        ksort($slots);
        $this->assertSame(['completed' => 1, 'scheduled' => 7], $slots, 'one group lesson already taught + 7 planned = the 8 bought');
    }

    public function test_a_group_slot_confirmed_by_one_subject_is_not_reserved_again_for_its_sibling(): void
    {
        // #4767: only subject 1 sends the ended slot in confirmed_dates; subject 2 sends none and must not reserve it again.
        $this->travelTo(Carbon::parse('2026-11-01 17:00:00'));
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $this->seedSubjects();
        $spec = fn (int $subject) => ['subject_id' => $subject, 'teacher_id' => $this->makeTeacher()->id, 'duration_hours' => 2, 'days_of_week' => [1], 'start_time' => '14:00'];

        $pkgId = $this->postCreate($token, ['student_id' => $student->id, 'branch_id' => 1, 'name' => '團體共用', 'total_sessions' => 8,
            'rate' => 500, 'class_type' => 'one_on_two', 'subjects' => [['confirmed_dates' => ['2026-11-01']] + $spec(1), $spec(2)]])
            ->assertStatus(201)->json('package_id');

        $rows = ClassSession::whereIn('StudentClassID', StudentClass::where('PackageID', $pkgId)->pluck('ID'))->get();
        $slots = $rows->map(fn ($r) => substr((string) $r->SessionDate, 0, 10) . ' ' . substr((string) $r->StartTime, 0, 5) . ' ' . ($r->Status === 'scheduled' ? 'future' : 'past'))->unique();
        $this->assertSame(['future' => 7, 'past' => 1], $slots->countBy(fn ($k) => explode(' ', $k)[2])->sortKeys()->all(), '1 historical + 7 future = the 8 bought');
        $this->assertSame(1, $rows->where('SessionDate', '<', '2026-11-02')->unique(fn ($r) => substr((string) $r->SessionDate, 0, 10) . $r->StartTime)->count(), 'the ended slot exists once');
    }

    public function test_create_with_days_of_week_generates_class_sessions(): void
    {
        // The first lesson is the start date (today) at each subject's time; once 14:00 or 16:00 passed, the pool
        // planned around a started lesson and the per-subject split read 5/3. Pin the clock to a Sunday morning.
        $this->travelTo(Carbon::parse('2026-11-01 09:00:00'));
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->seedSubjects();

        $res = $this->postCreate($token, [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '排課方案',
            'total_sessions' => 8,
            'rate'           => 500,
            'class_type'     => 'one_on_one',
            'subjects'       => [
                [
                    'subject_id'     => 1,
                    'teacher_id'     => $t1->id,
                    'duration_hours' => 2,
                    'days_of_week'   => [1],
                    'start_time'     => '16:00',
                ],
                [
                    'subject_id'     => 2,
                    'teacher_id'     => $t2->id,
                    'duration_hours' => 2,
                    'days_of_week'   => [3],
                    'start_time'     => '14:00',
                ],
            ],
        ]);

        $res->assertStatus(201);
        $json = $res->json();
        $this->assertArrayHasKey('members_scheduled', $json);
        $this->assertCount(2, $json['members_scheduled']);

        $pkgId = $json['package_id'];
        $members = StudentClass::where('PackageID', $pkgId)->get();
        $this->assertCount(2, $members);
        $futurePlanned = ClassSession::whereIn('StudentClassID', $members->pluck('ID'))
            ->where('Status', 'scheduled')
            ->whereDate('SessionDate', '>=', now()->toDateString())
            ->count();
        // The pool pre-plans what was bought (8), not 8 per subject; Mon/Wed alternate by date.
        $this->assertSame(8, $futurePlanned);
        $this->assertSame(8, $json['package']['future_planned_sessions']);
        $this->assertSame(0, $json['package']['overage_sessions']);
        $this->assertFalse($json['package']['renewal_warning']);

        foreach ($members as $m) {
            $this->assertSame(4, $this->futurePlanned($m->ID), "Member {$m->ID} takes every other lesson");
        }

        foreach ($json['members_scheduled'] as $ms) {
            $this->assertGreaterThanOrEqual(4, $ms['scheduled_count']);
            $this->assertNotNull($ms['first_session_date']);
            $this->assertTrue((bool) $ms['has_schedule']);
        }
    }

    // ─── NFR-002：選填排課時段（降級）───

    public function test_create_without_schedule_no_sessions(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->seedSubjects();

        $res = $this->postCreate($token, [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '純建立無排課',
            'total_sessions' => 12,
            'rate'           => 500,
            'subjects'       => [
                ['subject_id' => 1, 'teacher_id' => $t1->id, 'duration_hours' => 2],
                ['subject_id' => 2, 'teacher_id' => $t2->id, 'duration_hours' => 2],
            ],
        ]);

        $res->assertStatus(201);
        $pkgId = $res->json('package_id');
        $members = StudentClass::where('PackageID', $pkgId)->get();
        foreach ($members as $m) {
            $count = ClassSession::where('StudentClassID', $m->ID)->count();
            $this->assertEquals(0, $count);
        }

        foreach ($res->json('members_scheduled') as $ms) {
            $this->assertFalse((bool) $ms['has_schedule']);
            $this->assertEquals(0, $ms['scheduled_count']);
        }
    }

    // ─── 混合：部分科有時段、部分無 ───

    public function test_create_partial_schedule(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->seedSubjects();

        $res = $this->postCreate($token, [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '部分排課',
            'total_sessions' => 6,
            'rate'           => 500,
            'subjects'       => [
                [
                    'subject_id'   => 1,
                    'teacher_id'   => $t1->id,
                    'days_of_week' => [2],
                    'start_time'   => '15:00',
                ],
                [
                    'subject_id' => 2,
                    'teacher_id' => $t2->id,
                ],
            ],
        ]);

        $res->assertStatus(201);
        $json = $res->json();
        $members = StudentClass::where('PackageID', $json['package_id'])->orderBy('ID')->get();
        $this->assertCount(2, $members);

        $withSchedule = $members->firstWhere('SubjectID', 1);
        $withoutSchedule = $members->firstWhere('SubjectID', 2);

        $this->assertEquals(6, ClassSession::where('StudentClassID', $withSchedule->ID)
            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])->count());
        $this->assertEquals(0, ClassSession::where('StudentClassID', $withoutSchedule->ID)->count());
    }

    // ─── confirmed_dates + 時段：補排數 = total - confirmed ───

    public function test_create_with_confirmed_dates_plus_schedule(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $this->seedSubjects();

        $confirmed = ['2026-05-04', '2026-05-05']; // 2 已上堂
        $res = $this->postCreate($token, [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '含已上堂',
            'total_sessions' => 6,
            'rate'           => 500,
            'subjects'       => [
                [
                    'subject_id'     => 1,
                    'teacher_id'     => $t1->id,
                    'confirmed_dates'=> $confirmed,
                    'days_of_week'   => [1],
                    'start_time'     => '16:00',
                ],
            ],
        ]);

        $res->assertStatus(201);
        $pkgId = $res->json('package_id');
        $member = StudentClass::where('PackageID', $pkgId)->first();

        $total = ClassSession::where('StudentClassID', $member->ID)
            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])->count();
        $this->assertEquals(6, $total);

        $attended = ClassSession::where('StudentClassID', $member->ID)
            ->where('Status', 'attended')->count();
        $this->assertEquals(2, $attended);
    }

    // ─── GAP-01 迴歸：days_of_week 值原樣寫入 week 欄位（不做 mod 轉換） ───

    public function test_create_schedule_writes_week_fields(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $tMon = $this->makeTeacher();
        $tSun = $this->makeTeacher();
        $this->seedSubjects();

        $res = $this->postCreate($token, [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => 'GAP-01 驗證',
            'total_sessions' => 3,
            'rate'           => 500,
            'subjects'       => [
                [
                    'subject_id'   => 1,
                    'teacher_id'   => $tMon->id,
                    'days_of_week' => [1],
                    'start_time'   => '16:00',
                ],
                [
                    'subject_id'   => 2,
                    'teacher_id'   => $tSun->id,
                    'days_of_week' => [7],
                    'start_time'   => '10:00',
                ],
            ],
        ]);
        $res->assertStatus(201);

        $pkgId = $res->json('package_id');
        $mon = StudentClass::where('PackageID', $pkgId)->where('SubjectID', 1)->first();
        $sun = StudentClass::where('PackageID', $pkgId)->where('SubjectID', 2)->first();

        // 關鍵：原本 bug 會把 1 寫成 0、7 寫成 6。
        $this->assertEquals(1, (int) $mon->week, 'Monday (1) must stay 1, not the buggy 0');
        $this->assertEquals(7, (int) $sun->week, 'Sunday (7) must stay 7, not the buggy 6');
        $this->assertStringStartsWith('16:00', (string) $mon->time);
        $this->assertStringStartsWith('10:00', (string) $sun->time);
    }

    // ─── 多日：寫入 week1 / week2 ───

    public function test_create_multi_days_writes_week1_week2(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t = $this->makeTeacher();
        $this->seedSubjects();

        $res = $this->postCreate($token, [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '週一三五',
            'total_sessions' => 6,
            'rate'           => 500,
            'subjects'       => [
                [
                    'subject_id'   => 1,
                    'teacher_id'   => $t->id,
                    'days_of_week' => [1, 3],
                    'start_time'   => '17:00',
                ],
            ],
        ]);
        $res->assertStatus(201);

        $pkgId = $res->json('package_id');
        $member = StudentClass::where('PackageID', $pkgId)->first();
        $this->assertEquals(1, (int) $member->week1);
        $this->assertEquals(3, (int) $member->week2);
        $this->assertStringStartsWith('17:00', (string) $member->time1);
        $this->assertStringStartsWith('17:00', (string) $member->time2);
    }

    // ─── FR-008：月結方案不補排 ───

    public function test_monthly_package_ignores_schedule(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->makeStudent(1);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();
        $this->seedSubjects();

        $res = $this->postCreate($token, [
            'student_id'     => $student->id,
            'branch_id'      => 1,
            'name'           => '月結方案',
            'payment_type'   => 'monthly',
            'settlement_day' => 1,
            'rate'           => 500,
            'subjects'       => [
                [
                    'subject_id'   => 1,
                    'teacher_id'   => $t1->id,
                    'days_of_week' => [1],
                    'start_time'   => '16:00',
                ],
                [
                    'subject_id'   => 2,
                    'teacher_id'   => $t2->id,
                    'days_of_week' => [3],
                    'start_time'   => '14:00',
                ],
            ],
        ]);
        $res->assertStatus(201);

        $pkgId = $res->json('package_id');
        $pkg = CoursePackage::find($pkgId);
        $this->assertEquals('date', $pkg->billing_mode);
        $members = StudentClass::where('PackageID', $pkgId)->get();
        foreach ($members as $m) {
            $count = ClassSession::where('StudentClassID', $m->ID)->count();
            $this->assertEquals(0, $count, '月結方案不應補排任何 ClassSession');
        }
    }
}
