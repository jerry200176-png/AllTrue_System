<?php

namespace Tests\Feature;

use App\Models\{Invoice, SubjectCreditTransfer};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SubjectCreditFixtures;
use Tests\TestCase;

/** #535: narrow history stays reachable from the new batch without leaking audit snapshots. */
class SubjectCreditHistoryTest extends TestCase
{
    use RefreshDatabase;
    use SubjectCreditFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_history_is_read_only_bounded_and_reachable_from_source_template_and_new_batch(): void
    {
        [$token, $source, $target] = $this->fixture();
        $url = "/api/v1/student-classes/{$source->ID}/subject-credit-transfer";
        $input = ['target_course_id' => $target->ID, 'sessions' => 6];
        $hash = $this->withToken($token)->postJson("{$url}/preview", $input)->assertOk()->json('state_hash');
        $record = $this->withToken($token)->postJson($url, $input + ['reason' => '同價跨科', 'idempotency_key' => 'history-isolated', 'state_hash' => $hash])->assertCreated()->json('transfer');
        $before = $this->businessSnapshot();
        $audit = SubjectCreditTransfer::first()->getAttributes();
        foreach ([$source->ID, $target->ID, $record['target_course_id']] as $id) {
            $r = $this->withToken($token)->getJson("/api/v1/student-classes/{$id}/subject-credit-transfer")->assertOk()
                ->assertJsonPath('total', 1)->assertJsonPath('per_page', 20)
                ->assertJsonPath('data.0.id', $record['id'])->assertJsonPath('data.0.reason', '同價跨科')
                ->assertJsonPath('data.0.moved_sessions', 6)->assertJsonPath('data.0.reversed', false);
            $this->assertArrayNotHasKey('source_before', $r->json('data.0'));
            $this->assertArrayNotHasKey('operation_key', $r->json('data.0'));
            $this->assertArrayNotHasKey('request_fingerprint', $r->json('data.0'));
        }
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSame($audit, SubjectCreditTransfer::first()->getAttributes());
        $this->withToken($token)->getJson("{$url}?page=0")->assertStatus(422);
        // Bounded pagination must not silently hide older operations behind the default page.
        $first = SubjectCreditTransfer::first();
        for ($i = 0; $i < 20; $i++) {
            $copy = $first->replicate();
            $copy->operation_key = hash('sha256', "isolated-history-page-{$i}");
            $copy->save();
        }
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('last_page', 2);
        $this->withToken($token)->getJson("{$url}?page=2")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $record['id']);
    }

    public function test_history_denies_teacher_other_campus_empty_scope_and_does_not_include_other_student(): void
    {
        [$token, $source] = $this->fixture();
        [, $other] = $this->fixture();
        $url = "/api/v1/student-classes/{$source->ID}/subject-credit-transfer";
        foreach ([$this->token(null), $this->token((int) $source->student->CampusID, false)] as $denied) {
            $this->withToken($denied)->getJson($url)->assertForbidden();
        }
        $this->withToken($token)->getJson("/api/v1/student-classes/{$other->ID}/subject-credit-transfer")->assertForbidden();
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseCount('contract_subject_credit_transfers', 0);
        $this->assertSame(4, Invoice::count());
    }
}
