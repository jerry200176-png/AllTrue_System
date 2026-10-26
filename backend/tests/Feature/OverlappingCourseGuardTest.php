<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #805 防再犯：建課時偵測「同學生 + 同科目 + 同老師 + 日期區間重疊」的進行中課程。
 *
 * 對應 in-app #161 / 復發家族 F1 的「重疊續報」變體：續報新期起始日早於舊期結束，
 * 兩筆都 active，重疊區間每週各生成一堂 → 點名重複。建課端先擋（force 可覆蓋）。
 */
class OverlappingCourseGuardTest extends TestCase
{
    use RefreshDatabase;

    private function batchPayload(int $studentId, int $teacherId, array $dates, string $classType = 'one_on_one'): array
    {
        return [
            'branch_id' => 1,
            'student_id' => $studentId,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => $classType,
            'total_classes' => count($dates),
            'confirmed_dates' => [],
            'future_dates' => [],
            'session_plan' => array_map(fn ($d) => [
                'session_date' => $d,
                'start_time' => '16:00',
                'kind' => 'future',
                'subject' => 'Math',
            ], $dates),
            'days_of_week' => [3],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
            'course_start_date' => $dates[0],
        ];
    }

    public function test_overlapping_same_teacher_subject_course_is_blocked_and_force_overrides(): void
    {
        $token = $this->createUserToken('A', [1], 'dir-ovl@example.com');
        $teacherId = $this->createTeacher(1, 'teach-ovl@example.com');
        $student = $this->createStudent(1, '重疊測試生');

        // 第一期：未來 8~11 週的週三（one_on_one）
        $base = Carbon::now()->addWeeks(8)->next(Carbon::WEDNESDAY);
        $first = [];
        $cur = $base->copy();
        for ($i = 0; $i < 4; $i++) { $first[] = $cur->toDateString(); $cur->addWeek(); }

        $res1 = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', $this->batchPayload($student->id, $teacherId, $first, 'one_on_one'));
        $res1->assertCreated();

        // 第二期：與第一期重疊（10~13 週），同生同科同師，但「不同 class_type」→
        // 既有的 duplicate_active_course（同型）不會觸發，必須由 #805 的重疊偵測擋下。
        $second = [];
        $cur = $base->copy()->addWeeks(2);
        for ($i = 0; $i < 4; $i++) { $second[] = $cur->toDateString(); $cur->addWeek(); }

        $res2 = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', $this->batchPayload($student->id, $teacherId, $second, 'one_on_two'));
        $res2->assertStatus(409)->assertJson(['code' => 'overlapping_active_course']);
        $this->assertStringContainsString('「續課」', (string) $res2->json('message')); // in-app #429
        $this->assertStringNotContainsString('加購堂數', (string) $res2->json('message'));

        $audit = null;
        \Illuminate\Support\Facades\Log::listen(function ($e) use (&$audit) {
            $e->message === 'enrollment_force_override' && $audit = $e->context;
        });
        // 勾選強制建立 → 須帶審計原因（獨立平行課）
        $forced = $this->batchPayload($student->id, $teacherId, $second, 'one_on_two');
        $forced['force'] = true;
        $forced['force_reason'] = 'independent_parallel';
        $forced['force_note'] = '測試：同科不同班型平行';
        $forced['existing_contract_ids'] = [(int) $res1->json('student_class_id')];
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', $forced)
            ->assertCreated();
        // #3738 review: the audit log must carry the authenticated actor (auth_user attribute, not auth_user_id).
        $this->assertGreaterThan(0, (int) ($audit['actor_user_id'] ?? 0));

        // force 但未帶 reason → 422
        $forcedNoReason = $this->batchPayload($student->id, $teacherId, $second, 'one_on_three');
        $forcedNoReason['force'] = true;
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', $forcedNoReason)
            ->assertStatus(422)
            ->assertJson(['code' => 'force_reason_required']);
    }

