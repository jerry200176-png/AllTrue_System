<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Guardian;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\StudentIdentityGroup;
use App\Models\StudentLineBinding;
use App\Models\User;
use App\Services\StudentIdentityService;
use Database\Factories\CampusFactory;
use Database\Factories\StudentFactory;
use Database\Factories\UserCampusFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * #3944 follow-up (review threads 4256776859 / 4256776870).
 *
 * 1. A LINE session anchored to identity-group member B must not keep reaching
 *    member A after A is unbound from that LINE account.
 * 2. The nightly bindings:cleanup-orphans job must revoke guardian access and
 *    expire sessions, not just delete the binding row.
 */
class GuardianRevocationGapsTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campusA;

    private Campus $campusB;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campusA = CampusFactory::new()->create();
        $this->campusB = CampusFactory::new()->create();
        $this->director = UserFactory::new()->director()->create();
        UserCampusFactory::new()->create([
            'UserID' => $this->director->id,
            'CampusID' => $this->campusA->id,
            'Approved' => true,
        ]);
    }

    /** @return array<string, array{0: bool}> */
    public static function flagModes(): array
    {
        return ['multi-guardian on' => [true], 'multi-guardian off' => [false]];
    }

    /** @dataProvider flagModes */
    public function test_still_bound_line_account_keeps_identity_group_access(bool $flag): void
    {
        config(['perfflags.multi_guardian_enabled' => $flag]);
        $line = $this->lineId('a');
        [$a, $b, $group] = $this->groupedPair(StudentIdentityService::MODE_ACTIONS);
        $this->bindLine($a, $line, $this->campusA);
        $this->bindLine($b, $line, $this->campusB);
        $classA = $this->course($a);
        $classB = $this->course($b);
        $token = $this->groupToken($b, $group, $line);

        $dashboard = $this->dashboard($token)->assertOk();
        $dashboard->assertJsonPath('identity_group_id', $group->id);
        $this->assertEqualsCanonicalizing([$classA, $classB], collect($dashboard->json('classes'))->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->billingStudentClassOwners($token));

        $this->switchTo($token, $a)->assertOk()->assertJsonPath('student.id', $a->id);
        $this->leave($token, $this->futureSession($classA))->assertOk();
        $this->feedback($token, $this->learningRecord($a, $classA))->assertOk();
    }

    /** @dataProvider flagModes */
    public function test_token_anchored_to_b_cannot_reach_a_after_a_is_unbound(bool $flag): void
    {
        config(['perfflags.multi_guardian_enabled' => $flag]);
        $line = $this->lineId('b');
        [$a, $b, $group] = $this->groupedPair(StudentIdentityService::MODE_ACTIONS);
        $bindingA = $this->bindLine($a, $line, $this->campusA);
        $this->bindLine($b, $line, $this->campusB);
        $classA = $this->course($a);
        $classB = $this->course($b);
        $sessionA = $this->futureSession($classA);
        $recordA = $this->learningRecord($a, $classA);
        // Token issued before the unbind, anchored to the still-bound member B.
        $token = $this->groupToken($b, $group, $line);

        $this->deleteJson('/api/v1/bindings/' . $bindingA->id, [], ['X-User-Id' => (string) $this->director->id])
            ->assertOk();

        // B itself is still bound: the token keeps working for B only.
        $dashboard = $this->dashboard($token)->assertOk();
        $this->assertSame([$classB], collect($dashboard->json('classes'))->pluck('id')->all());
        $this->assertNull($dashboard->json('identity_group_id'));
        $campusScoped = $this->getJson('/api/v1/parent/dashboard?scope=campus&campus_id=' . $this->campusA->id, $this->bearer($token))
            ->assertOk();
        $this->assertNotContains($classA, collect($campusScoped->json('classes'))->pluck('id')->all());
        $this->assertSame([$b->id], $this->billingStudentClassOwners($token));
        // Codex r4261327132: with only the anchor left, billing metadata is campus-scoped like the dashboard.
        $this->getJson('/api/v1/parent/billing-history', $this->bearer($token))->assertOk()
            ->assertJsonPath('active_scope', 'campus')->assertJsonPath('identity_group_id', null);

        // A is unreachable for reads and actions.
        $this->switchTo($token, $a)->assertForbidden();
        $this->leave($token, $sessionA)->assertForbidden();
        $this->assertSame('scheduled', ClassSession::query()->findOrFail($sessionA->id)->Status);
        $this->feedback($token, $recordA)->assertForbidden();
        $this->putJson("/api/v1/parent/learning-records/{$recordA}/feedback", ['content' => '測試'], $this->bearer($token))
            ->assertForbidden();
        $this->assertSame(0, DB::table('learning_record_feedbacks')->where('learning_record_id', $recordA)->count());
    }

    /** @dataProvider flagModes */
    public function test_line_login_lists_only_group_members_still_bound_after_an_unbind(bool $flag): void
    {
        config(['perfflags.multi_guardian_enabled' => $flag]);
        $line = $this->lineId('d');
        [$a, $b, $group] = $this->groupedPair(StudentIdentityService::MODE_ACTIONS);
        $bindingA = $this->bindLine($a, $line, $this->campusA);
        $this->bindLine($b, $line, $this->campusB);
        Http::fake(['https://api.line.me/v2/profile' => Http::response(['userId' => $line], 200)]);

        $before = $this->postJson('/api/v1/parent/login-line', ['access_token' => 'valid-token'])->assertOk();
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            collect($before->json('identity_groups.0.members'))->pluck('student_id')->all()
        );

        $this->deleteJson('/api/v1/bindings/' . $bindingA->id, [], ['X-User-Id' => (string) $this->director->id])
            ->assertOk();

        $after = $this->postJson('/api/v1/parent/login-line', ['access_token' => 'valid-token'])->assertOk();
        $after->assertJsonPath('student.id', $b->id);
        $this->assertSame([$b->id], collect($after->json('students'))->pluck('id')->all());
        // A group with one reachable member is not listed, so A's id, campus and name never leave.
        $this->assertSame([], $after->json('identity_groups'));
    }

    /** Codex r4260862499: a three-member group keeps no name derived from the unbound member. */
    public function test_line_login_group_name_never_comes_from_an_unreachable_member(): void
    {
        $line = $this->lineId('e');
        [$a, $b, $group] = $this->groupedPair(StudentIdentityService::MODE_ACTIONS);
        $a->update(['name' => 'Source ' . Str::random(6)]);
        $group->update(['display_name' => $a->name]);
        $campusC = CampusFactory::new()->create();
        $c = StudentFactory::new()->create(['CampusID' => $campusC->id, 'enable' => 1, 'name' => 'Third ' . Str::random(6), 'Phone' => $b->Phone]);
        app(StudentIdentityService::class)->linkStudents((int) $b->id, (int) $c->id, null, 'super_admin', []);
        $bindingA = $this->bindLine($a, $line, $this->campusA);
        $this->bindLine($b, $line, $this->campusB);
        $this->bindLine($c, $line, $campusC);
        $this->deleteJson('/api/v1/bindings/' . $bindingA->id, [], ['X-User-Id' => (string) $this->director->id])->assertOk();
        Http::fake(['https://api.line.me/v2/profile' => Http::response(['userId' => $line], 200)]);

        $rows = $this->postJson('/api/v1/parent/login-line', ['access_token' => 'valid-token'])->assertOk()->json('identity_groups');
        $this->assertEqualsCanonicalizing([$b->id, $c->id], collect($rows[0]['members'] ?? [])->pluck('student_id')->all());
        $this->assertStringNotContainsString($a->name, json_encode($rows, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Access contract (strict): a LINE account never bound to a group member does not reach it through the group.
     *
     * @dataProvider flagModes
     */
    public function test_line_session_does_not_reach_a_group_member_it_was_never_bound_to(bool $flag): void
    {
        config(['perfflags.multi_guardian_enabled' => $flag]);
        $line = $this->lineId('f');
        [$a, $b, $group] = $this->groupedPair(StudentIdentityService::MODE_ACTIONS);
        $this->bindLine($b, $line, $this->campusB); // same child, bound at campus B only
        $classA = $this->course($a);
        $token = $this->groupToken($b, $group, $line);

        $this->assertNotContains($classA, collect($this->dashboard($token)->assertOk()->json('classes'))->pluck('id')->all());
        $this->switchTo($token, $a)->assertForbidden();
    }

    /** Access contract: two separately bound children (no identity group) stay reachable from either session. */
    /** @dataProvider flagModes */
    public function test_separately_bound_children_stay_reachable_without_a_group(bool $flag): void
    {
        config(['perfflags.multi_guardian_enabled' => $flag]);
        $line = $this->lineId('9');
        $k1 = StudentFactory::new()->create(['CampusID' => $this->campusA->id, 'enable' => 1]);
        $k2 = StudentFactory::new()->create(['CampusID' => $this->campusB->id, 'enable' => 1]);
        $this->bindLine($k1, $line, $this->campusA);
        $this->bindLine($k2, $line, $this->campusB);

        $this->switchTo($this->groupToken($k1, null, $line), $k2)->assertOk()->assertJsonPath('student.id', $k2->id);
    }

    /** Access contract: a suspended guardian link stops group reach like a revoked one (flag on: no SLB fallback). */
    public function test_suspended_guardian_link_stops_group_reach(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $line = $this->lineId('8');
        [$a, $b, $group] = $this->groupedPair(StudentIdentityService::MODE_ACTIONS);
        $this->bindLine($a, $line, $this->campusA);
        $this->bindLine($b, $line, $this->campusB);
        $classA = $this->course($a);
        $token = $this->groupToken($b, $group, $line);
        $this->linkFor($a, $line)->update(['status' => StudentGuardian::STATUS_SUSPENDED]);

        $this->assertNotContains($classA, collect($this->dashboard($token)->assertOk()->json('classes'))->pluck('id')->all());
        $this->switchTo($token, $a)->assertForbidden();
    }

    /** Codex r4261240553: the dashboard switcher never lists a student through another guardian's binding. */
    /** @dataProvider flagModes */
    public function test_dashboard_switcher_lists_only_students_the_session_line_still_reaches(bool $flag): void
    {
        config(['perfflags.multi_guardian_enabled' => $flag]);
        [$mine, $other] = [$this->lineId('7'), $this->lineId('6')];
        $a = StudentFactory::new()->create(['CampusID' => $this->campusA->id, 'enable' => 1]);
        $b = StudentFactory::new()->create(['CampusID' => $this->campusA->id, 'enable' => 1]);
        $bindingA = $this->bindLine($a, $mine, $this->campusA);
        $this->bindLine($b, $mine, $this->campusA);
        $this->bindLine($a, $other, $this->campusA);
        $this->bindLine($b, $other, $this->campusA);
        $token = $this->groupToken($b, null, $mine);
        $this->assertSame([$a->id, $b->id], collect($this->dashboard($token)->assertOk()->json('students'))->pluck('id')->sort()->values()->all());

        $this->deleteJson('/api/v1/bindings/' . $bindingA->id, [], ['X-User-Id' => (string) $this->director->id])->assertOk();

        $this->assertNotContains($a->id, collect($this->dashboard($token)->assertOk()->json('students') ?? [])->pluck('id')->all());
    }

    /** Codex r4261240532 / r4261327121: a suspended anchor link ends the LINE session on every parent-token endpoint. */
    public function test_feedback_endpoints_revalidate_the_anchor_link(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $line = $this->lineId('5');
        $a = StudentFactory::new()->create(['CampusID' => $this->campusA->id, 'enable' => 1]);
        $this->bindLine($a, $line, $this->campusA);
        $record = $this->learningRecord($a, $this->course($a));
        $token = $this->groupToken($a, null, $line);
        $this->feedback($token, $record)->assertOk();
        $this->getJson('/api/v1/parent/system-trust-summary', $this->bearer($token))->assertOk();

        $this->linkFor($a, $line)->update(['status' => StudentGuardian::STATUS_SUSPENDED]);

        $this->feedback($token, $record)->assertUnauthorized();
        $this->postJson('/api/v1/parent/feedback', [], $this->bearer($token))->assertUnauthorized();
        $this->getJson('/api/v1/parent/system-trust-summary', $this->bearer($token))->assertUnauthorized();
        $this->dashboard($token)->assertUnauthorized();
    }

    public function test_phone_login_group_session_is_unaffected_by_a_line_unbind(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        [$a, $b, $group] = $this->groupedPair(StudentIdentityService::MODE_READONLY);
        $bindingA = $this->bindLine($a, $this->lineId('c'), $this->campusA);
        $classA = $this->course($a);
        $classB = $this->course($b);
        $phoneToken = $this->groupToken($b, $group, null);

        $this->deleteJson('/api/v1/bindings/' . $bindingA->id, [], ['X-User-Id' => (string) $this->director->id])
            ->assertOk();

        $dashboard = $this->dashboard($phoneToken)->assertOk();
        $this->assertEqualsCanonicalizing([$classA, $classB], collect($dashboard->json('classes'))->pluck('id')->all());
    }

    public function test_nightly_cleanup_revokes_guardian_access_and_sessions(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $line = $this->lineId('d');
        $other = $this->lineId('e');
        $disabled = StudentFactory::new()->create(['CampusID' => $this->campusA->id, 'enable' => 1]);
        $active = StudentFactory::new()->create(['CampusID' => $this->campusA->id, 'enable' => 1]);
        $this->bindLine($disabled, $line, $this->campusA);
        $this->bindLine($active, $line, $this->campusA);
        $this->bindLine($active, $other, $this->campusA);
        $disabledToken = $this->groupToken($disabled, null, $line);
        $activeToken = $this->groupToken($active, null, $line);
        $otherToken = $this->groupToken($active, null, $other);
        $this->dashboard($disabledToken)->assertOk();
        $disabled->forceFill(['enable' => 0])->save();

        Artisan::call('bindings:cleanup-orphans');
        $this->assertStringContainsString('orphan_bindings_deleted=1', Artisan::output());

        $this->assertDatabaseMissing('student_line_bindings', ['student_id' => $disabled->id]);
        $this->assertSame(StudentGuardian::STATUS_REVOKED, $this->linkFor($disabled, $line)->status);
        $this->assertSame(0, ParentSession::query()
            ->where('StudentID', $disabled->id)
            ->where('ExpiresAt', '>', now())
            ->count());
        $this->dashboard($disabledToken)->assertUnauthorized();

        // The same LINE account's other student and other guardians are untouched.
        $this->assertSame(StudentGuardian::STATUS_ACTIVE, $this->linkFor($active, $line)->status);
        $this->assertSame(StudentGuardian::STATUS_ACTIVE, $this->linkFor($active, $other)->status);
        $this->dashboard($activeToken)->assertOk();
        $this->dashboard($otherToken)->assertOk();
    }

    public function test_nightly_cleanup_of_missing_student_expires_its_sessions(): void
    {
        config(['perfflags.multi_guardian_enabled' => false]);
        $line = $this->lineId('f');
        StudentLineBinding::create([
            'student_id' => 999999,
            'line_user_id' => $line,
            'campus_id' => $this->campusA->id,
            'bound_at' => now(),
        ]);
        ParentSession::create([
            'StudentID' => 999999,
            'line_user_id' => $line,
            'TokenHash' => hash('sha256', Str::random(48)),
            'ExpiresAt' => now()->addDays(30),
        ]);

        Artisan::call('bindings:cleanup-orphans');
        $this->assertStringContainsString('orphan_bindings_deleted=1', Artisan::output());

        $this->assertDatabaseMissing('student_line_bindings', ['student_id' => 999999]);
        $this->assertSame(0, ParentSession::query()
            ->where('StudentID', 999999)
            ->where('ExpiresAt', '>', now())
            ->count());
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @return array{0: Student, 1: Student, 2: StudentIdentityGroup} */
    private function groupedPair(string $mode): array
    {
        $name = 'Group ' . Str::random(6);
        $phone = '0912' . random_int(100000, 999999);
        $a = StudentFactory::new()->create(['CampusID' => $this->campusA->id, 'enable' => 1, 'name' => $name, 'Phone' => $phone]);
        $b = StudentFactory::new()->create(['CampusID' => $this->campusB->id, 'enable' => 1, 'name' => $name, 'Phone' => $phone]);
        $service = app(StudentIdentityService::class);
        $group = $service->linkStudents((int) $a->id, (int) $b->id, null, 'super_admin', []);
        $service->setMode((int) $group->id, $mode, null);

        return [$a, $b, $group];
    }

    private function lineId(string $char): string
    {
        return 'U' . str_repeat($char, 32);
    }

    /** A verified LINE binding plus the guardian link it produces under dual-write. */
    private function bindLine(Student $student, string $line, Campus $campus): StudentLineBinding
    {
        $binding = StudentLineBinding::create([
            'student_id' => $student->id,
            'line_user_id' => $line,
            'campus_id' => $campus->id,
            'bound_at' => now(),
            'verified_at' => now(),
            'verification_method' => 'contact_phone',
        ]);
        $guardian = Guardian::query()->firstOrCreate(['line_user_id' => $line], ['display_name' => 'G']);
        StudentGuardian::query()->firstOrCreate(['student_id' => $student->id, 'guardian_id' => $guardian->id], [
            'campus_id' => $campus->id,
            'role' => StudentGuardian::ROLE_GUARDIAN,
            'is_primary' => !StudentGuardian::query()->where('student_id', $student->id)->exists(),
            'status' => StudentGuardian::STATUS_ACTIVE,
            'notify_learning_feedback' => true,
            'notify_tuition' => true,
            'source' => StudentGuardian::SOURCE_LINE_BINDING,
            'student_line_binding_id' => $binding->id,
        ]);

        return $binding;
    }

    private function linkFor(Student $student, string $line): StudentGuardian
    {
        $guardian = Guardian::query()->where('line_user_id', $line)->firstOrFail();

        return StudentGuardian::query()
            ->where('student_id', $student->id)
            ->where('guardian_id', $guardian->id)
            ->firstOrFail();
    }

    private function groupToken(Student $anchor, ?StudentIdentityGroup $group, ?string $line): string
    {
        $raw = Str::random(48);
        ParentSession::create([
            'StudentID' => $anchor->id,
            'identity_group_id' => $group?->id,
            'line_user_id' => $line,
            'TokenHash' => hash('sha256', $raw),
            'ExpiresAt' => now()->addDays(30),
        ]);

        return $raw;
    }

    private function course(Student $student): int
    {
        return (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $student->id, 'TeacherID' => 1, 'GradeID' => 1, 'SubjectID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->subDays(30)->toDateString(),
            'TotalHours' => 20, 'Charge' => 1000, 'Pay' => 0, 'Paid' => 0, 'Rate' => 1000,
            'Stop' => 0, 'ScheduleMode' => 'count', 'RemainingSessions' => 10, 'UsedSessions' => 0,
            'SessionCount' => 10, 'SessionDuration' => 60, 'ClassType' => 'one_on_one',
        ]);
    }

    /** Y2: tomorrow at 23:00 so it is never "already started". */
    private function futureSession(int $studentClassId): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $studentClassId,
            'SessionDate' => now()->addDay()->toDateString(),
            'StartTime' => '23:00',
            'EndTime' => '23:50',
            'Status' => 'scheduled',
        ]);
    }

    private function learningRecord(Student $student, int $studentClassId): int
    {
        $date = now()->subDay()->toDateString();
        $sessionId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $studentClassId, 'SessionDate' => $date,
            'StartTime' => '14:00', 'EndTime' => '15:00', 'Status' => 'attended',
        ]);

        return (int) DB::table('LearningRecord')->insertGetId([
            'StudentID' => $student->id, 'StudentClassID' => $studentClassId, 'ClassSessionID' => $sessionId,
            'TeacherID' => 1, 'Subject' => 'English', 'SessionDate' => $date, 'StartTime' => '14:00',
            'EndTime' => '15:00', 'Content' => 'c', 'Status' => 'approved', 'ApprovedBy' => 1,
            'ApprovedAt' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<int, int> student ids owning the billing-history rows */
    private function billingStudentClassOwners(string $token): array
    {
        $ids = collect($this->getJson('/api/v1/parent/billing-history', $this->bearer($token))
            ->assertOk()
            ->json('records'))
            ->pluck('student_class_id')
            ->all();

        return DB::table('StudentClass')->whereIn('ID', $ids)->pluck('StudentID')
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    private function dashboard(string $token): TestResponse
    {
        return $this->getJson('/api/v1/parent/dashboard?scope=all', $this->bearer($token));
    }

    private function switchTo(string $token, Student $student): TestResponse
    {
        return $this->postJson('/api/v1/parent/switch-student', ['student_id' => $student->id], $this->bearer($token));
    }

    private function leave(string $token, ClassSession $session): TestResponse
    {
        return $this->postJson('/api/v1/parent/sessions/' . $session->id . '/leave', ['reason' => '測試'], $this->bearer($token));
    }

    private function feedback(string $token, int $recordId): TestResponse
    {
        return $this->getJson("/api/v1/parent/learning-records/{$recordId}/feedback", $this->bearer($token));
    }
}
