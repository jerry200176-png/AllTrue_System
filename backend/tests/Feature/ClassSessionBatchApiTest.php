<?php

namespace Tests\Feature;

use App\Http\Controllers\ClassSessionController;
use App\Models\AuthToken;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClassSessionBatchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Pin to 10:00 AM. Tests that need a specific time override with their own
        // Carbon::setTestNow() call. This default prevents midnight-crossing EndTime
        // values when tests use start_time='23:00' with short durations.
        Carbon::setTestNow(Carbon::today()->setTime(10, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_director_can_batch_create_sessions_and_only_past_dates_create_learning_records(): void
    {
        $token = $this->createUserToken('A', [1], 'director-batch-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-batch-a@example.com');
        $student = $this->createStudent(1, '批次排課測試A');

        $todayYmd = now()->toDateString();
        $pastMon = now()->copy();
        for ($i = 0; $i < 21; $i++) {
            if ((int) $pastMon->dayOfWeekIso === 1 && $pastMon->toDateString() < $todayYmd) {
                break;
            }
            $pastMon->subDay();
        }
        $pastDate = $pastMon->toDateString();
        $futureThu = now()->copy()->addDay();
        for ($i = 0; $i < 45; $i++) {
            if (
                (int) $futureThu->dayOfWeekIso === 4
                && $futureThu->toDateString() >= $todayYmd
                && $futureThu->toDateString() > $pastDate
            ) {
                break;
            }
            $futureThu->addDay();
        }
        $futureDate = $futureThu->toDateString();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 2,
            'confirmed_dates' => [$pastDate],
            'future_dates' => [$futureDate],
            'days_of_week' => [1, 4],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ]);

        $res->assertCreated()
            ->assertJsonPath('created_sessions', 2)
            ->assertJsonPath('created_confirmed_sessions', 1)
            ->assertJsonPath('created_future_sessions', 1)
            ->assertJsonPath('created_learning_records', 1)
            ->assertJsonPath('approved_learning_records', 1)
            ->assertJsonPath('deducted_sessions', 1);

        $studentClassId = (int) ($res->json('student_class_id') ?? 0);
        $this->assertTrue($studentClassId > 0);

        $this->assertDatabaseHas('StudentClass', [
            'ID' => $studentClassId,
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'SessionCount' => 2,
            'RemainingSessions' => 1,
            'UsedSessions' => 1,
        ]);

        $sessions = DB::table('ClassSession')
            ->where('StudentClassID', $studentClassId)
            ->orderBy('SessionDate')
            ->get();
        $this->assertCount(2, $sessions);
        $this->assertSame(
            [substr($pastDate, 0, 10), substr($futureDate, 0, 10)],
            $sessions->pluck('SessionDate')->map(fn ($d) => substr((string) $d, 0, 10))->all()
        );
        $this->assertSame('completed', (string) ($sessions[0]->Status ?? ''));
        $this->assertSame('scheduled', (string) ($sessions[1]->Status ?? ''));

        $learningRecords = LearningRecord::where('StudentClassID', $studentClassId)->get();
        $this->assertCount(1, $learningRecords);
        $this->assertSame($pastDate, substr((string) $learningRecords->first()->SessionDate, 0, 10));
        $this->assertSame('approved', (string) $learningRecords->first()->Status);
        $this->assertSame(1, (int) ($learningRecords->first()->SessionDeducted ?? 0));
    }

    public function test_batch_endpoint_recalculates_remaining_sessions_using_completed_status(): void
    {
        $token = $this->createUserToken('A', [1], 'director-batch-remain-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-batch-remain-a@example.com');
        $student = $this->createStudent(1, '批次排課扣堂測試A');

        $todayY = now()->toDateString();
        $confirmedDates = [];
        $c = now()->copy();
        for ($i = 0; $i < 120 && count($confirmedDates) < 3; $i++) {
            if ((int) $c->dayOfWeekIso === 4 && $c->toDateString() < $todayY) {
                $confirmedDates[] = $c->toDateString();
            }
            $c->subDay();
        }
        sort($confirmedDates);
        $lastConf = $confirmedDates[count($confirmedDates) - 1];
        $futureDates = [];
        $f = Carbon::parse($lastConf)->addDay();
        for ($i = 0; $i < 120 && count($futureDates) < 5; $i++) {
            if (
                (int) $f->dayOfWeekIso === 4
                && $f->toDateString() > $lastConf
                && $f->toDateString() >= $todayY
            ) {
                $futureDates[] = $f->toDateString();
            }
            $f->addDay();
        }

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 8,
            'confirmed_dates' => $confirmedDates,
            'future_dates' => $futureDates,
            'days_of_week' => [4],
            'start_time' => '23:00',
            'duration_minutes' => 30,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ]);

        $res->assertCreated()
            ->assertJsonPath('created_confirmed_sessions', 3)
            ->assertJsonPath('created_future_sessions', 5);

        $studentClassId = (int) ($res->json('student_class_id') ?? 0);
        $this->assertTrue($studentClassId > 0);

        $this->assertDatabaseHas('StudentClass', [
            'ID' => $studentClassId,
            'SessionCount' => 8,
            'UsedSessions' => 3,
            'RemainingSessions' => 5,
        ]);

        $completedCount = DB::table('ClassSession')
            ->where('StudentClassID', $studentClassId)
            ->where('Status', 'completed')
            ->count();
        $this->assertSame(3, $completedCount);
    }

    public function test_recalculate_session_counters_counts_legacy_attended_for_compatibility(): void
    {
        $token = $this->createUserToken('A', [1], 'director-batch-compat-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-batch-compat-a@example.com');
        $student = $this->createStudent(1, '批次排課扣堂測試B');

        $today = Carbon::today();
        $pastTue = $today->copy();
        for ($i = 0; $i < 14; $i++) {
            if ((int) $pastTue->dayOfWeekIso === 2 && $pastTue->toDateString() < $today->toDateString()) {
                break;
            }
            $pastTue->subDay();
        }
        $confYmd = $pastTue->toDateString();
        $fu1 = $pastTue->copy()->addDay();
        for ($i = 0; $i < 28; $i++) {
            if (
                (int) $fu1->dayOfWeekIso === 2
                && $fu1->toDateString() > $confYmd
                && $fu1->toDateString() >= $today->toDateString()
            ) {
                break;
            }
            $fu1->addDay();
        }
        $fu2 = $pastTue->copy()->addDay();
        for ($i = 0; $i < 28; $i++) {
            if (
                (int) $fu2->dayOfWeekIso === 4
                && $fu2->toDateString() > $confYmd
                && $fu2->toDateString() >= $today->toDateString()
            ) {
                break;
            }
            $fu2->addDay();
        }
        $futurePair = [$fu1->toDateString(), $fu2->toDateString()];
        sort($futurePair);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 3,
            'confirmed_dates' => [$confYmd],
            'future_dates' => $futurePair,
            'days_of_week' => [2, 4],
            'start_time' => '23:00',
            'duration_minutes' => 30,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ]);

        $res->assertCreated();
        $studentClassId = (int) ($res->json('student_class_id') ?? 0);
        $this->assertTrue($studentClassId > 0);

        $legacySession = DB::table('ClassSession')
            ->where('StudentClassID', $studentClassId)
            ->where('Status', 'scheduled')
            ->orderBy('SessionDate')
            ->first();
        $this->assertNotNull($legacySession);

        DB::table('ClassSession')
            ->where('id', (int) $legacySession->id)
            ->update(['Status' => 'attended']);

        $studentClass = \App\Models\StudentClass::findOrFail($studentClassId);
        $controller = app(ClassSessionController::class);
        $method = new \ReflectionMethod($controller, 'recalculateSessionCounters');
        $method->setAccessible(true);
        $method->invoke($controller, $studentClass);

        $this->assertDatabaseHas('StudentClass', [
            'ID' => $studentClassId,
            'SessionCount' => 3,
            'UsedSessions' => 2,
            'RemainingSessions' => 1,
        ]);
    }

    public function test_batch_endpoint_rejects_mismatched_total_classes_and_dates_length(): void
    {
        $token = $this->createUserToken('A', [1], 'director-batch-b@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-batch-b@example.com');
        $student = $this->createStudent(1, '批次排課測試B');

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 3,
            'confirmed_dates' => [now()->subDay()->toDateString()],
            'future_dates' => [now()->addDay()->toDateString()],
            'days_of_week' => [2, 4],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ])->assertStatus(422);
    }

    public function test_batch_endpoint_rejects_overlap_between_confirmed_and_future_dates(): void
    {
        Carbon::setTestNow('2026-04-10 18:00:00');
        $token = $this->createUserToken('A', [1], 'director-batch-overlap-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-batch-overlap-a@example.com');
        $student = $this->createStudent(1, '批次排課重疊測試A');
        $date = '2026-04-08';

        try {
            $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->postJson('/api/v1/class-sessions/batch', [
                'branch_id' => 1,
                'student_id' => $student->id,
                'teacher_id' => $teacherId,
                'subject' => 'Math',
                'class_type' => 'one_on_one',
                'total_classes' => 2,
                'confirmed_dates' => [$date],
                'future_dates' => [$date],
                'days_of_week' => [3],
                'start_time' => '16:00',
                'duration_minutes' => 120,
                'price_per_session' => 500,
                'payment_type' => 'session',
            ])->assertStatus(422);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_director_cannot_create_sessions_for_unassigned_branch(): void
    {
        $token = $this->createUserToken('A', [1], 'director-batch-c@example.com');
        $teacherId = $this->createTeacher(2, 'teacher-batch-c@example.com');
        $student = $this->createStudent(2, '批次排課測試C');

        $todayY = now()->toDateString();
        $pastMon = now()->copy();
        for ($i = 0; $i < 14; $i++) {
            if ((int) $pastMon->dayOfWeekIso === 1 && $pastMon->toDateString() < $todayY) {
                break;
            }
            $pastMon->subDay();
        }

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 2,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 1,
            'confirmed_dates' => [$pastMon->toDateString()],
            'future_dates' => [],
            'days_of_week' => [1],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ])->assertStatus(403);
    }

    public function test_teacher_cannot_create_sessions_for_other_teacher(): void
    {
        $teacherUserId = $this->createTeacher(1, 'teacher-batch-d@example.com');
        $token = $this->createUserToken('T', [1], 'teacher-batch-d@example.com', $teacherUserId);
        $anotherTeacherId = $this->createTeacher(1, 'teacher-batch-e@example.com');
        $student = $this->createStudent(1, '批次排課測試D');

        $todayY = now()->toDateString();
        $pastWed = now()->copy();
        for ($i = 0; $i < 14; $i++) {
            if ((int) $pastWed->dayOfWeekIso === 3 && $pastWed->toDateString() < $todayY) {
                break;
            }
            $pastWed->subDay();
        }

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $anotherTeacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 1,
            'confirmed_dates' => [$pastWed->toDateString()],
            'future_dates' => [],
            'days_of_week' => [3],
            'start_time' => '16:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ])->assertStatus(403);
    }

    public function test_batch_endpoint_supports_monthly_courses_without_total_classes(): void
    {
        $token = $this->createUserToken('A', [1], 'director-batch-monthly-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-batch-monthly-a@example.com');
        $student = $this->createStudent(1, '月結批次排課測試A');

        Carbon::setTestNow('2026-04-12 18:00:00');
        $confirmedDate = '2026-04-07';
        $futureDates = ['2026-04-14', '2026-04-21'];

        try {
            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->postJson('/api/v1/class-sessions/batch', [
                'branch_id' => 1,
                'student_id' => $student->id,
                'teacher_id' => $teacherId,
                'subject' => 'Math',
                'class_type' => 'one_on_one',
                'confirmed_dates' => [$confirmedDate],
                'future_dates' => $futureDates,
                'days_of_week' => [2],
                'start_time' => '16:00',
                'duration_minutes' => 120,
                'price_per_session' => 500,
                'payment_type' => 'monthly',
                'settlement_day' => 10,
                'monthly_sessions' => 3,
            ]);

            $res->assertCreated()
                ->assertJsonPath('created_sessions', 3)
                ->assertJsonPath('created_confirmed_sessions', 1)
                ->assertJsonPath('created_future_sessions', 2)
                ->assertJsonPath('deducted_sessions', 1);

            $studentClassId = (int) ($res->json('student_class_id') ?? 0);
            $this->assertTrue($studentClassId > 0);

            $this->assertDatabaseHas('StudentClass', [
                'ID' => $studentClassId,
                'ScheduleMode' => 'date',
                'SessionCount' => 0,
                'RemainingSessions' => 0,
                'UsedSessions' => 1,
                'settlement_day' => 10,
                'monthly_sessions' => 3,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @param  array<int>  $campusIds
     */
    public function test_batch_create_with_per_day_duration_stores_varied_end_times(): void
    {
        $token = $this->createUserToken('A', [1], 'director-batch-dur@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-batch-dur@example.com');
        $student = $this->createStudent(1, '每日時長測試');

        $mon = now()->addDays(1);
        while ((int) $mon->dayOfWeekIso !== 1) { $mon->addDay(); }
        $wed = $mon->copy()->addDays(2);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'English',
            'class_type' => 'one_on_one',
            'total_classes' => 2,
            'confirmed_dates' => [],
            'future_dates' => [$mon->toDateString(), $wed->toDateString()],
            'days_of_week' => [1, 3],
            'day_time_slots' => [
                ['day' => 1, 'start_time' => '15:00', 'duration_minutes' => 90],
                ['day' => 3, 'start_time' => '16:00', 'duration_minutes' => 120],
            ],
            'start_time' => '15:00',
            'duration_minutes' => 120,
            'rate_unit' => 'hour',
            'price_per_session' => 400,
            'payment_type' => 'session',
        ]);

        $res->assertCreated();
        $scId = (int) $res->json('student_class_id');
        $this->assertTrue($scId > 0);

        $sc = \App\Models\StudentClass::find($scId);
        $this->assertEquals('hour', $sc->rate_unit);
        $this->assertEquals(90, (int) $sc->duration1);
        $this->assertEquals(120, (int) $sc->duration2);

        $sessions = \App\Models\ClassSession::where('StudentClassID', $scId)
            ->orderBy('SessionDate')
            ->get();
        $this->assertCount(2, $sessions);

        $monSess = $sessions->first(fn ($s) => $s->SessionDate === $mon->toDateString());
        $wedSess = $sessions->first(fn ($s) => $s->SessionDate === $wed->toDateString());

        $this->assertNotNull($monSess);
        $this->assertEquals('15:00:00', $monSess->StartTime);
        $this->assertEquals('16:30:00', $monSess->EndTime);

        $this->assertNotNull($wedSess);
        $this->assertEquals('16:00:00', $wedSess->StartTime);
        $this->assertEquals('18:00:00', $wedSess->EndTime);
    }

    public function test_session_plan_creates_two_sessions_on_same_weekday_date(): void
    {
        $token = $this->createUserToken('A', [1], 'director-batch-2slot@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-batch-2slot@example.com');
        $student = $this->createStudent(1, '同日兩時段批次測試');

        $sat = now()->addDays(1);
        while ((int) $sat->dayOfWeekIso !== 6) {
            $sat->addDay();
        }
        $d1 = $sat->toDateString();
        $d2 = $sat->copy()->addWeek()->toDateString();

        $sessionPlan = [
            ['session_date' => $d1, 'start_time' => '15:00', 'kind' => 'future'],
            ['session_date' => $d1, 'start_time' => '17:00', 'kind' => 'future'],
            ['session_date' => $d2, 'start_time' => '15:00', 'kind' => 'future'],
            ['session_date' => $d2, 'start_time' => '17:00', 'kind' => 'future'],
        ];

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 4,
            'confirmed_dates' => [],
            'future_dates' => [],
            'session_plan' => $sessionPlan,
            'days_of_week' => [6],
            'day_time_slots' => [
                ['day' => 6, 'start_time' => '15:00', 'duration_minutes' => 120],
                ['day' => 6, 'start_time' => '17:00', 'duration_minutes' => 120],
            ],
            'start_time' => '15:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ]);

        $res->assertCreated();
        $scId = (int) ($res->json('student_class_id') ?? 0);
        $this->assertTrue($scId > 0);

        $sessions = \App\Models\ClassSession::where('StudentClassID', $scId)
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->get();
        $this->assertCount(4, $sessions);

        $sameDay = $sessions->filter(fn ($s) => substr((string) $s->SessionDate, 0, 10) === $d1);
        $this->assertCount(2, $sameDay);
        $starts = $sameDay->pluck('StartTime')->map(fn ($t) => substr((string) $t, 0, 5))->sort()->values()->all();
        $this->assertSame(['15:00', '17:00'], $starts);

        $this->assertDatabaseHas('StudentClass', [
            'ID' => $scId,
            'SessionCount' => 4,
            'RemainingSessions' => 4,
        ]);
    }

    public function test_batch_rejects_session_plan_on_weekday_outside_fixed_schedule(): void
    {
        $token = $this->createUserToken('A', [1], 'director-batch-wd@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-batch-wd@example.com');
        $student = $this->createStudent(1, '固定星期驗證');

        $wed = now()->addDays(1);
        while ((int) $wed->dayOfWeekIso !== 3) {
            $wed->addDay();
        }
        $wedYmd = $wed->toDateString();

        $sat = now()->addDays(1);
        while ((int) $sat->dayOfWeekIso !== 6) {
            $sat->addDay();
        }
        $satYmd = $sat->toDateString();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 2,
            'confirmed_dates' => [],
            'future_dates' => [],
            'session_plan' => [
                ['session_date' => $wedYmd, 'start_time' => '13:00', 'kind' => 'future'],
                ['session_date' => $satYmd, 'start_time' => '13:00', 'kind' => 'future'],
            ],
            'days_of_week' => [6, 7],
            'day_time_slots' => [
                ['day' => 6, 'start_time' => '13:00', 'duration_minutes' => 120],
                ['day' => 7, 'start_time' => '10:00', 'duration_minutes' => 120],
            ],
            'start_time' => '13:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('週三', (string) $res->json('message'));
    }

    public function test_multi_slot_deduction_counts_each_session_separately(): void
    {
        $token = $this->createUserToken('A', [1], 'director-deduct-ms@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-deduct-ms@example.com');
        $student = $this->createStudent(1, '多時段扣堂測試');

        $pastDate = now()->subDays(2)->toDateString();

        $sessionPlan = [
            ['session_date' => $pastDate, 'start_time' => '15:00', 'kind' => 'confirmed'],
            ['session_date' => $pastDate, 'start_time' => '17:00', 'kind' => 'confirmed'],
        ];

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 2,
            'confirmed_dates' => [],
            'future_dates' => [],
            'session_plan' => $sessionPlan,
            'days_of_week' => [now()->subDays(2)->dayOfWeekIso],
            'day_time_slots' => [
                ['day' => now()->subDays(2)->dayOfWeekIso, 'start_time' => '15:00', 'duration_minutes' => 120],
                ['day' => now()->subDays(2)->dayOfWeekIso, 'start_time' => '17:00', 'duration_minutes' => 120],
            ],
            'start_time' => '15:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ]);

        $res->assertCreated();
        $scId = (int) $res->json('student_class_id');

        $this->assertDatabaseHas('StudentClass', [
            'ID' => $scId,
            'SessionCount' => 2,
        ]);

        $sessions = \App\Models\ClassSession::where('StudentClassID', $scId)->get();
        $this->assertCount(2, $sessions, 'Two ClassSession rows should exist for same-day two slots');

        $sameDate = $sessions->filter(fn ($s) => substr((string) $s->SessionDate, 0, 10) === $pastDate);
        $this->assertCount(2, $sameDate, 'Both sessions should be on the same date');

        $this->assertEquals(2, $res->json('created_learning_records'), 'Both past slots should get LRs');
        $this->assertEquals(2, $res->json('deducted_sessions'), 'Both slots should deduct a session');
    }

    public function test_batch_per_slot_subjects_create_two_student_classes(): void
    {
        $token = $this->createUserToken('A', [1], 'director-multi-subj@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-multi-subj@example.com');
        $student = $this->createStudent(1, '同日雙科測試');

        $pastDate = now()->subDays(2)->toDateString();
        $dow = (int) \Carbon\Carbon::parse($pastDate)->dayOfWeekIso;

        $sessionPlan = [
            ['session_date' => $pastDate, 'start_time' => '15:00', 'kind' => 'confirmed', 'subject' => 'Math'],
            ['session_date' => $pastDate, 'start_time' => '17:00', 'kind' => 'confirmed', 'subject' => 'English'],
        ];

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/class-sessions/batch', [
            'branch_id' => 1,
            'student_id' => $student->id,
            'teacher_id' => $teacherId,
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'total_classes' => 2,
            'confirmed_dates' => [],
            'future_dates' => [],
            'session_plan' => $sessionPlan,
            'days_of_week' => [$dow],
            'day_time_slots' => [
                ['day' => $dow, 'start_time' => '15:00', 'duration_minutes' => 120, 'subject' => 'Math'],
                ['day' => $dow, 'start_time' => '17:00', 'duration_minutes' => 120, 'subject' => 'English'],
            ],
            'start_time' => '15:00',
            'duration_minutes' => 120,
            'price_per_session' => 500,
            'payment_type' => 'session',
        ]);

        $res->assertCreated();
        $ids = $res->json('student_class_ids');
        $this->assertIsArray($ids);
        $this->assertCount(2, $ids);

        $mathId = (int) DB::table('Subject')->where('Subject_Name', 'Math')->value('id');
        $englishId = (int) DB::table('Subject')->where('Subject_Name', 'English')->value('id');
        $this->assertGreaterThan(0, $mathId);
        $this->assertGreaterThan(0, $englishId);

        $subjects = DB::table('StudentClass')->whereIn('ID', $ids)->orderBy('ID')->pluck('SubjectID')->all();
        sort($subjects);
        $expected = [$mathId, $englishId];
        sort($expected);
        $this->assertSame($expected, $subjects);

        foreach ($ids as $scId) {
            $this->assertDatabaseHas('StudentClass', [
                'ID' => (int) $scId,
                'StudentID' => $student->id,
                'SessionCount' => 1,
            ]);
            $this->assertSame(1, (int) DB::table('ClassSession')->where('StudentClassID', (int) $scId)->count());
        }
    }

    public function test_reschedule_targets_correct_session_on_multi_slot_day(): void
    {
        $token = $this->createUserToken('A', [1], 'director-resch-ms@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-resch-ms@example.com');
        $student = $this->createStudent(1, '多時段調課測試');

        $futureDate = now()->addDays(5)->toDateString();
        $newDate = now()->addDays(10)->toDateString();

        $sc = \App\Models\StudentClass::create([
            'StudentID' => $student->id,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'GradeID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => $futureDate,
            'EndDate' => null,
            'TotalHours' => 40,
            'Memo' => '多時段調課測試',
            'Charge' => null,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 0,
            'Disconunt' => null,
            'Rate' => 500,
            'LearnTimeID' => null,
            'MDate' => now(),
            'SessionCount' => 4,
            'SessionDuration' => 120,
            'ScheduleMode' => 'count',
            'RemainingSessions' => 4,
            'UsedSessions' => 0,
            'Stop' => 0,
            'ClassType' => 'one_on_one',
        ]);

        $cs1 = \App\Models\ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => $futureDate,
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'scheduled',
        ]);
        $cs2 = \App\Models\ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => $futureDate,
            'StartTime' => '17:00',
            'EndTime' => '19:00',
            'Status' => 'scheduled',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/reschedule-session', [
            'student_class_id' => $sc->ID,
            'old_date' => $futureDate,
            'old_start_time' => '17:00',
            'new_date' => $newDate,
            'start_time' => '17:00',
            'end_time' => '19:00',
        ]);

        $res->assertOk();

        $cs1->refresh();
        $cs2->refresh();
        $this->assertEquals($futureDate, substr((string) $cs1->SessionDate, 0, 10), 'First slot should not move');
        $this->assertEquals($newDate, substr((string) $cs2->SessionDate, 0, 10), 'Second slot should move to new date');
    }

    public function test_student_classes_index_exposes_two_same_weekday_time_slots(): void
    {
        $token = $this->createUserToken('A', [1], 'director-2slot-index@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-2slot-index@example.com');
        $student = $this->createStudent(1, '雙時段索引');

        $sc = \App\Models\StudentClass::create([
            'StudentID' => $student->id,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'GradeID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->addDays(1)->toDateString(),
            'EndDate' => null,
            'TotalHours' => 40,
            'Memo' => '雙時段索引',
            'Charge' => null,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 0,
            'Disconunt' => null,
            'Rate' => 500,
            'LearnTimeID' => null,
            'MDate' => now(),
            'SessionCount' => 4,
            'SessionDuration' => 120,
            'ScheduleMode' => 'count',
            'RemainingSessions' => 4,
            'UsedSessions' => 0,
            'Stop' => 0,
            'ClassType' => 'one_on_one',
            'week' => 6,
            'time' => '13:00:00',
            'week1' => 6,
            'time1' => '15:00:00',
            'duration1' => 120,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/student-classes?branch_id=1&student_id=' . $student->id);

        $res->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', (int) $sc->ID);
        $this->assertNotNull($row, 'Expected course row in index payload');
        $slots = $row['day_time_slots'] ?? [];
        $this->assertCount(2, $slots, 'Same weekday with two DB rows should yield two day_time_slots');
        $this->assertSame(6, (int) $slots[0]['day']);
        $this->assertSame('13:00', (string) $slots[0]['start_time']);
        $this->assertSame(6, (int) $slots[1]['day']);
        $this->assertSame('15:00', (string) $slots[1]['start_time']);
    }

    private function createUserToken(string $type, array $campusIds, string $loginName, ?int $forceUserId = null): string
    {
        if ($forceUserId && $type === 'T') {
            $user = User::findOrFail($forceUserId);
        } else {
            $user = User::create([
                'LoginName' => $loginName,
                'Name' => $type === 'T' ? '老師測試' : '主任測試',
                'PSW' => 'secret',
                'type' => $type,
                'phone' => '0911000000',
                'MustChangePassword' => false,
            ]);
        }

        foreach ($campusIds as $campusId) {
            UserCampus::firstOrCreate([
                'CampusID' => $campusId,
                'UserID' => $user->id,
            ], [
                'Admin' => $type === 'A' ? 1 : 0,
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

    private function createTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName,
            'Name' => '老師測試',
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

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }
}

