<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\ParentBindingAttempt;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentLineBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** PB-00 (#1436): LINE/Portal observation, parity, fail-open, PII, ops. */
class ParentBindingObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['parent_binding.observability_enabled' => true, 'parent_binding.timezone' => 'Asia/Taipei']);
    }

    private function student(int $campusId, string $name, ?string $phone, ?string $parent = null): Student
    {
        return Student::create([
            'name' => $name, 'CampusID' => $campusId, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '', 'Phone' => $phone, 'parent_phone' => $parent,
        ]);
    }

    private function lineBind(Campus $campus, string $lineUserId, string $text): \Illuminate\Testing\TestResponse
    {
        $secret = 'obs-secret';
        DB::table('Campus')->where('id', $campus->id)->update([
            'messaging_channel_secret' => $secret, 'messaging_channel_token' => 'obs-token',
        ]);
        $body = json_encode(['events' => [[
            'type' => 'message', 'source' => ['userId' => $lineUserId],
            'message' => ['type' => 'text', 'text' => $text], 'replyToken' => 'reply-obs',
        ]]]);
        $sig = base64_encode(hash_hmac('sha256', $body, $secret, true));

        return $this->call('POST', "/api/v1/line/webhook/{$campus->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_LINE_SIGNATURE' => $sig,
        ], $body);
    }

    private function latestReason(): ?string
    {
        return ParentBindingAttempt::query()->latest('id')->value('reason_code');
    }

    public function test_migration_table_present(): void
    {
        $this->assertTrue(Schema::hasTable('parent_binding_attempts'));
        foreach (['correlation_id', 'occurred_at', 'channel', 'method', 'outcome', 'reason_code', 'campus_id', 'student_id'] as $col) {
            $this->assertTrue(Schema::hasColumn('parent_binding_attempts', $col));
        }
    }

    public function test_line_success_parity_failures_noop_flag_failopen_pii(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campus = Campus::factory()->create();
        $uid = 'U' . str_repeat('a', 32);
        $s = $this->student($campus->id, '觀測成功', null, '0912345678');

        $this->lineBind($campus, $uid, '綁定 觀測成功 0912345678')->assertOk();
        $this->assertTrue(StudentLineBinding::where('student_id', $s->id)->where('line_user_id', $uid)->whereNotNull('verified_at')->exists());
        $row = ParentBindingAttempt::query()->latest('id')->first();
        $this->assertSame(['line', 'name', 'success'], [$row->channel, $row->method, $row->outcome]);
        $this->assertNull($row->reason_code);

        $this->lineBind($campus, $uid, '綁定 不存在的人 0912345678')->assertOk();
        $this->assertSame('STUDENT_NOT_FOUND', $this->latestReason());
        $this->student($campus->id, '缺聯', '', '');
        $this->lineBind($campus, $uid, '綁定 缺聯 0912345678')->assertOk();
        $this->assertSame('CONTACT_PHONE_MISSING', $this->latestReason());
        $this->student($campus->id, '錯機', null, '0987654321');
        $this->lineBind($campus, $uid, '綁定 錯機 0912345678')->assertOk();
        $this->assertSame('PHONE_MISMATCH', $this->latestReason());
        $this->lineBind($campus, $uid, '綁定 任意 +++')->assertOk();
        $this->assertSame('INVALID_INPUT', $this->latestReason());

        $this->lineBind($campus, $uid, '綁定 999999 0912345678')->assertOk();
        $this->assertSame('STUDENT_NOT_FOUND', $this->latestReason());
        $byId = $this->student($campus->id, '代號生', null, '0911111111');
        $this->lineBind($campus, $uid, "綁定 {$byId->id} 0911111111")->assertOk();
        $this->assertSame('success', ParentBindingAttempt::query()->latest('id')->value('outcome'));
        $this->lineBind($campus, $uid, "綁定 {$byId->id} 0911111111")->assertOk();
        $noop = ParentBindingAttempt::query()->latest('id')->first();
        $this->assertSame(['noop', 'ALREADY_BOUND'], [$noop->outcome, $noop->reason_code]);
        $emptyId = $this->student($campus->id, '空碼代號', '', '');
        $this->lineBind($campus, $uid, "綁定 {$emptyId->id} 0912345678")->assertOk();
        $this->assertSame('CONTACT_PHONE_MISSING', $this->latestReason());
        Http::assertSent(fn ($req) => ($req->data()['messages'][0]['text'] ?? '') === '手機號碼不符，請確認後重試。');

        config(['parent_binding.observability_enabled' => false]);
        ParentBindingAttempt::query()->delete();
        $this->student($campus->id, '旗標關', null, '0922222222');
        $this->lineBind($campus, 'U' . str_repeat('b', 32), '綁定 旗標關 0922222222')->assertOk();
        $this->assertSame(0, ParentBindingAttempt::count());
        $this->assertTrue(StudentLineBinding::where('line_user_id', 'U' . str_repeat('b', 32))->exists());

        config(['parent_binding.observability_enabled' => true]);
        ParentBindingAttempt::creating(fn () => throw new \RuntimeException('telemetry 0912345678'));
        $this->student($campus->id, '失敗開放', null, '0933333333');
        $this->lineBind($campus, 'U' . str_repeat('c', 32), '綁定 失敗開放 0933333333')->assertOk();
        $this->assertTrue(StudentLineBinding::where('line_user_id', 'U' . str_repeat('c', 32))->exists());
        $this->lineBind($campus, 'U' . str_repeat('c', 32), '綁定 不存在某某 0912345678')->assertOk();

        ParentBindingAttempt::flushEventListeners();
        config(['parent_binding.store_phone_fingerprint' => true, 'parent_binding.phone_fingerprint_key' => 'pii-k']);
        $name = 'PII測試生';
        $this->student($campus->id, $name, null, '0912345678');
        $this->lineBind($campus, 'U' . str_repeat('d', 32), "綁定 {$name} 0912-345-678")->assertOk();
        $blob = json_encode(ParentBindingAttempt::query()->latest('id')->first()->toArray());
        foreach (['0912345678', '+886912345678', '0912-345-678', $name, 'U' . str_repeat('d', 32), 'reply-obs', 'obs-token'] as $raw) {
            $this->assertStringNotContainsString($raw, $blob);
        }
    }

    public function test_portal_success_parity_failure_matrix_siblings_flag_failopen_pii(): void
    {
        $campus = Campus::factory()->create();
        $ok = $this->student($campus->id, '入口成功', null, '0912345678');
        $res = $this->postJson('/api/v1/parent/login', ['Name' => '入口成功', 'Phone' => '0912345678']);
        $res->assertOk();
        $this->assertSame($ok->id, $res->json('student.id'));
        $this->assertNotEmpty($res->json('token'));
        $this->assertTrue(ParentSession::where('StudentID', $ok->id)->exists());
        $this->assertSame('success', ParentBindingAttempt::query()->latest('id')->value('outcome'));

        $this->postJson('/api/v1/parent/login', [])->assertStatus(422);
        $this->assertSame('INVALID_INPUT', $this->latestReason());
        $this->postJson('/api/v1/parent/login', ['Name' => 'x', 'Phone' => '---'])->assertStatus(422);
        $this->assertSame('INVALID_INPUT', $this->latestReason());
        $this->postJson('/api/v1/parent/login', ['StudentID' => 999999, 'Phone' => '0912345678'])->assertStatus(404);
        $this->assertSame('STUDENT_NOT_FOUND', $this->latestReason());
        $empty = $this->student($campus->id, '缺碼', '', '');
        $this->postJson('/api/v1/parent/login', ['StudentID' => $empty->id, 'Phone' => '0912345678'])
            ->assertStatus(401)->assertJson(['message' => '此學生尚未設定聯絡手機，請聯繫分校補登後再登入']);
        $this->assertSame('CONTACT_PHONE_MISSING', $this->latestReason());
        $wrong = $this->student($campus->id, '錯碼', null, '0987654321');
        $this->postJson('/api/v1/parent/login', ['StudentID' => $wrong->id, 'Phone' => '0912345678'])->assertStatus(404);
        $this->assertSame('PHONE_MISMATCH', $this->latestReason());
        $this->postJson('/api/v1/parent/login', ['Name' => '完全沒有', 'Phone' => '0912345678'])->assertStatus(404);
        $this->assertSame('STUDENT_NOT_FOUND', $this->latestReason());
        $this->student($campus->id, '有名錯碼', null, '0987654321');
        $this->postJson('/api/v1/parent/login', ['Name' => '有名錯碼', 'Phone' => '0912345678'])->assertStatus(404);
        $this->assertSame('PHONE_MISMATCH', $this->latestReason());
        $this->student($campus->id, '雙生', null, '0911222333');
        $this->student($campus->id, '雙生', null, '0911222333');
        $this->postJson('/api/v1/parent/login', ['Name' => '雙生', 'Phone' => '0911222333'])
            ->assertStatus(409)->assertJson(['message' => '找到多筆相符資料，請改以 LINE 綁定或提供學生代號登入']);
        $this->assertSame('AMBIGUOUS_MATCH', $this->latestReason());

        $a = $this->student($campus->id, '兄長', null, '0911000001');
        $b = $this->student($campus->id, '弟', null, '0911000002');
        $line = 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
        foreach ([$a, $b] as $st) {
            StudentLineBinding::create([
                'student_id' => $st->id, 'line_user_id' => $line, 'campus_id' => $campus->id,
                'verified_at' => now(), 'verification_method' => 'contact_phone',
            ]);
        }
        $sib = $this->postJson('/api/v1/parent/login', ['Name' => '兄長', 'Phone' => '0911000001'])->assertOk();
        $ids = array_column($sib->json('students'), 'id');
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);

        config(['parent_binding.observability_enabled' => false]);
        ParentBindingAttempt::query()->delete();
        $this->student($campus->id, '旗標關入口', null, '0944444444');
        $this->postJson('/api/v1/parent/login', ['Name' => '旗標關入口', 'Phone' => '0944444444'])->assertOk();
        $this->assertSame(0, ParentBindingAttempt::count());
        config(['parent_binding.observability_enabled' => true]);
        ParentBindingAttempt::creating(fn () => throw new \RuntimeException('portal 0912345678'));
        $this->student($campus->id, '入口失敗開放', null, '0955555555');
        $this->postJson('/api/v1/parent/login', ['Name' => '入口失敗開放', 'Phone' => '0955555555'])->assertOk()->assertJsonStructure(['token']);
        ParentBindingAttempt::flushEventListeners();

        $name = '個資防漏';
        $this->student($campus->id, $name, '0912-345-678', null);
        $this->postJson('/api/v1/parent/login', ['Name' => $name, 'Phone' => '0912-345-678'])->assertOk();
        $blob = json_encode(ParentBindingAttempt::query()->latest('id')->first()->toArray());
        foreach (['0912345678', '+886912345678', '0912-345-678', $name] as $raw) {
            $this->assertStringNotContainsString($raw, $blob);
        }
    }

    public function test_ops_report_and_missing_contact(): void
    {
        $a = Campus::factory()->create(['name' => '甲校']);
        $b = Campus::factory()->create();
        ParentBindingAttempt::create([
            'correlation_id' => '550e8400-e29b-41d4-a716-446655440000', 'occurred_at' => now(),
            'channel' => 'line', 'method' => 'name', 'outcome' => 'failure', 'reason_code' => 'PHONE_MISMATCH', 'campus_id' => $a->id,
        ]);
        ParentBindingAttempt::create([
            'correlation_id' => '550e8400-e29b-41d4-a716-446655440001', 'occurred_at' => now(),
            'channel' => 'parent_portal', 'method' => 'name', 'outcome' => 'success', 'campus_id' => $a->id, 'student_id' => 1,
        ]);
        ParentBindingAttempt::create([
            'correlation_id' => '550e8400-e29b-41d4-a716-446655440002', 'occurred_at' => now(),
            'channel' => 'line', 'method' => 'name', 'outcome' => 'failure', 'reason_code' => 'STUDENT_NOT_FOUND', 'campus_id' => $b->id,
        ]);
        $this->assertSame(0, Artisan::call('parent-binding:report', ['--days' => 7, '--format' => 'json']));
        $json = json_decode(Artisan::output(), true);
        $this->assertSame(3, $json['total_attempts']);
        $this->assertSame(1, $json['success_count']);
        $this->assertArrayHasKey('PHONE_MISMATCH', $json['by_reason_code']);
        $this->assertStringNotContainsString('0912345678', Artisan::output());
        $this->assertSame(1, Artisan::call('parent-binding:report', ['--days' => 0, '--format' => 'json']));
        Artisan::call('parent-binding:report', ['--days' => 7, '--campus' => $a->id, '--format' => 'json']);
        $this->assertSame(2, json_decode(Artisan::output(), true)['total_attempts']);

        $this->student($a->id, '有手機', null, '0912345678')->forceFill(['status' => 'active'])->save();
        $this->student($a->id, '缺手機', '', '')->forceFill(['status' => 'active'])->save();
        $this->student($a->id, '畢業', '', '')->forceFill(['status' => 'graduated'])->save();
        $this->assertSame(0, Artisan::call('parent-binding:report', ['--missing-contact' => true, '--campus' => $a->id, '--format' => 'json']));
        $miss = json_decode(Artisan::output(), true);
        $this->assertSame('parent_phone → Phone (StudentContactPhone)', $miss['authoritative_rule']);
        $this->assertSame(2, $miss['campuses'][0]['active_student_count']);
        $this->assertSame(1, $miss['campuses'][0]['missing_contact_count']);
        $this->assertStringNotContainsString('缺手機', Artisan::output());
    }
}
