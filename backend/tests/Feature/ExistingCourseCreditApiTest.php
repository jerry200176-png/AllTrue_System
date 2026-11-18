<?php

namespace Tests\Feature;

use App\Models\{AuthToken, Campus, ClassSession, ExistingCourseCreditAllocationGroup as Group, Payment, Student, StudentClass, SubjectCreditTransfer, User, UserCampus};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ExistingCourseCreditFixtures;
use Tests\TestCase;

/** In-app563: real HTTP permissions and explicit intent around the internal financial authority. */
class ExistingCourseCreditApiTest extends TestCase
{
    use RefreshDatabase, ExistingCourseCreditFixtures;

    protected function setUp(): void { parent::setUp(); Carbon::setTestNow('2026-09-30 10:00:00'); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function context(): array
    {
        [$source, $targets, $input, $actor, $campus] = $this->allocation();
        UserCampus::firstOrCreate(['UserID' => $source->TeacherID, 'CampusID' => $campus], ['Admin' => 0, 'Approved' => 1]);
        return [$source, $targets, $input, $actor, $campus, (string) AuthToken::where('user_id', $actor)->value('token')];
    }

    public function test_readonly_proposal_explicit_apply_replay_and_whole_inverse_preserve_cash_and_taught_history(): void
    {
        [$source, $targets, $input, $actor, $campus, $token] = $this->context();
        $url = "/api/v1/student-classes/{$source->ID}/existing-credit-allocations";
        $before = $this->businessSnapshot();
        $choices = $this->withToken($token)->getJson("/api/v1/student-classes?student_id={$source->StudentID}&per_page=100")->assertOk();
        foreach ($targets as $targetId => [$count, $used]) {
            $choice = collect($choices->json('data'))->firstWhere('id', $targetId);
            $this->assertNotNull($choice, 'The real candidate API must retain completed existing targets.');
            $this->assertSame([$count, $used], [(int) $choice['SessionCount'], (int) $choice['sessions_used']]);
        }
        $quote = $this->withToken($token)->postJson("{$url}/preview", ['target_course_ids' => array_keys($targets)])
            ->assertOk()->assertJsonPath('execution_available', true)->assertJsonCount(5, 'released_lessons');
        $this->assertSame($before, $this->businessSnapshot());
        $body = $input + ['actor_id' => 999999, 'campus_id' => 999999];
        $body['state_hash'] = $quote->json('state_hash');
        $taught = ClassSession::where('Status', 'attended')->orderBy('id')->get()->toJson();
        $result = $this->withToken($token)->postJson($url, $body)->assertCreated()->assertJsonPath('replayed', false);
        $id = $result->json('allocation_group.id');
        $this->assertDatabaseHas('existing_course_credit_allocation_groups', ['id' => $id, 'created_by' => $actor, 'campus_id' => $campus]);
        $this->withToken($token)->postJson($url, $body)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('allocation_group.id', $id);
        $this->assertDatabaseCount('existing_course_credit_allocation_groups', 1);
        $this->assertSame($taught, ClassSession::where('Status', 'attended')->orderBy('id')->get()->toJson());
        $this->assertSame(12000, (int) Payment::sum('Amount'));
        foreach ([$source->ID, ...array_keys($targets)] as $courseId) {
            $history = $this->withToken($token)->getJson("/api/v1/student-classes/{$courseId}/existing-credit-allocations")
                ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('per_page', 20)
                ->assertJsonPath('data.0.id', $id)->assertJsonCount(2, 'data.0.targets');
            foreach (['source_before', 'source_after', 'targets_before', 'request_fingerprint', 'operation_key'] as $private) {
                $this->assertArrayNotHasKey($private, $history->json('data.0'));
            }
        }
        $this->withToken($token)->getJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer")
            ->assertOk()->assertJsonPath('data.0.allocation_group_id', $id);
        $this->withToken($token)->postJson("{$url}/{$id}/reverse", ['reason' => 'restore same paid lessons'])
            ->assertOk()->assertJsonPath('replayed', false)->assertJsonPath('allocation_group.reversed', true);
        $settled = $this->businessSnapshot();
        $this->withToken($token)->postJson("{$url}/{$id}/reverse", ['reason' => 'restore same paid lessons'])
            ->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($settled, $this->businessSnapshot());
        $this->assertSame(12000, (int) Payment::sum('Amount'));
    }

    public static function routes(): array
    {
        return [['GET', ''], ['POST', '/preview'], ['POST', ''], ['POST', '/1/reverse']];
    }

    #[DataProvider('routes')]
    public function test_every_surface_denies_teacher_empty_scope_and_other_campus_without_mutations(string $method, string $suffix): void
    {
        [$source, , $input, , $campus] = $this->context();
        $otherCampus = (int) Campus::factory()->create()->id;
        $before = $this->businessSnapshot();
        foreach ([$this->token(null), $this->token($campus, false), $this->token($otherCampus)] as $denied) {
            $this->withToken($denied)->json($method, "/api/v1/student-classes/{$source->ID}/existing-credit-allocations{$suffix}", $input)
                ->assertForbidden();
        }
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseCount('existing_course_credit_allocation_groups', 0);
    }

    public function test_no_caller_flag_can_invent_missing_release_confirmation(): void
    {
        [$source, , $input, , , $token] = $this->context();
        $url = "/api/v1/student-classes/{$source->ID}/existing-credit-allocations";
        $before = $this->businessSnapshot();
        unset($input['released_session_ids']);
        $this->withToken($token)->postJson($url, $input + ['auto_release' => true])->assertStatus(422);
        $this->withToken($token)->postJson($url, $input + ['released_session_ids' => [], 'auto_release' => true])->assertStatus(422);
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseCount('existing_course_credit_allocation_groups', 0);
    }

    public function test_changed_zero_fee_case_is_refused_without_repricing_or_repairing_the_source(): void
    {
        [$source, $targets, $input, , , $token] = $this->context();
        $source->forceFill(['SessionCount' => 7, 'RemainingSessions' => 5, 'Charge' => 10500])->save();
        StudentClass::whereIn('ID', array_keys($targets))->update(['Rate' => 0, 'Charge' => 0]);
        $before = $this->businessSnapshot();
        $url = "/api/v1/student-classes/{$source->ID}/existing-credit-allocations";
        $this->withToken($token)->postJson("{$url}/preview", ['target_course_ids' => array_keys($targets)])->assertStatus(422);
        $this->withToken($token)->postJson($url, $input)->assertStatus(422);
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseCount('existing_course_credit_allocation_groups', 0);
    }

    public function test_even_free_capacity_requires_an_explicit_empty_release_list(): void
    {
        [$source, $targets, $input, , , $token] = $this->context();
        ClassSession::where('StudentClassID', $source->ID)->where('Status', 'scheduled')->delete();
        $url = "/api/v1/student-classes/{$source->ID}/existing-credit-allocations";
        $quote = $this->withToken($token)->postJson("{$url}/preview", ['target_course_ids' => array_keys($targets)])
            ->assertOk()->assertJsonCount(0, 'released_lessons');
        unset($input['released_session_ids']); $input['state_hash'] = $quote->json('state_hash');
        $before = $this->businessSnapshot();
        $this->withToken($token)->postJson($url, $input + ['auto_release' => true])->assertStatus(422);
        $this->assertSame($before, $this->businessSnapshot());
        $this->withToken($token)->postJson($url, $input + ['released_session_ids' => []])->assertCreated();
        $this->assertSame(12000, (int) Payment::sum('Amount'));
    }

    public function test_history_tenant_guards_surround_source_and_target_membership(): void
    {
        [$source, $targets, $input, , $campus, $token] = $this->context();
        $id = $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/existing-credit-allocations", $input)
            ->assertCreated()->json('allocation_group.id');
        $group = Group::findOrFail($id);
        $other = Student::create(['name' => 'isolated unrelated', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        foreach ([['student_id' => $other->id], ['campus_id' => Campus::factory()->create()->id]] as $n => $foreign) {
            // Historical evidence must fail closed even if an imported membership link is inconsistent.
            $copy = $group->replicate();
            $copy->forceFill($foreign + ['operation_key' => hash('sha256', "isolated-foreign-group-{$n}")])->save();
            $child = SubjectCreditTransfer::where('allocation_group_id', $id)->orderBy('id')->firstOrFail()->replicate();
            $child->forceFill(['allocation_group_id' => $copy->id, 'operation_key' => hash('sha256', "isolated-foreign-child-{$n}")])->save();
        }
        foreach ([$source->ID, array_key_first($targets)] as $courseId) {
            $this->withToken($token)->getJson("/api/v1/student-classes/{$courseId}/existing-credit-allocations")
                ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $id);
        }
    }

    public function test_history_is_bounded_and_does_not_follow_another_students_source(): void
    {
        [$source, , $input, , $campus, $token] = $this->context();
        $url = "/api/v1/student-classes/{$source->ID}/existing-credit-allocations";
        $id = $this->withToken($token)->postJson($url, $input)->assertCreated()->json('allocation_group.id');
        $first = Group::findOrFail($id);
        for ($n = 0; $n < 20; $n++) {
            $copy = $first->replicate(); $copy->operation_key = hash('sha256', "isolated-group-page-{$n}"); $copy->save();
        }
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('last_page', 2);
        $this->withToken($token)->getJson("{$url}?page=2")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->withToken($token)->getJson("{$url}?page=0")->assertStatus(422);
        $student = Student::create(['name' => 'isolated other student', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $other = $source->fresh()->replicate(); $other->StudentID = $student->id; $other->save();
        $before = $this->businessSnapshot();
        $this->withToken($token)->getJson("/api/v1/student-classes/{$other->ID}/existing-credit-allocations")
            ->assertOk()->assertJsonPath('total', 0);
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_super_admin_exception_still_rechecks_the_same_student_and_uses_the_authenticated_actor(): void
    {
        [$source, , $input] = $this->context();
        $user = User::create(['LoginName' => uniqid('allocation-owner-'), 'Name' => 'isolated owner', 'PSW' => 'test-only', 'type' => 'S', 'phone' => '0900000000']);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $id = $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/existing-credit-allocations", $input)
            ->assertCreated()->json('allocation_group.id');
        $this->assertDatabaseHas('existing_course_credit_allocation_groups', ['id' => $id, 'created_by' => $user->id]);
        $this->assertDatabaseCount('contract_subject_credit_transfers', 2);
    }
}
