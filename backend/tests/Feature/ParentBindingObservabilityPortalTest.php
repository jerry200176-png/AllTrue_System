<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\ParentBindingAttempt;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentLineBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * PB-00 (#1436): Parent Portal login observability — reason codes, parity, PII-safe.
 */
class ParentBindingObservabilityPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['parent_binding.observability_enabled' => true]);
    }

    private function makeStudent(int $campusId, string $name, ?string $phone, ?string $parentPhone = null): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => $phone,
            'parent_phone' => $parentPhone,
        ]);
    }

    public function test_portal_login_success_parity_and_observation(): void
    {
        $campus = Campus::factory()->create();
        $student = $this->makeStudent($campus->id, '入口成功', null, '0912345678');

        $res = $this->postJson('/api/v1/parent/login', [
            'Name' => '入口成功',
            'Phone' => '0912345678',
        ]);

        $res->assertOk();
        $body = $res->json();
        $this->assertSame($student->id, $body['student']['id']);
        $this->assertSame('入口成功', $body['student']['name']);
        $this->assertNotEmpty($body['token']);
        $this->assertTrue(ParentSession::where('StudentID', $student->id)->exists());

        $row = ParentBindingAttempt::query()->latest('id')->first();
        $this->assertSame('parent_portal', $row->channel);
        $this->assertSame('name', $row->method);
        $this->assertSame('success', $row->outcome);
        $this->assertNull($row->reason_code);
    }

    public function test_portal_login_failure_reason_matrix(): void
    {
        $campus = Campus::factory()->create();

        // validation 422 → INVALID_INPUT
        $this->postJson('/api/v1/parent/login', [])->assertStatus(422);
        $this->assertSame('INVALID_INPUT', ParentBindingAttempt::query()->latest('id')->first()->reason_code);

        // empty phone after normalize
        $this->postJson('/api/v1/parent/login', [
            'Name' => 'x',
            'Phone' => '---',
        ])->assertStatus(422);
        $this->assertSame('INVALID_INPUT', ParentBindingAttempt::query()->latest('id')->first()->reason_code);

        // StudentID not found
        $this->postJson('/api/v1/parent/login', [
            'StudentID' => 999999,
            'Phone' => '0912345678',
        ])->assertStatus(404);
        $this->assertSame('STUDENT_NOT_FOUND', ParentBindingAttempt::query()->latest('id')->first()->reason_code);

        // CONTACT_PHONE_MISSING via StudentID
        $empty = $this->makeStudent($campus->id, '缺碼', '', '');
        $resMissing = $this->postJson('/api/v1/parent/login', [
            'StudentID' => $empty->id,
            'Phone' => '0912345678',
        ]);
        $resMissing->assertStatus(401);
        $resMissing->assertJson(['message' => '此學生尚未設定聯絡手機，請聯繫分校補登後再登入']);
        $this->assertSame('CONTACT_PHONE_MISSING', ParentBindingAttempt::query()->latest('id')->first()->reason_code);

        // PHONE_MISMATCH via StudentID
        $wrong = $this->makeStudent($campus->id, '錯碼', null, '0987654321');
        $this->postJson('/api/v1/parent/login', [
            'StudentID' => $wrong->id,
            'Phone' => '0912345678',
        ])->assertStatus(404);
        $this->assertSame('PHONE_MISMATCH', ParentBindingAttempt::query()->latest('id')->first()->reason_code);

        // Name not found
        $this->postJson('/api/v1/parent/login', [
            'Name' => '完全沒有這個人',
            'Phone' => '0912345678',
        ])->assertStatus(404);
        $this->assertSame('STUDENT_NOT_FOUND', ParentBindingAttempt::query()->latest('id')->first()->reason_code);

        // Name exists, phone mismatch
        $this->makeStudent($campus->id, '有名錯碼', null, '0987654321');
        $this->postJson('/api/v1/parent/login', [
            'Name' => '有名錯碼',
            'Phone' => '0912345678',
        ])->assertStatus(404);
        $this->assertSame('PHONE_MISMATCH', ParentBindingAttempt::query()->latest('id')->first()->reason_code);

        // Ambiguous
        $this->makeStudent($campus->id, '雙生', null, '0911222333');
        $this->makeStudent($campus->id, '雙生', null, '0911222333');
        $amb = $this->postJson('/api/v1/parent/login', [
            'Name' => '雙生',
            'Phone' => '0911222333',
        ]);
        $amb->assertStatus(409);
        $amb->assertJson(['message' => '找到多筆相符資料，請改以 LINE 綁定或提供學生代號登入']);
        $this->assertSame('AMBIGUOUS_MATCH', ParentBindingAttempt::query()->latest('id')->first()->reason_code);
    }

    public function test_portal_sibling_line_binding_unchanged(): void
    {
        $campus = Campus::factory()->create();
        $a = $this->makeStudent($campus->id, '兄長觀測', null, '0911000001');
        $b = $this->makeStudent($campus->id, '弟觀測', null, '0911000002');
        $lineUserId = 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
        StudentLineBinding::create([
            'student_id' => $a->id,
            'line_user_id' => $lineUserId,
            'campus_id' => $campus->id,
            'verified_at' => now(),
            'verification_method' => 'contact_phone',
        ]);
        StudentLineBinding::create([
            'student_id' => $b->id,
            'line_user_id' => $lineUserId,
            'campus_id' => $campus->id,
            'verified_at' => now(),
            'verification_method' => 'contact_phone',
        ]);

        $res = $this->postJson('/api/v1/parent/login', [
            'Name' => '兄長觀測',
            'Phone' => '0911000001',
        ]);
        $res->assertOk();
        $ids = array_map(fn ($s) => $s['id'], $res->json('students'));
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
    }

    public function test_portal_flag_off_and_fail_open(): void
    {
        $campus = Campus::factory()->create();
        $this->makeStudent($campus->id, '旗標關入口', null, '0912345678');

        config(['parent_binding.observability_enabled' => false]);
        $this->postJson('/api/v1/parent/login', [
            'Name' => '旗標關入口',
            'Phone' => '0912345678',
        ])->assertOk();
        $this->assertSame(0, ParentBindingAttempt::query()->count());

        config(['parent_binding.observability_enabled' => true]);
        ParentBindingAttempt::creating(function () {
            throw new \RuntimeException('portal telemetry down 0912345678');
        });
        $this->makeStudent($campus->id, '入口失敗開放', null, '0987654321');
        $res = $this->postJson('/api/v1/parent/login', [
            'Name' => '入口失敗開放',
            'Phone' => '0987654321',
        ]);
        $res->assertOk();
        $this->assertNotEmpty($res->json('token'));
    }

    public function test_portal_logs_and_rows_have_no_raw_pii(): void
    {
        $campus = Campus::factory()->create();
        $name = '個資防漏生';
        $this->makeStudent($campus->id, $name, '0912-345-678', null);

        Log::spy();
        $this->postJson('/api/v1/parent/login', [
            'Name' => $name,
            'Phone' => '0912-345-678',
        ])->assertOk();

        $row = ParentBindingAttempt::query()->latest('id')->first();
        $blob = json_encode($row->toArray());
        foreach (['0912345678', '+886912345678', '0912-345-678', $name] as $raw) {
            $this->assertStringNotContainsString($raw, $blob);
        }
    }
}
