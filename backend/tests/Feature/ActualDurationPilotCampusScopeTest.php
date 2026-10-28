<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Scheduling\DeductionBasis;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #3834 pilot boundary (Founder GO 2026-10-13): perfflags.actual_duration_campus_ids lists the campuses where a course
 * may NEWLY opt in to deduction_basis=actual_duration. Create and edit answer 422 elsewhere; a course that already is
 * actual_duration is never touched, so deploying the list changes no balance.
 */
class ActualDurationPilotCampusScopeTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private int $studentId;
    private int $teacherId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['perfflags.actual_duration_deduction_enabled' => true]);

        $this->teacherId = $this->mkUser('T', 'pilot-t' . uniqid() . '@e.com');
        UserCampus::create(['CampusID' => 1, 'UserID' => $this->teacherId, 'Admin' => 0, 'Approved' => 1]);
        $dirId = $this->mkUser('A', 'pilot-d' . uniqid() . '@e.com');
        UserCampus::create(['CampusID' => 1, 'UserID' => $dirId, 'Admin' => 1, 'Approved' => 1]);
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $dirId, 'token' => $this->token, 'expires_at' => now()->addDay()]);
        $this->studentId = (int) Student::create([
            'name' => '試辦' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ])->id;
    }

    /** @dataProvider listProvider */
    public function test_campus_list_parsing(string $raw, int $campus, bool $allowed): void
    {
        config(['perfflags.actual_duration_campus_ids' => $raw]);

        $this->assertSame($allowed, DeductionBasis::campusAllowed($campus));
    }

    /** @return array<string, array{0:string,1:int,2:bool}> */
    public static function listProvider(): array
    {
        return [
            'listed' => ['11,13', 13, true],
            'whitespace' => [' 11 , 13 ', 11, true],
            'not listed' => ['11,13', 4, false],
            'empty list means none' => ['', 11, false],
            'star means every campus' => ['*', 4, true],
            'unknown campus never passes, even under star' => ['*', 0, false],
            'junk entries are ignored' => ['11,x,,1x', 1, false],
        ];
    }

    public function test_create_is_refused_outside_the_pilot_and_allowed_inside(): void
    {
        config(['perfflags.actual_duration_campus_ids' => '2']);
        $this->create(['deduction_basis' => DeductionBasis::ACTUAL_DURATION, 'standard_lesson_minutes' => 120])
            ->assertStatus(422)->assertJsonPath('errors.deduction_basis.0', '此分校尚未開放依實際時長扣堂，請改用「每次上課扣一堂」。');
        $this->assertSame(0, StudentClass::where('StudentID', $this->studentId)->count());

        // A fixed-session course at the same campus is not affected.
        $this->create(['deduction_basis' => DeductionBasis::FIXED_SESSION])->assertCreated();

        config(['perfflags.actual_duration_campus_ids' => '1']);
        $this->create(['deduction_basis' => DeductionBasis::ACTUAL_DURATION, 'standard_lesson_minutes' => 120, 'total_classes' => 8, 'subject' => 'Science',
            'start_time' => '17:00', 'session_plan' => $this->plan(8, '17:00')])->assertCreated();
        $this->assertSame(1, StudentClass::where('StudentID', $this->studentId)->where('deduction_basis', DeductionBasis::ACTUAL_DURATION)->count());
    }

    public function test_a_new_opt_in_by_edit_is_refused_outside_the_pilot_but_an_existing_one_is_left_alone(): void
    {
        $fixed = $this->course();
        config(['perfflags.actual_duration_campus_ids' => '2']);

        $this->patchCourse($fixed, ['deduction_basis' => DeductionBasis::ACTUAL_DURATION, 'standard_lesson_minutes' => 90])
            ->assertStatus(422)->assertJsonPath('errors.deduction_basis.0', '此分校尚未開放依實際時長扣堂，請改用「每次上課扣一堂」。');
        $this->assertSame(DeductionBasis::FIXED_SESSION, $fixed->fresh()->deduction_basis);

        // Already actual_duration (opted in before the list existed): editing it is never blocked by the list ...
        $existing = $this->course(['deduction_basis' => DeductionBasis::ACTUAL_DURATION, 'standard_lesson_minutes' => 120]);
        $this->patchCourse($existing, ['deduction_basis' => DeductionBasis::ACTUAL_DURATION, 'standard_lesson_minutes' => 90])->assertOk();
        // ... and it keeps deducting by its own standard: the list narrows creation only, it moves no balance.
        $this->assertSame(90, SessionDeductionService::billingStandardMinutes($existing->fresh()));

        config(['perfflags.actual_duration_campus_ids' => '1']);
        $this->patchCourse($fixed, ['deduction_basis' => DeductionBasis::ACTUAL_DURATION, 'standard_lesson_minutes' => 90])->assertOk();
        $this->assertTrue($fixed->fresh()->isActualDurationBasis());
    }

    /** @return array<int, array<string, mixed>> */
    private function plan(int $count, string $start = '14:00'): array
    {
        return array_map(fn (int $i) => [
            'session_date' => Carbon::now()->addDays(7 + $i * 7)->toDateString(), 'start_time' => $start, 'kind' => 'future', 'duration_minutes' => 120,
        ], range(0, $count - 1));
    }

    private function create(array $over)
    {
        $payload = array_merge([
            'branch_id' => 1, 'confirmed_dates' => [], 'future_dates' => [], 'student_id' => $this->studentId,
            'teacher_id' => $this->teacherId, 'subject' => 'Math', 'class_type' => 'one_on_one', 'payment_type' => 'session',
            'price_per_session' => 500, 'duration_minutes' => 120, 'start_time' => '14:00', 'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'course_start_date' => Carbon::now()->addDays(7)->toDateString(), 'total_classes' => 1,
            'session_plan' => $this->plan(1),
        ], $over);

        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/class-sessions/batch', $payload);
    }

    private function patchCourse(StudentClass $sc, array $payload)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])
            ->putJson('/api/v1/student-classes/' . $sc->ID, $payload);
    }

    private function course(array $over = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $this->studentId, 'TeacherID' => 1, 'GradeID' => 1, 'SubjectID' => 1,
            'ClassType' => 'one_on_one', 'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'UsedSessions' => 0, 'Rate' => 500, 'TotalHours' => 16, 'Charge' => 4000, 'Pay' => 4000, 'Paid' => 0,
            'Stop' => 0, 'by1' => 0, 'MDate' => now(),
            'StartDate' => Carbon::now()->subMonth()->toDateString(), 'EndDate' => Carbon::now()->addMonths(3)->toDateString(),
        ], $over));
    }

    private function mkUser(string $type, string $login): int
    {
        return (int) User::create(['LoginName' => $login, 'Name' => $type, 'PSW' => 'secret', 'type' => $type, 'phone' => '0900000000', 'MustChangePassword' => false])->id;
    }
}