    /** @return array{0:array,1:int,2:int,3:int} [headers, studentId, teacherId, firstStudentClassId] */
    private function renewalFixture(string $email, string $classType = 'one_on_one', array $dates = []): array
    {
        $token = $this->createUserToken('A', [1], 'dir-'.$email);
        $teacherId = $this->createTeacher(1, 'teach-'.$email);
        $student = $this->createStudent(1, '續報守衛測試生');
        $h = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $dates = $dates ?: [Carbon::now()->addWeeks(8)->next(Carbon::WEDNESDAY)->toDateString()];
        $res = $this->withHeaders($h)->postJson('/api/v1/class-sessions/batch', $this->batchPayload($student->id, $teacherId, $dates, $classType));
        $res->assertCreated();

        return [$h, $student->id, $teacherId, (int) $res->json('student_class_id')];
    }

    private function renew(array $fx, array $dates, array $over = [], string $classType = 'one_on_one'): \Illuminate\Testing\TestResponse
    {
        [$h, $studentId, $teacherId, $first] = $fx;
        $payload = array_merge($this->batchPayload($studentId, $teacherId, $dates, $classType),
            ['force' => true, 'force_reason' => 'renewal_next_term', 'existing_contract_ids' => [$first]], $over);

        return $this->withHeaders($h)->postJson('/api/v1/class-sessions/batch', $payload);
    }

    private function completed(int $course, string $date): void
    {
        DB::table('ClassSession')->insert(['StudentClassID' => $course, 'SessionDate' => $date, 'StartTime' => '16:00:00',
            'EndTime' => '18:00:00', 'Status' => 'completed', 'Note' => '']);
    }

    private function later(int $weeks = 20): string
    {
        return Carbon::now()->addWeeks($weeks)->next(Carbon::WEDNESDAY)->toDateString();
    }

    private function counts(): array
    {
        return [DB::table('ClassSession')->count(), DB::table('LearningRecord')->count(), DB::table('StudentClass')->count()];
    }

    /** #3738 review: renewal_next_term must not bypass the same-slot student overlap guard (future slots). */
    public function test_renewal_next_term_force_does_not_create_same_slot_duplicate_sessions(): void
    {
        $dates = [Carbon::now()->addWeeks(8)->next(Carbon::WEDNESDAY)->toDateString()];
        $fx = $this->renewalFixture('renew-future@example.com', 'one_on_one', $dates);
        $before = $this->counts();

        $res = $this->renew($fx, $dates, [], 'one_on_two');

        $this->assertGreaterThanOrEqual(400, $res->status());
        $this->assertSame($before, $this->counts());
    }

    /** #3738 review: a past/completed same-slot row bypassed the write guard and added a second completed lesson. */
    public function test_renewal_next_term_force_refuses_confirmed_same_slot_without_second_session(): void
    {
        $fx = $this->renewalFixture('renew-past@example.com');
        $past = Carbon::now()->subWeeks(2)->toDateString();
        $this->completed($fx[3], $past);
        $before = $this->counts();
        $plan = [['session_date' => $past, 'start_time' => '16:00', 'kind' => 'confirmed', 'subject' => 'Math']];

        $this->assertSame(422, $this->renew($fx, [$past], ['session_plan' => $plan])->status());
        $this->assertSame($before, $this->counts());
    }

    /** #3738 review: a same-day future row whose slot already ended becomes completed, so the guard must apply. */
    public function test_renewal_guard_applies_to_future_row_that_is_already_ended_today(): void
    {
        $fx = $this->renewalFixture('renew-today@example.com');
        Carbon::setTestNow(Carbon::now()->setTime(20, 0));
        try {
            $today = Carbon::now()->toDateString();
            $this->completed($fx[3], $today);
            $before = $this->counts();

            $res = $this->renew($fx, [$today], ['days_of_week' => [Carbon::now()->dayOfWeekIso]]); // kind: future

            $this->assertSame('renewal_confirmed_slot_overlap', $res->json('code'), $res->getContent());
            $this->assertSame($before, $this->counts());
        } finally {
            Carbon::setTestNow();
        }
    }


