<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Read-only AI triage tokens: Sanctum row stores sha256 only. Expiry is fixed at
 * issue (expires_at); a later ttl_days change can only shorten it, never extend it (#4021).
 */
final class TriageTokenService
{
    /** Cap for rows issued before expires_at existed (2026-10-13 launch default ttl_days). */
    private const LEGACY_MAX_TTL_DAYS = 90;

    public static function ability(): string
    {
        return (string) config('triage_tokens.ability');
    }

    /** Audit rows for refused or failed token requests (any token): 10/min per IP and 120/min overall, across routes; the response never changes. */
    public static function mayAuditDenial(?string $ip): bool
    {
        $keys = ['triage-unresolved-audit:' . hash('sha256', (string) $ip) => 10, 'triage-unresolved-audit:all' => 120];
        if (array_filter($keys, fn (int $max, string $key) => RateLimiter::tooManyAttempts($key, $max), ARRAY_FILTER_USE_BOTH) !== []) {
            return false;
        }
        array_walk($keys, fn (int $max, string $key) => RateLimiter::hit($key, 60));
        return true;
    }

    public static function looksLikeTriageToken(string $bearer): bool
    {
        $prefix = (string) config('triage_tokens.secret_prefix');

        return $prefix !== '' && str_contains($bearer, $prefix);
    }

    /** @return array{token: string, record: array<string, mixed>} */
    public static function create(User $creator, string $name): array
    {
        $secret = (string) config('triage_tokens.secret_prefix') . Str::random(40);
        $pat = new PersonalAccessToken();
        $pat->forceFill([
            'tokenable_type' => User::class,
            'tokenable_id' => $creator->getKey(),
            'name' => $name,
            'token' => hash('sha256', $secret),
            'abilities' => [self::ability()],
            'expires_at' => now()->addDays((int) config('triage_tokens.ttl_days')),
        ])->save();

        return ['token' => $pat->getKey() . '|' . $secret, 'record' => self::present($pat)];
    }

    /** @return list<array<string, mixed>> */
    public static function list(): array
    {
        return self::query()->orderByDesc('id')->get()
            ->map(fn (PersonalAccessToken $pat) => self::present($pat))
            ->values()->all();
    }

    public static function find(int $id): ?PersonalAccessToken
    {
        $pat = self::query()->whereKey($id)->first();

        return $pat instanceof PersonalAccessToken ? $pat : null;
    }

    /** Resolve "<id>|<secret>" to a triage token row (null when unknown or not a triage token). */
    public static function resolve(?string $bearer): ?PersonalAccessToken
    {
        if (!is_string($bearer) || !str_contains($bearer, '|')) {
            return null;
        }
        $pat = PersonalAccessToken::findToken($bearer);
        if (!$pat instanceof PersonalAccessToken || !self::hasOnlyTriageAbility($pat)) {
            return null;
        }

        return $pat;
    }

    /** min(expiry fixed at issue, created_at + current ttl_days): config can shorten a token, never lengthen it. */
    public static function expiresAt(PersonalAccessToken $pat): Carbon
    {
        $created = Carbon::parse($pat->getAttribute('created_at'));
        $stored = $pat->getAttribute('expires_at');
        $issued = $stored ? Carbon::parse($stored) : $created->copy()->addDays(self::LEGACY_MAX_TTL_DAYS);
        $current = $created->copy()->addDays((int) config('triage_tokens.ttl_days'));

        return $issued->lessThan($current) ? $issued : $current;
    }

    public static function isExpired(PersonalAccessToken $pat): bool
    {
        return self::expiresAt($pat)->isPast();
    }

    /** The token acts for its creator; it dies with the creator's super-admin role. */
    public static function ownerIsSuperAdmin(PersonalAccessToken $pat): bool
    {
        if ($pat->getAttribute('tokenable_type') !== User::class) {
            return false;
        }
        $owner = User::query()->find((int) $pat->getAttribute('tokenable_id'));

        return $owner instanceof User && $owner->type === 'S'
            && !in_array($owner->status, ['inactive', 'suspended'], true);
    }

    private static function hasOnlyTriageAbility(PersonalAccessToken $pat): bool
    {
        $abilities = $pat->getAttribute('abilities');

        return is_array($abilities) && array_values($abilities) === [self::ability()];
    }

    private static function query(): \Illuminate\Database\Eloquent\Builder
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('abilities', json_encode([self::ability()]));
    }

    /** Never includes the secret or its hash. @return array<string, mixed> */
    private static function present(PersonalAccessToken $pat): array
    {
        $lastUsed = $pat->getAttribute('last_used_at');

        return [
            'id' => (int) $pat->getKey(),
            'name' => (string) $pat->getAttribute('name'),
            'ability' => self::ability(),
            'created_by_user_id' => (int) $pat->getAttribute('tokenable_id'),
            'created_at' => Carbon::parse($pat->getAttribute('created_at'))->toIso8601String(),
            'last_used_at' => $lastUsed ? Carbon::parse($lastUsed)->toIso8601String() : null,
            'expires_at' => self::expiresAt($pat)->toIso8601String(),
            'expired' => self::isExpired($pat),
        ];
    }
}
