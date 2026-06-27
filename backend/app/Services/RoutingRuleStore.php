<?php

namespace App\Services;

use App\Models\CampusRoutingRule;
use App\Models\CampusRoutingVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * RoutingRuleStore — CRDE Phase 4 immutable, versioned routing config.
 *
 * Invariants:
 *   - Published versions are append-only snapshots; rules are never UPDATE-d in place.
 *   - Publishing a new version supersedes the previous one atomically.
 *   - A ruleset is validated (no duplicate / fuzzy / ambiguous domains) BEFORE it can
 *     be published — ambiguity is rejected at write time, not silently resolved at read.
 *   - Resolution against the active version is deterministic: EXACT host match first,
 *     then explicit subdomain wildcard; 0 → none, >1 → ambiguous (never first-pick).
 */
class RoutingRuleStore
{
    /** The currently published (active) version, or null if none. */
    public function activeVersion(): ?CampusRoutingVersion
    {
        return CampusRoutingVersion::where('status', 'published')
            ->orderByDesc('version')
            ->first();
    }

    /** Enabled rules of the active version, as plain arrays. */
    public function activeRules(): array
    {
        $version = $this->activeVersion();
        if (!$version) {
            return [];
        }

        return CampusRoutingRule::where('routing_version_id', $version->id)
            ->where('enabled', true)
            ->get()
            ->map(fn ($r) => [
                'domain'     => $r->domain,
                'campus_id'  => (int) $r->campus_id,
                'liff_id'    => $r->liff_id,
                'match_type' => $r->match_type,
                'priority'   => (int) $r->priority,
            ])
            ->all();
    }

    /**
     * Deterministic resolution against the active version.
     *
     * @return array{status:string,campus_id:?int,liff_id:?string,candidates:int[],version:?int}
     */
    public function resolve(string $requestHost): array
    {
        $version = $this->activeVersion();
        $rules = $this->activeRules();
        $host = CampusDomainResolver::normalizeHost($requestHost);

        $base = ['status' => 'none', 'campus_id' => null, 'liff_id' => null, 'candidates' => [], 'version' => $version?->version];
        if ($host === '' || $rules === []) {
            return $base;
        }

        // EXACT first; explicit subdomain wildcard (domain "*.x") only if no exact match.
        $exact = array_values(array_filter($rules, fn ($r) => ($r['match_type'] ?? 'exact') === 'exact' && $r['domain'] === $host));
        $matches = $exact;
        if ($matches === []) {
            $matches = array_values(array_filter($rules, function ($r) use ($host) {
                if (($r['match_type'] ?? '') !== 'wildcard' || !str_starts_with($r['domain'], '*.')) {
                    return false;
                }
                $suffix = substr($r['domain'], 1); // ".x.com"
                return str_ends_with($host, $suffix);
            }));
        }

        if ($matches === []) {
            return $base;
        }
        if (count($matches) > 1) {
            return array_merge($base, [
                'status'     => 'ambiguous',
                'candidates' => array_map(fn ($m) => (int) $m['campus_id'], $matches),
            ]);
        }

        $m = $matches[0];
        return [
            'status'     => 'ok',
            'campus_id'  => (int) $m['campus_id'],
            'liff_id'    => $m['liff_id'] ?? null,
            'candidates' => [(int) $m['campus_id']],
            'version'    => $version?->version,
        ];
    }

    /**
     * Validate a proposed ruleset. Returns the normalized rules or throws.
     *
     * @param array<int,array{domain:string,campus_id:int|string,match_type?:string,priority?:int,enabled?:bool,liff_id?:?string}> $rules
     * @return array<int,array<string,mixed>>
     */
    public function validate(array $rules): array
    {
        $seen = [];
        $normalized = [];
        foreach ($rules as $i => $rule) {
            $domain = CampusDomainResolver::normalizeHost($rule['domain'] ?? '');
            $matchType = $rule['match_type'] ?? 'exact';
            if ($matchType === 'wildcard') {
                // keep the leading "*." for wildcard rules; normalize the rest
                $raw = strtolower(trim((string) ($rule['domain'] ?? '')));
                $domain = str_starts_with($raw, '*.') ? '*.' . CampusDomainResolver::normalizeHost(substr($raw, 2)) : $domain;
            }
            if ($domain === '' || $domain === '*.') {
                throw new RuntimeException("rule #{$i}: empty/invalid domain");
            }
            if (!in_array($matchType, ['exact', 'wildcard'], true)) {
                throw new RuntimeException("rule #{$i}: invalid match_type '{$matchType}' (only exact|wildcard)");
            }
            $campusId = (int) ($rule['campus_id'] ?? 0);
            if ($campusId <= 0) {
                throw new RuntimeException("rule #{$i}: invalid campus_id");
            }
            if (isset($seen[$domain])) {
                throw new RuntimeException("ambiguous ruleset: domain '{$domain}' mapped to campus {$seen[$domain]} and {$campusId}");
            }
            $seen[$domain] = $campusId;
            $normalized[] = [
                'domain'     => $domain,
                'campus_id'  => $campusId,
                'match_type' => $matchType,
                'priority'   => (int) ($rule['priority'] ?? 100),
                'enabled'    => (bool) ($rule['enabled'] ?? true),
                'liff_id'    => $rule['liff_id'] ?? null,
            ];
        }
        return $normalized;
    }

    /**
     * Create a NEW version from a validated ruleset (never overwrites existing rows).
     * Optionally publish it (superseding the previous published version).
     */
    public function createVersion(array $rules, ?string $note = null, bool $publish = false): CampusRoutingVersion
    {
        $normalized = $this->validate($rules);

        return DB::transaction(function () use ($normalized, $note, $publish) {
            $nextVersion = (int) (CampusRoutingVersion::max('version') ?? 0) + 1;
            ksort($normalized);
            $checksum = hash('sha256', json_encode($normalized));

            $version = CampusRoutingVersion::create([
                'version'  => $nextVersion,
                'status'   => 'draft',
                'checksum' => $checksum,
                'note'     => $note,
            ]);

            foreach ($normalized as $r) {
                CampusRoutingRule::create(array_merge($r, ['routing_version_id' => $version->id]));
            }

            if ($publish) {
                $this->publish($version->id);
                $version->refresh();
            }

            return $version;
        });
    }

    /** Publish a version, atomically superseding the previously published one. */
    public function publish(int $versionId): CampusRoutingVersion
    {
        return DB::transaction(function () use ($versionId) {
            $version = CampusRoutingVersion::findOrFail($versionId);

            CampusRoutingVersion::where('status', 'published')
                ->where('id', '!=', $version->id)
                ->update(['status' => 'superseded']);

            $version->update(['status' => 'published', 'published_at' => now()]);

            return $version;
        });
    }
}
