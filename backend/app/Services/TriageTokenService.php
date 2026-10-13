<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Read-only AI triage tokens (config/triage_tokens.php).
 *
 * Sanctum's personal_access_tokens row stores only sha256(secret); the
 * plaintext "<id>|<prefix><random>" is returned once by create().
 * Expiry is created_at + ttl_days, the same model Sanctum v2 uses.
 */
final class TriageTokenService
{
    public static function ability(): string
    {
        return (string) config('triage_tokens.ability');
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

    public static function expiresAt(PersonalAccessToken $pat): Carbon
    {
        return Carbon::parse($pat->getAttribute('created_at'))->addDays((int) config('triage_tokens.ttl_days'));
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

        return $owner instanceof User && $owner->type === 'S';
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
