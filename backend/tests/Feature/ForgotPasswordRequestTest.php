<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ForgotPasswordRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_creates_pending_request_with_generic_response(): void
    {
        $email = 'forgot-teacher@example.com';

        $user = User::create([
            'LoginName' => $email,
            'Name' => 'Forgot Teacher',
            'PSW' => password_hash('pass-1234', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => 900100002,
        ]);

        $this->postJson('/api/v1/auth/forgot-password', [
            'account' => $email,
            'role' => 'teacher',
            'note' => 'Please help reset',
        ])->assertOk()
            ->assertJsonPath('message', '已收到重設密碼申請，請聯繫主任或管理員協助處理');

        $this->assertDatabaseHas('password_reset_requests', [
            'user_id' => $user->id,
            'account_input' => $email,
            'role_input' => 'teacher',
            'status' => 'pending',
            'request_note' => 'Please help reset',
        ]);
    }

    public function test_forgot_password_deduplicates_recent_pending_requests_and_hides_account_existence(): void
    {
        $account = 'missing-user@example.com';

        $first = $this->postJson('/api/v1/auth/forgot-password', [
            'account' => $account,
            'role' => 'director',
            'note' => 'first',
        ]);
        $first->assertOk()
            ->assertJsonPath('message', '已收到重設密碼申請，請聯繫主任或管理員協助處理');

        $second = $this->postJson('/api/v1/auth/forgot-password', [
            'account' => $account,
            'role' => 'director',
            'note' => 'second',
        ]);
        $second->assertOk()
            ->assertJsonPath('message', '已收到重設密碼申請，請聯繫主任或管理員協助處理');

        $this->assertSame(1, DB::table('password_reset_requests')->count());
        $this->assertDatabaseHas('password_reset_requests', [
            'account_input' => $account,
            'role_input' => 'director',
            'status' => 'pending',
        ]);

        $this->travel(16)->minutes();

        $this->postJson('/api/v1/auth/forgot-password', [
            'account' => $account,
            'role' => 'director',
            'note' => 'third',
        ])->assertOk();

        $this->assertSame(2, DB::table('password_reset_requests')->count());
    }
}
