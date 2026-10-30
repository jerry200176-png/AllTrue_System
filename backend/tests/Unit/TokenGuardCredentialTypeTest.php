<?php

namespace Tests\Unit;

use Illuminate\Auth\TokenGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/** #977 / GHSA-f9x3-7vq9-cph6: real dependency classes, synthetic credentials only. */
class TokenGuardCredentialTypeTest extends TestCase
{
    public function testNonStringQueryAndBodyCredentialsNeverReachTheProvider(): void
    {
        foreach ([null, false, true, 0, 1, -1, 1.5, [], [0], ['synthetic']] as $value) {
            foreach (['GET', 'POST'] as $method) {
                foreach ([false, true] as $hash) {
                    $provider = $this->createMock(UserProvider::class);
                    $provider->expects($this->never())->method('retrieveByCredentials');
                    $request = Request::create('/', $method, ['api_token' => $value]);
                    $guard = new TokenGuard($provider, $request, 'api_token', 'api_token', $hash);
                    $this->assertNull($guard->user());
                    $this->assertFalse($guard->validate(['api_token' => $value]));
                }
            }
        }
    }

    public function testValidateRejectsObjectsAndStringableObjectsWithoutCoercion(): void
    {
        foreach ([new \stdClass(), new class {
            public function __toString(): string { return 'synthetic-token'; }
        }] as $value) {
            $provider = $this->createMock(UserProvider::class);
            $provider->expects($this->never())->method('retrieveByCredentials');
            $guard = new TokenGuard($provider, Request::create('/'));
            $this->assertFalse($guard->validate(['api_token' => $value]));
        }
    }

    public function testValidStringsPreserveHashCustomStorageAndUserCaching(): void
    {
        foreach (['synthetic-token', '123'] as $token) {
            foreach ([false, true] as $hash) {
                $user = $this->createMock(Authenticatable::class);
                $provider = $this->createMock(UserProvider::class);
                $provider->expects($this->once())->method('retrieveByCredentials')
                    ->with(['custom_storage' => $hash ? hash('sha256', $token) : $token])
                    ->willReturn($user);
                $guard = new TokenGuard($provider, Request::create('/', 'GET', ['custom_input' => $token]), 'custom_input', 'custom_storage', $hash);
                $this->assertSame($user, $guard->user());
                $this->assertSame($user, $guard->user());
            }
        }
    }

    public function testValidateKeepsExistingStringCredentialSemantics(): void
    {
        $provider = $this->createMock(UserProvider::class);
        $provider->expects($this->once())->method('retrieveByCredentials')
            ->with(['custom_storage' => 'synthetic-token'])
            ->willReturn($this->createMock(Authenticatable::class));
        $guard = new TokenGuard($provider, Request::create('/'), 'custom_input', 'custom_storage', true);
        $this->assertTrue($guard->validate(['custom_input' => 'synthetic-token']));
    }

    public function testMalformedPriorityTokenDoesNotFallThroughToAnotherIdentitySource(): void
    {
        $provider = $this->createMock(UserProvider::class);
        $provider->expects($this->never())->method('retrieveByCredentials');
        $request = Request::create('/?api_token[0]=0', 'POST', ['api_token' => 'synthetic-body'], [], [], ['HTTP_AUTHORIZATION' => 'Bearer synthetic-header']);
        $this->assertNull((new TokenGuard($provider, $request))->user());
    }

    public function testEmptyAndZeroStringRetainExistingRejection(): void
    {
        foreach (['', '0'] as $token) {
            $provider = $this->createMock(UserProvider::class);
            $provider->expects($this->never())->method('retrieveByCredentials');
            $guard = new TokenGuard($provider, Request::create('/', 'GET', ['api_token' => $token]));
            $this->assertNull($guard->user());
            $this->assertFalse($guard->validate(['api_token' => $token]));
        }
    }
}
