<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\ParentBindingAttempt;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentLineBinding;
use App\Services\ParentBinding\ParentBindingClassifier;
use App\Support\ParentBinding\ParentBindingCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** PB-00 (#1436): contract, defaults, LINE/Portal observation, parity, fail-open, PII, ops. */
class ParentBindingObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['parent_binding.observability_enabled' => true, 'parent_binding.timezone' => 'Asia/Taipei']);
        $this->withoutMiddleware(\App\Http\Middleware\ThrottleRequestsByIp::class);
    }

    private function student(int $cid, string $name, ?string $phone, ?string $parent = null): Student
    {
        return Student::create([
            'name' => $name, 'CampusID' => $cid, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(),
            'Notify_Token' => '', 'Phone' => $phone, 'parent_phone' => $parent, 'status' => 'active',
        ]);
    }

    private function line(Campus $campus, string $uid, string $text): \Illuminate\Testing\TestResponse
    {
        DB::table('Campus')->where('id', $campus->id)->update(['messaging_channel_secret' => 'obs', 'messaging_channel_token' => 'tok']);
        $body = json_encode(['events' => [['type' => 'message', 'source' => ['userId' => $uid], 'message' => ['type' => 'text', 'text' => $text], 'replyToken' => 'rt']]]);

        return $this->call('POST', "/api/v1/line/webhook/{$campus->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_LINE_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, 'obs', true)),
        ], $body);
    }

    private function reason(): ?string
    {
        return ParentBindingAttempt::query()->latest('id')->value('reason_code');
    }

    public function test_contract_table_classifier_and_line_portal_ops(): void
    {
        $this->assertSame(ParentBindingCodes::pb00Reasons(), [
            'STUDENT_NOT_FOUND', 'CONTACT_PHONE_MISSING', 'PHONE_MISMATCH', 'AMBIGUOUS_MATCH',
            'CAMPUS_MISMATCH', 'ALREADY_BOUND', 'INVALID_INPUT', 'AUTHORIZATION_DENIED', 'INTERNAL_ERROR',
        ]);
        $this->assertTrue(Schema::hasTable('parent_binding_attempts'));
        $cfg = file_get_contents(config_path('parent_binding.php'));
        $this->assertMatchesRegularExpression("/PARENT_BINDING_OBSERVABILITY'\\s*,\\s*false/", $cfg);
        $this->assertMatchesRegularExpression("/PARENT_BINDING_PHONE_HMAC_KEY'\\s*,\\s*''/", $cfg);
        $this->assertStringNotContainsString('APP_KEY', $cfg);
        config(['parent_binding.phone_fingerprint_key' => '']);
        $this->assertNull(ParentBindingCodes::phoneFingerprint('0912345678'));
        config(['parent_binding.phone_fingerprint_key' => 'ded-a']);
        $fp = ParentBindingCodes::phoneFingerprint('0912345678');
        $this->assertSame(hash_hmac('sha256', 'parent-binding-phone-v1|0912345678', 'ded-a'), $fp);
        $this->assertStringNotContainsString('0912345678', (string) $fp);
        config(['parent_binding.phone_fingerprint_key' => 'ded-b']);
        $this->assertNotSame($fp, ParentBindingCodes::phoneFingerprint('0912345678'));

        $campus = Campus::factory()->create();
        $empty = $this->student($campus->id, '空', '', '');
        $this->assertSame('CONTACT_PHONE_MISSING', (new ParentBindingClassifier())->classifyLineStudentId($empty, '0912345678', (int) $campus->id)['reasonCode']);
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $uid = 'U' . str_repeat('a', 32);

        // default-off / explicit false ⇒ 0 writes; explicit true ⇒ writes
        config(['parent_binding.observability_enabled' => false]);
        $s = $this->student($campus->id, '成功', null, '0912345678');
        $this->line($campus, $uid, '綁定 成功 0912345678')->assertOk();
        $this->assertTrue(StudentLineBinding::where(['student_id' => $s->id, 'line_user_id' => $uid])->whereNotNull('verified_at')->exists());
        $this->assertSame(0, ParentBindingAttempt::count());
        config(['parent_binding.observability_enabled' => true]);
        $ok2 = $this->student($campus->id, '成二', null, '0912345678');
        $this->line($campus, 'U' . str_repeat('e', 32), '綁定 成二 0912345678')->assertOk();
        $this->assertSame('success', ParentBindingAttempt::query()->latest('id')->value('outcome'));
        $this->assertTrue(StudentLineBinding::where('student_id', $ok2->id)->exists());

        foreach ([['綁定 無此人 0912345678', 'STUDENT_NOT_FOUND'], ['綁定 空 0912345678', 'CONTACT_PHONE_MISSING']] as [$t, $c]) {
            $this->line($campus, $uid, $t)->assertOk();
            $this->assertSame($c, $this->reason());
        }
        $this->student($campus->id, '錯', null, '0987654321');
        $this->line($campus, $uid, '綁定 錯 0912345678')->assertOk();
        $this->assertSame('PHONE_MISMATCH', $this->reason());
        $this->line($campus, $uid, '綁定 x +++')->assertOk();
        $this->assertSame('INVALID_INPUT', $this->reason());
        $idS = $this->student($campus->id, '代號', null, '0911111111');
        $this->line($campus, $uid, "綁定 {$idS->id} 0911111111")->assertOk();
        $this->line($campus, $uid, "綁定 {$idS->id} 0911111111")->assertOk();
        $this->assertSame(['noop', 'ALREADY_BOUND'], [ParentBindingAttempt::query()->latest('id')->value('outcome'), $this->reason()]);
        ParentBindingAttempt::creating(fn () => throw new \RuntimeException('db 0912345678'));
        $this->student($campus->id, '開', null, '0933333333');
        $this->line($campus, 'U' . str_repeat('c', 32), '綁定 開 0933333333')->assertOk();
        $this->assertTrue(StudentLineBinding::where('line_user_id', 'U' . str_repeat('c', 32))->exists());
        ParentBindingAttempt::flushEventListeners();

        config(['parent_binding.store_phone_fingerprint' => true, 'parent_binding.phone_fingerprint_key' => '']);
        $this->student($campus->id, '無鍵', null, '0955555555');
        $this->line($campus, 'U' . str_repeat('f', 32), '綁定 無鍵 0955555555')->assertOk();
        $this->assertNull(ParentBindingAttempt::query()->latest('id')->value('phone_fingerprint'));
        config(['parent_binding.store_phone_fingerprint' => false, 'parent_binding.phone_fingerprint_key' => 'pii']);
        $this->student($campus->id, '關指紋', null, '0966666666');
        $this->line($campus, 'U' . str_repeat('g', 32), '綁定 關指紋 0966666666')->assertOk();
        $this->assertNull(ParentBindingAttempt::query()->latest('id')->value('phone_fingerprint'));
        config(['parent_binding.store_phone_fingerprint' => true, 'parent_binding.phone_fingerprint_key' => 'pii']);
        $name = 'PII生';
        $this->student($campus->id, $name, null, '0912345678');
        $this->line($campus, 'U' . str_repeat('d', 32), "綁定 {$name} 0912-345-678")->assertOk();
        $row = ParentBindingAttempt::query()->latest('id')->first();
        $blob = json_encode($row->toArray());
        foreach (['0912345678', '0912-345-678', $name, 'U'.str_repeat('d', 32), 'rt', 'tok', 'pii'] as $raw) {
            $this->assertStringNotContainsString($raw, $blob);
        }
        $this->assertSame(hash_hmac('sha256', 'parent-binding-phone-v1|0912345678', 'pii'), $row->phone_fingerprint);

        $ok = $this->student($campus->id, '入口', null, '0944444444');
        $res = $this->postJson('/api/v1/parent/login', ['Name' => '入口', 'Phone' => '0944444444'])->assertOk();
        $this->assertSame($ok->id, $res->json('student.id'));
        $this->assertTrue(ParentSession::where('StudentID', $ok->id)->exists());
        foreach ([
            [[], 422, 'INVALID_INPUT'], [['Name' => 'x', 'Phone' => '---'], 422, 'INVALID_INPUT'],
            [['StudentID' => 999999, 'Phone' => '0912345678'], 404, 'STUDENT_NOT_FOUND'],
            [['StudentID' => $empty->id, 'Phone' => '0912345678'], 401, 'CONTACT_PHONE_MISSING'],
            [['Name' => '無', 'Phone' => '0912345678'], 404, 'STUDENT_NOT_FOUND'],
        ] as [$payload, $status, $code]) {
            $this->postJson('/api/v1/parent/login', $payload)->assertStatus($status);
            $this->assertSame($code, $this->reason());
        }
        $this->student($campus->id, '雙', null, '0911222333');
        $this->student($campus->id, '雙', null, '0911222333');
        $this->postJson('/api/v1/parent/login', ['Name' => '雙', 'Phone' => '0911222333'])->assertStatus(409);
        $this->assertSame('AMBIGUOUS_MATCH', $this->reason());

        $a = $this->student($campus->id, '兄', null, '0911000001');
        $b = $this->student($campus->id, '弟', null, '0911000002');
        $lu = 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
        foreach ([$a, $b] as $st) {
            StudentLineBinding::create(['student_id' => $st->id, 'line_user_id' => $lu, 'campus_id' => $campus->id, 'verified_at' => now(), 'verification_method' => 'contact_phone']);
        }
        $ids = array_column($this->postJson('/api/v1/parent/login', ['Name' => '兄', 'Phone' => '0911000001'])->json('students'), 'id');
        $this->assertTrue(in_array($a->id, $ids, true) && in_array($b->id, $ids, true));

        foreach ([['550e8400-e29b-41d4-a716-446655440000', 'line', 'failure', 'PHONE_MISMATCH'], ['550e8400-e29b-41d4-a716-446655440001', 'parent_portal', 'success', null]] as [$cid, $ch, $out, $rc]) {
            ParentBindingAttempt::create(['correlation_id' => $cid, 'occurred_at' => now(), 'channel' => $ch, 'method' => 'name', 'outcome' => $out, 'reason_code' => $rc, 'campus_id' => $campus->id]);
        }
        $this->assertSame(0, Artisan::call('parent-binding:report', ['--days' => 7, '--format' => 'json']));
        $json = json_decode(Artisan::output(), true);
        $this->assertGreaterThanOrEqual(2, $json['total_attempts']);
        $this->assertArrayHasKey('PHONE_MISMATCH', $json['by_reason_code']);
        $this->assertSame(1, Artisan::call('parent-binding:report', ['--days' => 0, '--format' => 'json']));
        $this->assertSame(1, Artisan::call('parent-binding:report', ['--days' => 7, '--format' => 'table']));
        $this->student($campus->id, '缺', '', '');
        $this->assertSame(0, Artisan::call('parent-binding:report', ['--missing-contact' => true, '--campus' => $campus->id, '--format' => 'json']));
        $miss = json_decode(Artisan::output(), true);
        $this->assertSame('parent_phone → Phone (StudentContactPhone)', $miss['authoritative_rule']);
        $this->assertGreaterThanOrEqual(1, $miss['campuses'][0]['missing_contact_count']);
        $this->assertStringNotContainsString('缺', Artisan::output());
        Schema::rename('parent_binding_attempts', '_pba_bak');
        $this->student($campus->id, '無表', null, '0977777777');
        $this->line($campus, 'U' . str_repeat('h', 32), '綁定 無表 0977777777')->assertOk();
        $this->assertTrue(StudentLineBinding::where('line_user_id', 'U' . str_repeat('h', 32))->exists());
        $this->postJson('/api/v1/parent/login', ['Name' => '入口', 'Phone' => '0944444444'])->assertOk();
        Schema::rename('_pba_bak', 'parent_binding_attempts');
    }
}
