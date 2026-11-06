<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentIdentityAuditLog;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** #5012 item 3. Synthetic data only. */
class TelegramBindingReviewTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_TOKEN = 'review-secret-token';

    private function campus(): Campus
    {
        return Campus::factory()->create(['TelegramToken' => self::SECRET_TOKEN]);
    }

    private function student(int $campusId, string $name, array $o = []): Student
    {
        return Student::create(array_merge(['name' => $name, 'CampusID' => $campusId, 'ClassID' => 1, 'enable' => 1, 'TelegramID' => ''], $o));
    }

    private function login(string $type, int $campusId): array
    {
        $user = User::create(['LoginName' => 'tg-' . uniqid() . '@example.com', 'Name' => 'u', 'type' => $type, 'status' => 'active',
            'PSW' => password_hash('Password123', PASSWORD_DEFAULT), 'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false]);
        UserCampus::create(['UserID' => $user->id, 'CampusID' => $campusId, 'Admin' => 0, 'Approved' => true]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return [$user, ['Authorization' => "Bearer {$raw}", 'Accept' => 'application/json']];
    }

    public function test_director_sees_only_own_campus_with_names_and_no_token(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['first_name' => 'Parent', 'last_name' => 'One']])]);
        $own = $this->campus();
        $other = $this->campus();
        $this->student($own->id, 'Alpha Test', ['TelegramID' => '111', 'TelegramID2' => '222']);
        $this->student($own->id, 'Unbound Test');
        $this->student($other->id, 'Beta Test', ['TelegramID' => '333']);
        [, $h] = $this->login('D', $own->id);

        $res = $this->withHeaders($h)->getJson('/api/v1/telegram/bindings');
        $res->assertOk()->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.student_name', 'Alpha Test');
        $res->assertJsonPath('data.0.bindings.0', ['slot' => 0, 'telegram_name' => 'Parent One']);
        $res->assertJsonPath('data.0.bindings.1.slot', 2);
        $this->assertStringNotContainsString(self::SECRET_TOKEN, $res->getContent());
        $this->withHeaders($h)->getJson('/api/v1/telegram/bindings?branch_id=' . $other->id)->assertForbidden();
    }

    public function test_teacher_is_refused(): void
    {
        $c = $this->campus();
        [, $h] = $this->login('T', $c->id);
        $this->withHeaders($h)->getJson('/api/v1/telegram/bindings')->assertForbidden();
        $this->withHeaders($h)->postJson('/api/v1/telegram/bindings/unbind', ['student_id' => 1, 'slot' => 0])->assertForbidden();
    }

    public function test_get_chat_failure_gives_null(): void
    {
        Cache::flush();
        Http::fake(['api.telegram.org/*' => Http::response('boom', 500)]);
        $c = $this->campus();
        $this->student($c->id, 'Gamma Test', ['TelegramID' => '444']);
        [, $h] = $this->login('D', $c->id);

        $this->withHeaders($h)->getJson('/api/v1/telegram/bindings')->assertOk()->assertJsonPath('data.0.bindings.0.telegram_name', null);
    }

    public function test_unbind_clears_one_slot_and_audits(): void
    {
        $c = $this->campus();
        $s = $this->student($c->id, 'Delta Test', ['TelegramID' => '1', 'TelegramID1' => '2', 'TelegramID2' => '3']);
        [$user, $h] = $this->login('D', $c->id);

        $this->withHeaders($h)->postJson('/api/v1/telegram/bindings/unbind', ['student_id' => $s->id, 'slot' => 1])->assertOk();
        $s->refresh();
        $this->assertSame('1', (string) $s->TelegramID);
        $this->assertNull($s->TelegramID1);
        $this->assertSame('3', (string) $s->TelegramID2);
        $this->withHeaders($h)->postJson('/api/v1/telegram/bindings/unbind', ['student_id' => $s->id, 'slot' => 0])->assertOk();
        $this->assertSame('', (string) $s->refresh()->TelegramID);
        $log = StudentIdentityAuditLog::query()->where('action', 'telegram_unbind')->orderBy('id')->first();
        $this->assertSame([$s->id, $c->id, $user->id], [$log->student_id, $log->campus_id, $log->actor_user_id]);
        $this->assertSame(2, StudentIdentityAuditLog::query()->where('action', 'telegram_unbind')->count());
    }

    public function test_unbind_refuses_another_campus_student(): void
    {
        $own = $this->campus();
        $other = $this->campus();
        $s = $this->student($other->id, 'Eps Test', ['TelegramID' => '9']);
        [, $h] = $this->login('D', $own->id);

        $this->withHeaders($h)->postJson('/api/v1/telegram/bindings/unbind', ['student_id' => $s->id, 'slot' => 0])->assertNotFound();
        $this->assertSame('9', (string) $s->refresh()->TelegramID);
    }
}