    public function test_renewal_guard_uses_the_materialization_timestamp_when_the_clock_crosses_slot_end(): void
    {
        $fx = $this->renewalFixture('renew-clock@example.com'); $decisionNow = Carbon::now()->setTime(17, 59, 59);
        Carbon::setTestNow($decisionNow->copy()->addSeconds(2));
        try {
            $today = $decisionNow->toDateString(); $this->completed($fx[3], $today);
            $before = $this->counts();
            $guard = new \ReflectionMethod(\App\Services\EnrollmentService::class, 'guardRenewalConfirmedOverlap');
            $guard->setAccessible(true); $result = $guard->invoke(app(\App\Services\EnrollmentService::class), $fx[1], ['Math' => [
                ['date' => $today, 'start_time' => '16:00:00', 'duration_minutes' => 120, 'kind' => 'future'],
            ]], $decisionNow);
            $this->assertNull($result, 'Snapshot-future rows must reach the scheduled overlap guard, not become completed.');
            $this->assertSame($before, $this->counts());
        } finally {
            Carbon::setTestNow();
        }
    }

    /** #3738 review: a booking that exists only in `schedules` (not yet a ClassSession) is the same clash. */
    public function test_renewal_confirmed_row_is_refused_on_a_schedule_only_booking(): void
    {
        $fx = $this->renewalFixture('renew-sched@example.com');
        $past = Carbon::now()->subWeeks(2)->toDateString();
        \App\Models\Schedule::create(['student_id' => $fx[1], 'teacher_id' => $fx[2], 'subject' => 'Math', 'day_of_week' => Carbon::parse($past)->dayOfWeekIso,
            'start_time' => '16:00', 'end_time' => '18:00', 'duration_hours' => 2, 'class_type' => 'one_on_one', 'status' => 'scheduled',
            'type' => 'normal', 'deduction' => 1, 'branch_id' => 1, 'schedule_date' => $past]);
        $before = $this->counts();
        $plan = [['session_date' => $past, 'start_time' => '16:00', 'kind' => 'confirmed', 'subject' => 'Math']];

        $this->assertSame('renewal_confirmed_slot_overlap', $this->renew($fx, [$past], ['session_plan' => $plan])->json('code'));
        $this->assertSame($before, $this->counts());
        \App\Models\Schedule::query()->update(['student_course_id' => $fx[3]]);
        DB::table('StudentClass')->where('ID', $fx[3])->update(['Stop' => 1]); // a stopped contract's schedule-only row is not live occupancy
        $this->assertNotSame('renewal_confirmed_slot_overlap', $this->renew($fx, [$past], ['session_plan' => $plan])->json('code'));
    }

    /** #3738 review: an existing TRIAL session is add-on, not a clash (canonical guard excludes trial). */
    public function test_confirmed_overlap_guard_ignores_trial_sessions(): void
    {
        $fx = $this->renewalFixture('renew-trial@example.com');
        DB::table('StudentClass')->where('ID', $fx[3])->update(['ClassType' => 'trial']);
        $past = Carbon::now()->subWeeks(2)->toDateString();
        $this->completed($fx[3], $past);
        $plan = [['session_date' => $past, 'start_time' => '16:00', 'kind' => 'confirmed', 'subject' => 'Math']];

        $this->assertNotSame('renewal_confirmed_slot_overlap', $this->renew($fx, [$past], ['session_plan' => $plan])->json('code'));
    }

    /** #3738 review: monthly (date-mode) renewal belongs to ContractRenewal::renewMonthly, not generic enrollment. */
    public function test_renewal_next_term_force_is_refused_for_monthly_contract(): void
    {
        $fx = $this->renewalFixture('renew-monthly@example.com');
        DB::table('StudentClass')->where('ID', $fx[3])->update(['ScheduleMode' => 'date']);
        $before = $this->counts();

        $this->renew($fx, [$this->later()], ['existing_contract_ids' => []])->assertStatus(422)->assertJson(['code' => 'renewal_use_monthly_flow']);
        $this->assertSame($before, $this->counts());
    }

    /** #3738 review: blank/NULL ScheduleMode (NOT NULL column here, so blank is tested) is a count contract; an unrelated monthly one does not block. */
    public function test_blank_mode_and_unrelated_monthly_contract_do_not_block_count_renewal(): void
    {
        $fx = $this->renewalFixture('renew-scope@example.com');
        DB::table('StudentClass')->where('ID', $fx[3])->update(['ScheduleMode' => '']);
        $this->assertNotSame('renewal_use_monthly_flow', $this->renew($fx, [$this->later()])->json('code'));

        DB::table('StudentClass')->where('ID', $fx[3])->update(['ScheduleMode' => 'count']);
        $row = (array) DB::table('StudentClass')->where('ID', $fx[3])->first();
        unset($row['ID']);
        DB::table('StudentClass')->insert(array_merge($row, ['ClassType' => 'one_on_two', 'ScheduleMode' => 'date']));
        $this->assertNotSame('renewal_use_monthly_flow', $this->renew($fx, [$this->later(30)])->json('code'));
    }

