<?php

namespace Tests\Feature;

use App\Models\AdmissionInquiry;
use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\TrialHold;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ScheduleGuardService;
use App\Services\SubstituteService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 試聽暫留 (in-app #575, #5499): a held hold occupies the teacher exactly like the trial it stands for,
 * through the canonical guard, so every guarded write and the availability finder see it; cancel frees the slot.
 */
class TrialHoldTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-09-12'; // a Saturday after "now"; 23:00 keeps the slot in the future (Y2)

    protected function setUp(): void
    {
        parent::setUp();
        config(['perfflags.admissions_funnel_v1' => true]);
        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hold_blocks_a_trial_and_a_one_on_one_lesson_and_cancel_frees_the_slot(): void
    {
        [$campus, $teacher, $token] = $this->campusWithTeacherAndDirector();
        $hold = $this->as($token)->postJson('/api/v1/trial-holds', $this->holdPayload($campus, $teacher, ['note' => '電話詢問']))
            ->assertCreated()->assertJsonPath('data.status', 'held')->json('data');
        $this->assertSame(0, \App\Models\Student::count(), 'a hold creates no student');
        $this->assertSame(0, \App\Models\StudentClass::count(), 'a hold creates no course');

        // A one-on-one lesson cannot overlap the held slot; the refusal names the hold.
        $conflicts = app(ScheduleGuardService::class)->validateScheduleOccurrence([
            'teacher_id' => $teacher->id, 'branch_id' => $campus->id, 'schedule_date' => self::DATE,
            'start_time' => '23:15', 'end_time' => '23:45', 'class_type' => 'one_on_one',
        ]);
        $this->assertSame('teacher_capacity', $conflicts[0]['type'] ?? null);
        $this->assertStringContainsString('試聽暫留', json_encode($conflicts, JSON_UNESCAPED_UNICODE));

        // A second trial in the same slot is refused (one trial per slot), but not once the hold is excluded (conversion).
        $inquiry = $this->inquiry($campus);
        $trial = ['teacher_id' => $teacher->id, 'trial_date' => self::DATE, 'start_time' => '23:00', 'duration_minutes' => 30];
        $this->as($token)->postJson("/api/v1/admission-inquiries/{$inquiry->id}/trial", $trial)
            ->assertStatus(409)->assertJsonPath('code', 'teacher_schedule_conflict');
        $this->assertSame([], app(ScheduleGuardService::class)->validateScheduleOccurrence([
            'teacher_id' => $teacher->id, 'branch_id' => $campus->id, 'schedule_date' => self::DATE,
            'start_time' => '23:00', 'end_time' => '23:30', 'class_type' => 'trial', 'exclude_trial_hold_id' => $hold['id'],
        ]));

        $this->as($token)->postJson("/api/v1/trial-holds/{$hold['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->as($token)->postJson("/api/v1/trial-holds/{$hold['id']}/cancel")->assertStatus(409);
        $this->assertNotNull(TrialHold::find($hold['id'])?->resolved_at, 'cancel keeps the row for the record');
        $this->as($token)->postJson("/api/v1/admission-inquiries/{$inquiry->id}/trial", $trial)->assertCreated();
    }

    public function test_hold_is_refused_over_an_existing_trial_and_a_second_hold(): void
    {
        [$campus, $teacher, $token] = $this->campusWithTeacherAndDirector();
        $inquiry = $this->inquiry($campus);
        $this->as($token)->postJson("/api/v1/admission-inquiries/{$inquiry->id}/trial", [
            'teacher_id' => $teacher->id, 'trial_date' => self::DATE, 'start_time' => '23:00', 'duration_minutes' => 30,
        ])->assertCreated();

        $this->as($token)->postJson('/api/v1/trial-holds', $this->holdPayload($campus, $teacher))
            ->assertStatus(422)->assertJsonPath('code', 'trial_hold_conflict');
        $this->as($token)->postJson('/api/v1/trial-holds', $this->holdPayload($campus, $teacher, ['start_time' => '22:00', 'end_time' => '22:30']))
            ->assertCreated();
        $this->as($token)->postJson('/api/v1/trial-holds', $this->holdPayload($campus, $teacher, ['start_time' => '22:15', 'end_time' => '22:45']))
            ->assertStatus(422)->assertJsonPath('code', 'trial_hold_conflict');
        $this->assertSame(1, TrialHold::count());
    }

    public function test_campus_role_and_teacher_boundaries(): void
    {
        [$campus, $teacher, $token] = $this->campusWithTeacherAndDirector();
        $other = Campus::factory()->create(['active' => true]);
        $otherToken = $this->directorToken((int) $other->id);
        $hold = $this->as($token)->postJson('/api/v1/trial-holds', $this->holdPayload($campus, $teacher))->assertCreated()->json('data');

        // Another campus's director can neither create at this campus nor see or cancel this hold.
        $this->as($otherToken)->postJson('/api/v1/trial-holds', $this->holdPayload($campus, $teacher))->assertForbidden();
        $this->as($otherToken)->getJson('/api/v1/trial-holds?from=2026-09-01&to=2026-09-30')->assertOk()->assertJsonCount(0, 'data');
        $this->as($otherToken)->postJson("/api/v1/trial-holds/{$hold['id']}/cancel")->assertNotFound();
        $this->as($token)->getJson('/api/v1/trial-holds?from=2026-09-01&to=2026-09-30')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.teacher_name', '試聽老師')->assertJsonPath('data.0.start_time', '23:00');

        // A teacher of another campus cannot be held here; teachers cannot manage holds.
        $stranger = $this->teacher('別校老師', (int) $other->id);
        $this->as($token)->postJson('/api/v1/trial-holds', $this->holdPayload($campus, $stranger))
            ->assertStatus(422)->assertJsonPath('code', 'teacher_not_assignable');
        $teacherToken = $this->tokenFor($teacher);
        $this->as($teacherToken)->getJson('/api/v1/trial-holds?from=2026-09-01&to=2026-09-30')->assertForbidden();
        $this->as($teacherToken)->postJson('/api/v1/trial-holds', $this->holdPayload($campus, $teacher))->assertForbidden();
        $this->assertSame(1, TrialHold::count());
    }

    public function test_hold_at_another_campus_is_busy_for_the_cross_campus_finder_only(): void
    {
        [$campus, $teacher, $token] = $this->campusWithTeacherAndDirector();
        $second = Campus::factory()->create(['active' => true]);
        UserCampus::create(['CampusID' => $second->id, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $this->as($this->directorToken((int) $second->id))
            ->postJson('/api/v1/trial-holds', $this->holdPayload($second, $teacher))->assertCreated();

        // The same-campus guard is per campus (unchanged); the availability finder sees the teacher busy everywhere.
        $this->assertSame([], app(ScheduleGuardService::class)->validateScheduleOccurrence([
            'teacher_id' => $teacher->id, 'branch_id' => $campus->id, 'schedule_date' => self::DATE,
            'start_time' => '23:00', 'end_time' => '23:30', 'class_type' => 'one_on_one',
        ]));
        $busy = app(SubstituteService::class)->collectTeacherBusySlots((int) $teacher->id, self::DATE);
        $this->assertSame([['23:00', '23:30', (int) $second->id]], array_map(fn ($b) => [$b['start_time'], $b['end_time'], $b['campus_id']], $busy));
        $withCapacity = app(SubstituteService::class)->collectTeacherBusySlotsWithCapacity((int) $teacher->id, self::DATE);
        $this->assertSame(['trial', 1, ['trial_hold']], [$withCapacity[0]['class_type'], $withCapacity[0]['student_count'], $withCapacity[0]['source_types']]);
    }

    public function test_converting_a_hold_books_the_trial_in_the_held_seat_and_releases_the_hold(): void
    {
        [$campus, $teacher, $token] = $this->campusWithTeacherAndDirector();
        $hold = $this->as($token)->postJson('/api/v1/trial-holds', $this->holdPayload($campus, $teacher))->assertCreated()->json('data');
        $inquiry = $this->inquiry($campus);
        $trial = ['teacher_id' => $teacher->id, 'trial_date' => self::DATE, 'start_time' => '23:00', 'duration_minutes' => 30];

        // Wrong date or an unheld hold is refused and writes nothing.
        $this->as($token)->postJson("/api/v1/admission-inquiries/{$inquiry->id}/trial", ['trial_date' => '2026-09-13', 'trial_hold_id' => $hold['id']] + $trial)
            ->assertStatus(422)->assertJsonPath('code', 'trial_hold_mismatch');
        $this->assertSame(0, \App\Models\StudentClass::count());

        $classId = $this->as($token)->postJson("/api/v1/admission-inquiries/{$inquiry->id}/trial", ['trial_hold_id' => $hold['id']] + $trial)
            ->assertCreated()->json('student_class_id');
        $converted = TrialHold::findOrFail($hold['id']);
        $this->assertSame([TrialHold::CONVERTED, (int) $classId], [$converted->status, (int) $converted->converted_student_class_id]);
        $this->as($token)->getJson('/api/v1/trial-holds?from=2026-09-01&to=2026-09-30')->assertOk()->assertJsonCount(0, 'data');

        // The trial now occupies the slot itself; the converted hold cannot be used again.
        $this->as($token)->postJson('/api/v1/trial-holds', $this->holdPayload($campus, $teacher))->assertStatus(422)->assertJsonPath('code', 'trial_hold_conflict');
        $other = $this->inquiry($campus);
        $this->as($token)->postJson("/api/v1/admission-inquiries/{$other->id}/trial", ['trial_hold_id' => $hold['id']] + $trial)
            ->assertStatus(409)->assertJsonPath('code', 'trial_hold_not_held');
    }

    /** @return array{0: Campus, 1: User, 2: string} */
    private function campusWithTeacherAndDirector(): array
    {
        $campus = Campus::factory()->create(['active' => true]);

        return [$campus, $this->teacher('試聽老師', (int) $campus->id), $this->directorToken((int) $campus->id)];
    }

    private function teacher(string $name, int $campusId): User
    {
        $teacher = User::create(['LoginName' => 'hold-teacher-' . uniqid() . '@example.com', 'Name' => $name, 'PSW' => 'secret', 'type' => 'T', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);

        return $teacher;
    }

    private function directorToken(int $campusId): string
    {
        $director = User::create(['LoginName' => 'hold-director-' . uniqid() . '@example.com', 'Name' => '主任', 'PSW' => 'secret', 'type' => 'A', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);

        return $this->tokenFor($director);
    }

    private function tokenFor(User $user): string
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function as(string $token): self
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}"]);
    }

    /** @return array<string, mixed> */
    private function holdPayload(Campus $campus, User $teacher, array $over = []): array
    {
        return $over + ['campus_id' => $campus->id, 'teacher_id' => $teacher->id, 'hold_date' => self::DATE, 'start_time' => '23:00', 'end_time' => '23:30'];
    }

    private function inquiry(Campus $campus): AdmissionInquiry
    {
        $this->postJson('/api/v1/admission-inquiries', [
            'campus_id' => $campus->id, 'parent_name' => '家長', 'parent_phone' => '0911-' . random_int(100, 999) . '-' . random_int(100, 999),
            'student_name' => '試聽生' . random_int(1000, 9999), 'grade' => 'P5', 'school_name' => '測試國小', 'subject' => 'Math',
            'preferred_slots' => ['週六上午'], 'consent' => 'yes',
        ])->assertStatus(202);

        return AdmissionInquiry::latest('id')->firstOrFail();
    }
}
