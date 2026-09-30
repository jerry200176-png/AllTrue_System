<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthUnifiedLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'shared-pass';

    private function makeUser(string $type, string $loginName = 'dual@example.com', string $password = self::PASSWORD): User
    {
        static $phone = 900200000;
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => 'Unified '.$type.$phone,
            'PSW' => password_hash($password, PASSWORD_DEFAULT),
            'type' => $type,
            'phone' => $phone++,
        ]);
        UserCampus::create(['UserID' => $user->id, 'CampusID' => 1, 'Admin' => 0, 'Approved' => true]);

        return $user;
    }

    /** @return array{0: string, 1: array<int, array>} */
    private function startChoice(): array
    {
        $res = $this->postJson('/api/v1/auth/login', ['account' => 'dual@example.com', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.requires_account_choice', true);

        return [$res->json('data.choice_token'), $res->json('data.choices')];
    }

    private function choiceIdFor(array $choices, string $label): string
    {
        return collect($choices)->firstWhere('role_label', $label)['choice_id'];
    }

    public function test_single_match_logs_in_without_role(): void
    {
        $teacher = $this->makeUser('T', 'solo@example.com');

        $this->postJson('/api/v1/auth/login', ['account' => 'solo@example.com', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.session.user.id', $teacher->id)
            ->assertJsonPath('data.session.user.role', 'teacher');
    }

    public function test_wrong_password_and_unknown_account_share_generic_error(): void
    {
        $this->makeUser('T');
        $this->makeUser('D');

        $this->postJson('/api/v1/auth/login', ['account' => 'dual@example.com', 'password' => 'nope'])
            ->assertStatus(401)->assertJsonPath('message', '帳號或密碼錯誤')->assertJsonMissingPath('data');
        $this->postJson('/api/v1/auth/login', ['account' => 'ghost@example.com', 'password' => 'nope'])
            ->assertStatus(401)->assertJsonPath('message', '帳號或密碼錯誤')->assertJsonMissingPath('data');
    }

    public function test_two_accounts_require_choice_and_choose_issues_token_once(): void
    {
        $teacher = $this->makeUser('T');
        $this->makeUser('D');
        $before = AuthToken::count();

        [$token, $choices] = $this->startChoice();
        $this->assertSame($before, AuthToken::count(), 'no session before choosing');
        $this->assertEqualsCanonicalizing(['老師帳號', '主任帳號（即將合併）'], array_column($choices, 'role_label'));
        foreach ($choices as $c) {
            $this->assertSame(['choice_id', 'role_label'], array_keys($c));
        }

        $choiceId = $this->choiceIdFor($choices, '老師帳號');
        $this->postJson('/api/v1/auth/login/choose', ['choice_token' => $token, 'choice_id' => $choiceId])
            ->assertOk()
            ->assertJsonPath('data.session.user.id', $teacher->id)
            ->assertJsonPath('data.session.user.role', 'teacher');
        $this->assertDatabaseHas('security_audit_events', ['event_type' => 'login.account_choice', 'outcome' => 'success']);

        // single use
        $this->postJson('/api/v1/auth/login/choose', ['choice_token' => $token, 'choice_id' => $choiceId])
            ->assertStatus(401);
    }

    public function test_choice_token_expires_after_five_minutes(): void
    {
        $this->makeUser('T');
        $this->makeUser('D');
        [$token, $choices] = $this->startChoice();

        $this->travel(6)->minutes();

        $this->postJson('/api/v1/auth/login/choose', [
            'choice_token' => $token,
            'choice_id' => $choices[0]['choice_id'],
        ])->assertStatus(401);
    }

    public function test_choice_is_bound_to_candidates_and_wrong_choice_burns_token(): void
    {
        $this->makeUser('T');
        $this->makeUser('D');
        $stranger = $this->makeUser('D', 'other@example.com');
        [$token, $choices] = $this->startChoice();

        foreach ([(string) $stranger->id, 'forged'] as $bad) {
            $this->postJson('/api/v1/auth/login/choose', ['choice_token' => 'x'.$bad, 'choice_id' => $bad])
                ->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login/choose', ['choice_token' => $token, 'choice_id' => (string) $stranger->id])
            ->assertStatus(401);
        // token consumed by the failed attempt
        $this->postJson('/api/v1/auth/login/choose', ['choice_token' => $token, 'choice_id' => $choices[0]['choice_id']])
            ->assertStatus(401);
        $this->assertSame(0, AuthToken::where('user_id', $stranger->id)->count());
    }

    public function test_legacy_role_param_keeps_working_and_never_prompts(): void
    {
        $teacher = $this->makeUser('T');
        $director = $this->makeUser('D');

        $this->postJson('/api/v1/auth/login', ['account' => 'dual@example.com', 'password' => self::PASSWORD, 'role' => 'teacher'])
            ->assertOk()->assertJsonPath('data.session.user.id', $teacher->id);
        $this->postJson('/api/v1/auth/login', ['account' => 'dual@example.com', 'password' => self::PASSWORD, 'role' => 'director'])
            ->assertOk()->assertJsonPath('data.session.user.id', $director->id);
    }

    public function test_suspended_account_is_not_offered_as_choice(): void
    {
        $teacher = $this->makeUser('T');
        $director = $this->makeUser('D');
        $director->forceFill(['status' => 'suspended'])->save();

        $this->postJson('/api/v1/auth/login', ['account' => 'dual@example.com', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('data.session.user.id', $teacher->id);
    }

    public function test_throttle_applies_to_login_and_choose(): void
    {
        $this->makeUser('T');
        $this->makeUser('D');

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', ['account' => 'dual@example.com', 'password' => 'bad'])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login', ['account' => 'dual@example.com', 'password' => 'bad'])->assertStatus(429);
        $this->postJson('/api/v1/auth/login', ['account' => 'dual@example.com', 'password' => self::PASSWORD])->assertStatus(429);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login/choose', ['choice_token' => 'bad', 'choice_id' => 'bad'])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login/choose', ['choice_token' => 'bad', 'choice_id' => 'bad'])->assertStatus(429);
    }
}