    /** #3738 review: a forged id of an unrelated count contract must not hide the real monthly conflict. */
    public function test_forged_count_contract_id_cannot_bypass_monthly_conflict(): void
    {
        $fx = $this->renewalFixture('renew-forged2@example.com');
        DB::table('StudentClass')->where('ID', $fx[3])->update(['ScheduleMode' => 'date']);
        $row = (array) DB::table('StudentClass')->where('ID', $fx[3])->first();
        unset($row['ID']);
        $decoy = DB::table('StudentClass')->insertGetId(array_merge($row, ['ClassType' => 'one_on_three', 'ScheduleMode' => 'count', 'TeacherID' => $fx[2] + 999]));

        $this->renew($fx, [$this->later()], ['existing_contract_ids' => [$decoy]])->assertStatus(422)->assertJson(['code' => 'renewal_use_monthly_flow']);
    }

    /** #3738 review: another weekday, and a manual_occurrence renewal without dates, must still start after the old contract ends. */
    public function test_renewal_next_term_force_is_refused_when_contract_ranges_overlap(): void
    {
        $fx = $this->renewalFixture('renew-range@example.com');
        $wed = (string) DB::table('StudentClass')->where('ID', $fx[3])->value('StartDate');
        DB::table('StudentClass')->where('ID', $fx[3])->update(['EndDate' => Carbon::parse($wed)->addMonths(3)->toDateString()]);
        $before = $this->counts();
        $thu = Carbon::parse($wed)->addDay()->toDateString();

        $rangeOverlap = $this->renew($fx, [$thu], ['days_of_week' => [4]])->assertStatus(422)->assertJson(['code' => 'renewal_range_overlap']);
        $this->assertStringContainsString('「續課」', (string) $rangeOverlap->json('message')); // in-app #429
        DB::table('StudentClass')->where('ID', $fx[3])->update(['TeacherID' => $fx[2] + 999]); // same class type, other teacher
        $this->renew($fx, [$thu], ['days_of_week' => [4]])->assertStatus(422)->assertJson(['code' => 'renewal_range_overlap']);
        DB::table('StudentClass')->where('ID', $fx[3])->update(['TeacherID' => $fx[2]]);
        $manual = $this->renew($fx, [$wed], ['scheduling_policy' => 'manual_occurrence', 'session_plan' => [],
            'course_start_date' => $thu, 'end_date' => Carbon::parse($wed)->addMonth()->toDateString()]);
        $this->assertSame('renewal_range_overlap', $manual->json('code'), $manual->getContent());
        DB::table('StudentClass')->where('ID', $fx[3])->update(['EndDate' => null]);
        $open = $this->renew($fx, [$wed], ['scheduling_policy' => 'manual_occurrence', 'session_plan' => [], 'course_start_date' => $this->later(30)]);
        $this->assertSame('renewal_range_overlap', $open->json('code'), $open->getContent());
        $this->assertSame($before, $this->counts());
    }

