<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CourseContractGroupMember;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\CourseContinuityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TutoringContinuationTest extends TestCase
{
    use RefreshDatabase;

    private array $initialCounts = [];
    private int $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-12 09:00:00');
        // Full CI includes unrelated courses. Assert exact per-test deltas,
        // never assume that an otherwise unrelated table starts empty.
        foreach (['StudentClass', 'ClassSession', 'course_contract_group_members', 'Invoice', 'InvoiceItem', 'Payment', 'payment_reports'] as $table) {
            $this->initialCounts[$table] = \Illuminate\Support\Facades\DB::table($table)->count();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @dataProvider scheduleModes */
    public function test_creates_linked_zero_charge_term_without_touching_source(string $mode): void
    {
        $source = $this->fixture(['ScheduleMode' => $mode]);
        $before = $source->fresh()->getAttributes();
        $response = $this->postJson("/api/v1/student-classes/{$source->ID}/continue-tutoring", $this->payload());
        $response->assertCreated()->assertJsonPath('new_course.charge', 0)
            ->assertJsonPath('source_course_id', (int) $source->ID)
            ->assertJsonPath('next_actions', ['view_new_course']);
        $new = StudentClass::findOrFail($response->json('new_course.id'));
        foreach (['StudentID', 'SubjectID', 'TeacherID', 'Rate', 'SessionDuration', 'ClassType', 'ScheduleMode'] as $key) {
            $this->assertEquals($source->getAttribute($key), $new->getAttribute($key), $key);
        }
        foreach (['Charge', 'Paid', 'Pay', 'UsedSessions'] as $key) {
            $this->assertSame(0, (int) $new->getAttribute($key), $key);
        }
        $this->assertNull($new->PayDate);
        $this->assertSame($before, $source->fresh()->getAttributes());
        $this->assertGreaterThan(0, ClassSession::where('StudentClassID', $new->ID)->count());
        $this->assertTableDelta('Invoice', 0);
        $this->assertTableDelta('InvoiceItem', 0);
        $this->assertTableDelta('Payment', 0);
        $this->assertTableDelta('payment_reports', 0);
        $members = CourseContractGroupMember::where('group_id', $response->json('continuity_group_id'))->orderBy('sequence')->get();
        $this->assertSame(['original', 'renewal'], $members->pluck('relation_type')->all());
        $this->assertSame([(int) $source->ID, (int) $new->ID], $members->pluck('student_class_id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotNull($members->last()->created_by);
        $this->postJson("/api/v1/student-classes/{$source->ID}/continue-tutoring", $this->payload())->assertStatus(409);
        $this->assertTableDelta('StudentClass', 2);
        $this->assertTableDelta('course_contract_group_members', 2);
    }

    public static function scheduleModes(): array
    {
        return [['count'], ['date']];
    }

    /** @dataProvider invalidRequests */
    public function test_invalid_request_leaves_no_partial_course(array $sourceOverrides, array $input): void
    {
        $source = $this->fixture($sourceOverrides);
        $this->postJson("/api/v1/student-classes/{$source->ID}/continue-tutoring", array_merge($this->payload(), $input))->assertStatus(422);
        $this->assertTableDelta('StudentClass', 1);
        $this->assertTableDelta('ClassSession', 0);
        $this->assertTableDelta('course_contract_group_members', 0);
    }

    public static function invalidRequests(): array
    {
        return [
            'paid course' => [['ClassType' => 'one_on_one'], []],
            'package' => [['PackageID' => 12], []],
            'overlap' => [[], ['start_date' => '2026-09-30']],
            'price tampering' => [[], ['Charge' => 100]],
            'payment tampering' => [[], ['paid_at' => '2026-10-05']],
            'student tampering' => [[], ['student_id' => 22]],
            'group tampering' => [[], ['group_id' => 22]],
            'no fixed schedule' => [['week' => null, 'time' => null], []],
        ];
    }

    public function test_foreign_campus_is_forbidden(): void
    {
        $source = $this->fixture();
        Student::where('id', $source->StudentID)->update(['CampusID' => 2]);
        $this->postJson("/api/v1/student-classes/{$source->ID}/continue-tutoring", $this->payload())->assertForbidden();
        $this->assertTableDelta('StudentClass', 1);
    }

    public function test_first_lesson_follows_fixed_weekday_not_arbitrary_start_date(): void
    {
        $source = $this->fixture();
        $response = $this->postJson("/api/v1/student-classes/{$source->ID}/continue-tutoring", array_merge($this->payload(), ['start_date' => '2026-10-06']));
        $response->assertCreated();
        $rows = ClassSession::where('StudentClassID', $response->json('new_course.id'))->orderBy('SessionDate')->get();
        $this->assertCount(4, $rows);
        $this->assertSame('2026-10-12', Carbon::parse($rows->first()->SessionDate)->toDateString());
        foreach ($rows as $row) $this->assertSame(1, Carbon::parse($row->SessionDate)->dayOfWeekIso);
    }

    public function test_manual_course_keeps_manual_policy_without_auto_generated_sessions(): void
    {
        $source = $this->fixture();
        $source->setAttribute('scheduling_policy', 'manual_occurrence');
        $source->save();
        $response = $this->postJson("/api/v1/student-classes/{$source->ID}/continue-tutoring", $this->payload());
        $response->assertCreated()->assertJsonPath('new_course.created_sessions', 0);
        $new = StudentClass::findOrFail($response->json('new_course.id'));
        $this->assertSame('manual_occurrence', $new->getAttribute('scheduling_policy'));
        $this->assertSame(4, (int) $new->SessionCount);
        $this->assertTableDelta('ClassSession', 0);
    }

    public function test_link_failure_rolls_back_new_course_and_sessions(): void
    {
        $source = $this->fixture();
        $this->mock(CourseContinuityService::class, function ($mock) {
            $mock->shouldReceive('createGroup')->once()->andThrow(ValidationException::withMessages(['members' => 'test conflict']));
        });
        $this->postJson("/api/v1/student-classes/{$source->ID}/continue-tutoring", $this->payload())->assertStatus(422);
        $this->assertTableDelta('StudentClass', 1);
        $this->assertTableDelta('ClassSession', 0);
    }

    public function test_teacher_cannot_create_next_term(): void
    {
        $source = $this->fixture();
        User::query()->whereKey($this->actorId)->update(['type' => 'T']);
        UserCampus::query()->where('UserID', $this->actorId)->update(['Admin' => 0]);
        $this->postJson("/api/v1/student-classes/{$source->ID}/continue-tutoring", $this->payload())->assertForbidden();
        $this->assertTableDelta('StudentClass', 1);
    }

    public function test_missing_campus_scope_cannot_create_next_term(): void
    {
        $source = $this->fixture();
        UserCampus::query()->where('UserID', $this->actorId)->delete();
        $this->postJson("/api/v1/student-classes/{$source->ID}/continue-tutoring", $this->payload())->assertForbidden();
        $this->assertTableDelta('StudentClass', 1);
    }

    public function test_no_auth_cannot_create_next_term(): void
    {
        $source = $this->fixture();
        $response = $this->withHeaders(['Authorization' => ''])->postJson("/api/v1/student-classes/{$source->ID}/continue-tutoring", $this->payload());
        $response->assertStatus(401);
        $this->assertTableDelta('StudentClass', 1);
    }

    public function test_existing_student_session_conflict_rolls_back_new_term(): void
    {
        $source = $this->fixture();
        $other = $source->replicate();
        $other->EndDate = '2026-10-31';
        $other->save();
        ClassSession::create(['StudentClassID' => $other->ID, 'SessionDate' => '2026-10-05', 'StartTime' => '14:00:00', 'EndTime' => '16:00:00', 'Status' => 'scheduled']);
        $this->postJson("/api/v1/student-classes/{$source->ID}/continue-tutoring", $this->payload())->assertStatus(422);
        $this->assertTableDelta('StudentClass', 2);
        $this->assertTableDelta('ClassSession', 1);
        $this->assertTableDelta('course_contract_group_members', 0);
    }

    private function assertTableDelta(string $table, int $added): void
    {
        $this->assertDatabaseCount($table, $this->initialCounts[$table] + $added);
    }

    private function payload(): array
    {
        return ['start_date' => '2026-10-05', 'end_date' => '2026-10-31', 'sessions' => 4];
    }

    private function fixture(array $overrides = []): StudentClass
    {
        $user = User::create(['LoginName' => 'continuation@example.test', 'Name' => '測試主任', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0900000000']);
        $this->actorId = (int) $user->id;
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
        $student = Student::create(['name' => '測試學生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99,
            'by1' => 4, 'Period' => 4, 'StartDate' => '2026-09-01', 'EndDate' => '2026-09-30',
            'TotalHours' => 8, 'Charge' => 0, 'Paid' => 0, 'Rate' => 1200, 'MDate' => now(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 4, 'SessionDuration' => 120,
            'RemainingSessions' => 4, 'ClassType' => 'tutoring', 'UsedSessions' => 0,
            'week' => 1, 'time' => '14:00:00',
        ], $overrides));
    }
}
