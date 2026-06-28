<?php

namespace Tests\Unit;

use App\Http\Controllers\AuthController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression for the production 500 on /auth/me + login:
 * avatarStoredPathForDisk() used '#' as the preg delimiter while '#' also
 * appears inside the [^?\s#] character class, so PHP closed the pattern early
 * and threw "preg_match(): Unknown modifier ']'" for any user whose AvatarUrl
 * is an absolute http(s) URL. The delimiter is now '~'.
 */
class AvatarStoredPathTest extends TestCase
{
    private function call(?string $stored): ?string
    {
        $m = new ReflectionMethod(AuthController::class, 'avatarStoredPathForDisk');
        $m->setAccessible(true);
        return $m->invoke(null, $stored);
    }

    public function test_absolute_url_resolves_without_preg_error(): void
    {
        $this->assertSame(
            'avatars/29/avatar.jpg',
            $this->call('https://daan.lifenet.com.tw/storage/avatars/29/avatar.jpg')
        );
    }

    public function test_absolute_url_strips_query_and_fragment(): void
    {
        $this->assertSame(
            'avatars/81/a.png',
            $this->call('https://daan.lifenet.com.tw/storage/avatars/81/a.png?v=123#frag')
        );
    }

    public function test_relative_storage_path(): void
    {
        $this->assertSame('avatars/5/x.jpg', $this->call('/storage/avatars/5/x.jpg'));
    }

    public function test_null_and_empty(): void
    {
        $this->assertNull($this->call(null));
        $this->assertNull($this->call(''));
    }

    public function test_non_storage_url_returns_null(): void
    {
        $this->assertNull($this->call('https://cdn.example.com/img/a.jpg'));
    }
}
