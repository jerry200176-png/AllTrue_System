<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Read-only AI triage tokens: Sanctum row stores sha256 only. Expiry is fixed at issue (#4021): the
 * issuing ttl_days rides in `abilities` as "ttl_days:N" (Sanctum 2 has no expires_at column and the auth
 * table stays untouched), and the effective lifetime is min(issued, current config), so a later config
 * change can only shorten a token, never extend or revive one.
 */
final class TriageTokenService
{
    private const TTL_MARK = 'ttl_days:';

    /** Rows issued before the mark existed: capped at the 2026-10-13 launch default ttl_days. */
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
            'abilities' => [self::ability(), self::TTL_MARK . (int) config('triage_tokens.ttl_days')],
        ])->save();

        return ['token' => $pat->getKey() . '|' . $secret, 'record' => self::present($pat)];
    }

    /** @return list<array<string, mixed>> */
    public static function list(): array
    {
        return self::query()->orderByDesc('id')->get()
            ->filter(fn (PersonalAccessToken $pat) => self::hasOnlyTriageAbility($pat))
            ->map(fn (PersonalAccessToken $pat) => self::present($pat))
            ->values()->all();
    }

    public static function find(int $id): ?PersonalAccessToken
    {
        $pat = self::query()->whereKey($id)->first();

        return $pat instanceof PersonalAccessToken && self::hasOnlyTriageAbility($pat) ? $pat : null;
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

    public static function expiresAt(PersonalAccessToken $pat): Carbon
    {
        $days = min(self::issuedTtlDays($pat) ?? self::LEGACY_MAX_TTL_DAYS, (int) config('triage_tokens.ttl_days'));

        return Carbon::parse($pat->getAttribute('created_at'))->addDays($days);
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

    /** The abilities, minus at most one ttl mark, must be exactly the triage ability. */
    private static function hasOnlyTriageAbility(PersonalAccessToken $pat): bool
    {
        $abilities = $pat->getAttribute('abilities');
        if (!is_array($abilities)) {
            return false;
        }
        $rest = array_values(array_filter($abilities, fn ($a) => !self::isTtlMark($a)));

        return $rest === [self::ability()] && count($abilities) - count($rest) <= 1;
    }

    private static function isTtlMark(mixed $ability): bool
    {
        return is_string($ability) && preg_match('/^' . self::TTL_MARK . '\d{1,4}$/', $ability) === 1;
    }

    private static function issuedTtlDays(PersonalAccessToken $pat): ?int
    {
        foreach ((array) $pat->getAttribute('abilities') as $ability) {
            if (self::isTtlMark($ability)) {
                return (int) substr($ability, strlen(self::TTL_MARK));
            }
        }

        return null;
    }

    /** Coarse SQL narrowing (the ability is always written first); hasOnlyTriageAbility() is the real check. */
    private static function query(): \Illuminate\Database\Eloquent\Builder
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('abilities', 'like', '["' . self::ability() . '"%');
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