    /** #3738 review: cross-campus identity enrollment must check the target-campus member's contracts. */
    public function test_monthly_guard_uses_resolved_target_campus_member(): void
    {
        [, $sourceId, $teacherId, $sourceCourse] = $this->renewalFixture('renew-xcampus@example.com');
        $group = DB::table('student_identity_groups')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
        DB::table('Campus')->updateOrInsert(['id' => 2], ['id' => 2, 'Name' => 'Target']);
        $target = $this->createStudent(2, '目標分校成員');
        foreach ([[$sourceId, 1], [$target->id, 2]] as [$sid, $cid]) {
            DB::table('student_identity_members')->insert(['identity_group_id' => $group, 'student_id' => $sid,
                'campus_id' => $cid, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        $row = (array) DB::table('StudentClass')->where('ID', $sourceCourse)->first();
        unset($row['ID']);
        DB::table('StudentClass')->insert(array_merge($row, ['StudentID' => $target->id, 'ScheduleMode' => 'date']));
        DB::table('UserCampus')->insert(['UserID' => $teacherId, 'CampusID' => 2]);
        $later = $this->later();
        $payload = array_merge($this->batchPayload($sourceId, $teacherId, [$later]), ['future_dates' => [$later],
            'identity_group_id' => $group, 'branch_id' => 2, 'force' => true, 'force_reason' => 'renewal_next_term']);
        // Not reachable over HTTP today; call the service.
        $request = \Illuminate\Http\Request::create('/x', 'POST');
        $request->attributes->set('auth_role', 'super_admin');
        $request->attributes->set('auth_user', \App\Models\User::find($teacherId));
        $res = \Illuminate\Testing\TestResponse::fromBaseResponse(app(\App\Services\EnrollmentService::class)->store($request, $payload));

        $this->assertSame('renewal_use_monthly_flow', $res->json('code'), $res->getContent());
        // No target-campus member yet: the source campus's monthly contract must not block it (Codex r4259069343).
        DB::table('student_identity_members')->where('student_id', $target->id)->delete();
        DB::table('StudentClass')->where('ID', $sourceCourse)->update(['ScheduleMode' => 'date']);
        $this->assertNotSame('renewal_use_monthly_flow', \Illuminate\Testing\TestResponse::fromBaseResponse(app(\App\Services\EnrollmentService::class)->store($request, $payload))->json('code'));
    }

    /**
     * 試聽是旁聽正式課堂的加掛，不可被 overlapping_active_course 擋死。
     * 同型試聽重複則仍走 duplicate_active_course。
     */
    public function test_trial_course_is_not_blocked_by_overlapping_active_course(): void
    {
        $token = $this->createUserToken('A', [1], 'dir-ovl-trial@example.com');
        $teacherId = $this->createTeacher(1, 'teach-ovl-trial@example.com');
        $student = $this->createStudent(1, '試聽重疊測試生');

        $base = Carbon::now()->addWeeks(8)->next(Carbon::WEDNESDAY);
        $first = [];
        $cur = $base->copy();
        for ($i = 0; $i < 4; $i++) {
            $first[] = $cur->toDateString();
            $cur->addWeek();
        }

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', $this->batchPayload($student->id, $teacherId, $first, 'one_on_three'))
            ->assertCreated();

        $trialDate = $first[1];
        $trialPayload = $this->batchPayload($student->id, $teacherId, [$trialDate], 'trial');

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', $trialPayload)
            ->assertCreated();

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', $trialPayload)
            ->assertStatus(409)
            ->assertJson(['code' => 'duplicate_active_course']);
    }

    public function test_non_overlapping_renewal_after_first_ends_is_allowed(): void
    {
        $token = $this->createUserToken('A', [1], 'dir-ovl2@example.com');
        $teacherId = $this->createTeacher(1, 'teach-ovl2@example.com');
        $student = $this->createStudent(1, '接續測試生');

        $base = Carbon::now()->addWeeks(8)->next(Carbon::WEDNESDAY);
        $first = [];
        $cur = $base->copy();
        for ($i = 0; $i < 4; $i++) { $first[] = $cur->toDateString(); $cur->addWeek(); }

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', $this->batchPayload($student->id, $teacherId, $first, 'one_on_one'))
            ->assertCreated();

        // 第二期接在第一期之後（無重疊），不同 class_type → 兩道 guard 都不應觸發
        $second = [];
        $cur = $base->copy()->addWeeks(5);
        for ($i = 0; $i < 4; $i++) { $second[] = $cur->toDateString(); $cur->addWeek(); }

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', $this->batchPayload($student->id, $teacherId, $second, 'one_on_two'))
            ->assertCreated();
    }

    // ── helpers (mirror CourseStartDateTest) ──────────────────────────
    private function createUserToken(string $type, array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => $type === 'T' ? '老師測試' : '主任測試',
            'PSW' => 'secret',
            'type' => $type,
            'phone' => '0911000000',
            'MustChangePassword' => false,
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::firstOrCreate(['CampusID' => $campusId, 'UserID' => $user->id], ['Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function createTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName, 'Name' => '老師測試', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922111111', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        return (int) $teacher->id;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name, 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
    }
}

