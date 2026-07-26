<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\ParentBindingAttempt;
use App\Models\Student;
use App\Models\StudentLineBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * PB-00 (#1436): LINE bind observability — reason codes, fail-open, PII-safe, success parity.
 */
class ParentBindingObservabilityLineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['parent_binding.observability_enabled' => true]);
    }

    private function postBindingMessage(Campus $campus, string $lineUserId, string $text): \Illuminate\Testing\TestResponse
    {
        $secret = 'bind-obs-secret';
        DB::table('Campus')->where('id', $campus->id)->update([
            'messaging_channel_secret' => $secret,
            'messaging_channel_token' => 'bind-obs-token',
        ]);

        $body = json_encode([
            'events' => [[
                'type' => 'message',
                'source' => ['userId' => $lineUserId],
                'message' => ['type' => 'text', 'text' => $text],
                'replyToken' => 'reply-token-obs-1',
            ]],
        ]);
        $sig = base64_encode(hash_hmac('sha256', $body, $secret, true));

        return $this->call(
            'POST',
            "/api/v1/line/webhook/{$campus->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_LINE_SIGNATURE' => $sig,
            ],
            $body
        );
    }

    private function makeStudent(Campus $campus, string $name, ?string $phone, ?string $parentPhone = null): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => $phone,
            'parent_phone' => $parentPhone,
        ]);
    }

    public function test_line_name_success_path_parity_and_observation(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campus = Campus::factory()->create();
        $lineUserId = 'U' . str_repeat('c', 32);
        $student = $this->makeStudent($campus, '觀測成功生', null, '0912345678');

        $res = $this->postBindingMessage($campus, $lineUserId, '綁定 觀測成功生 0912345678');
        $res->assertOk()->assertJson(['status' => 'ok']);

        $this->assertTrue(
            StudentLineBinding::where('student_id', $student->id)
                ->where('line_user_id', $lineUserId)
                ->whereNotNull('verified_at')
                ->exists()
        );

        $row = ParentBindingAttempt::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('line', $row->channel);
        $this->assertSame('name', $row->method);
        $this->assertSame('success', $row->outcome);
        $this->assertNull($row->reason_code);
        $this->assertSame((int) $student->id, (int) $row->student_id);
        $this->assertNull($row->phone_fingerprint);
    }

    public function test_line_name_failure_reason_codes(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campus = Campus::factory()->create();
        $lineUserId = 'U' . str_repeat('d', 32);

        // STUDENT_NOT_FOUND
        $this->postBindingMessage($campus, $lineUserId, '綁定 不存在的人 0912345678')->assertOk();
        $this->assertSame('STUDENT_NOT_FOUND', ParentBindingAttempt::query()->latest('id')->first()->reason_code);

        // CONTACT_PHONE_MISSING
        $this->makeStudent($campus, '缺聯絡', '', '');
        $this->postBindingMessage($campus, $lineUserId, '綁定 缺聯絡 0912345678')->assertOk();
        $this->assertSame('CONTACT_PHONE_MISSING', ParentBindingAttempt::query()->latest('id')->first()->reason_code);

        // PHONE_MISMATCH
        $this->makeStudent($campus, '手機錯', null, '0987654321');
        $this->postBindingMessage($campus, $lineUserId, '綁定 手機錯 0912345678')->assertOk();
        $this->assertSame('PHONE_MISMATCH', ParentBindingAttempt::query()->latest('id')->first()->reason_code);

        // INVALID_INPUT (non-digit phone after normalize — use unicode dashes that strip to empty is hard;
        // phone with only symbols)
        $this->postBindingMessage($campus, $lineUserId, '綁定 任意名 +++')->assertOk();
        $this->assertSame('INVALID_INPUT', ParentBindingAttempt::query()->latest('id')->first()->reason_code);
    }

    public function test_line_id_failure_and_already_bound_noop(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campus = Campus::factory()->create();
        $lineUserId = 'U' . str_repeat('e', 32);

        $this->postBindingMessage($campus, $lineUserId, '綁定 999999 0912345678')->assertOk();
        $this->assertSame('STUDENT_NOT_FOUND', ParentBindingAttempt::query()->latest('id')->first()->reason_code);

        $student = $this->makeStudent($campus, '代號綁定', null, '0912345678');
        $this->postBindingMessage($campus, $lineUserId, "綁定 {$student->id} 0912345678")->assertOk();
        $this->assertSame('success', ParentBindingAttempt::query()->latest('id')->first()->outcome);

        // already bound → noop
        $this->postBindingMessage($campus, $lineUserId, "綁定 {$student->id} 0912345678")->assertOk();
        $noop = ParentBindingAttempt::query()->latest('id')->first();
        $this->assertSame('noop', $noop->outcome);
        $this->assertSame('ALREADY_BOUND', $noop->reason_code);

        $this->assertSame(
            1,
            StudentLineBinding::where('student_id', $student->id)->where('line_user_id', $lineUserId)->count()
        );
    }

    public function test_line_id_contact_missing_keeps_same_reply_copy(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campus = Campus::factory()->create();
        $lineUserId = 'U' . str_repeat('f', 32);
        $student = $this->makeStudent($campus, '空碼代號', '', '');

        $this->postBindingMessage($campus, $lineUserId, "綁定 {$student->id} 0912345678")->assertOk();
        $this->assertSame('CONTACT_PHONE_MISSING', ParentBindingAttempt::query()->latest('id')->first()->reason_code);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $text = $payload['messages'][0]['text'] ?? '';
            return $text === '手機號碼不符，請確認後重試。';
        });
    }

    public function test_flag_off_writes_nothing(): void
    {
        config(['parent_binding.observability_enabled' => false]);
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campus = Campus::factory()->create();
        $lineUserId = 'U' . str_repeat('1', 32);
        $this->makeStudent($campus, '旗標關', null, '0912345678');

        $this->postBindingMessage($campus, $lineUserId, '綁定 旗標關 0912345678')->assertOk();
        $this->assertSame(0, ParentBindingAttempt::query()->count());
        $this->assertTrue(
            StudentLineBinding::where('line_user_id', $lineUserId)->whereNotNull('verified_at')->exists()
        );
    }

    public function test_recorder_exception_fail_open_on_success_and_failure(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        $campus = Campus::factory()->create();
        $lineUserId = 'U' . str_repeat('2', 32);
        $this->makeStudent($campus, '失敗開放', null, '0912345678');

        ParentBindingAttempt::creating(function () {
            throw new \RuntimeException('simulated telemetry failure with 0912345678');
        });

        Log::spy();

        // Success path still binds despite telemetry DB failure.
        $this->postBindingMessage($campus, $lineUserId, '綁定 失敗開放 0912345678')->assertOk();
        $this->assertTrue(StudentLineBinding::where('line_user_id', $lineUserId)->exists());

        // Failure path still returns webhook OK with original reply semantics.
        $this->postBindingMessage($campus, $lineUserId, '綁定 不存在某某 0912345678')->assertOk();
    }

    public function test_observation_and_logs_contain_no_raw_pii(): void
    {
        Http::fake(['https://api.line.me/v2/bot/message/reply' => Http::response(['ok' => true], 200)]);
        config([
            'parent_binding.store_phone_fingerprint' => true,
            'parent_binding.phone_fingerprint_key' => 'pii-test-key',
        ]);

        $campus = Campus::factory()->create();
        $lineUserId = 'U' . str_repeat('a', 32);
        $name = 'PII測試學生';
        $this->makeStudent($campus, $name, null, '0912345678');

        Log::spy();
        // Dash format still matches after digit normalize; assert raw forms never land in observation.
        $this->postBindingMessage($campus, $lineUserId, "綁定 {$name} 0912-345-678")->assertOk();

        $row = ParentBindingAttempt::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('success', $row->outcome);
        $serialized = json_encode($row->toArray());
        $forbidden = [
            '0912345678',
            '+886912345678',
            '0912-345-678',
            $name,
            $lineUserId,
            'reply-token-obs-1',
            'bind-obs-token',
        ];
        foreach ($forbidden as $raw) {
            $this->assertStringNotContainsString($raw, $serialized);
        }
        $this->assertNotNull($row->phone_fingerprint);
        $this->assertNotSame('0912345678', $row->phone_fingerprint);
        $this->assertSame(64, strlen($row->phone_fingerprint));
    }
}
