<?php

namespace App\Services;

/**
 * CampusDomainResolver — deterministic domain → campus resolution.
 *
 * Replaces the legacy fuzzy host matching (`str_ends_with(...)` + `->first()`)
 * that silently routed `daan.lifenet.com.tw` to the wrong campus when two
 * campuses shared a URL (in-app #/parent LIFF mis-binding, 2026-06-27).
 *
 * CORE PRINCIPLE:
 *   - deterministic : EXACT host match only — zero fuzzy / suffix / contains.
 *   - observable    : ambiguity is reported as a distinct status (caller logs),
 *                     never silently resolved by picking the first row.
 *   - replayable    : pure function of (requestHost, campusRows) — no I/O, no
 *                     framework facades, no global state.
 *   - testable      : runnable standalone without Laravel/DB (see
 *                     CampusDomainResolverSimulatorTest).
 *
 * Resolution algorithm (strict):
 *   1. normalize the request host
 *   2. EXACT match against each campus's normalized URL host
 *   3. 0 matches  -> status 'none'      (NEVER fall back to a "nearest" campus)
 *   4. >1 matches -> status 'ambiguous' (NEVER pick first — caller must alert/block)
 *   5. 1 match    -> status 'ok'
 */
final class CampusDomainResolver
{
    /**
     * Normalize a host or URL to a bare, comparable host.
     * Accepts either a full URL ("https://daan.lifenet.com.tw/") or a bare host
     * ("daan.lifenet.com.tw"). Lowercases, drops port, strips a leading "www.".
     */
    public static function normalizeHost(?string $value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return '';
        }

        $candidate = str_contains($raw, '://') ? $raw : ('https://' . $raw);
        $host = parse_url($candidate, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * @param string $requestHost The incoming request host (bare or URL).
     * @param array<int,array{id:int|string,url:?string,liff_id?:?string}> $campuses
     * @return array{status:string,campus_id:?int,liff_id:?string,candidates:int[]}
     *         status ∈ {'ok','none','ambiguous'}.
     */
    public static function resolve(string $requestHost, array $campuses): array
    {
        $host = self::normalizeHost($requestHost);
        if ($host === '') {
            return self::result('none', null, null, []);
        }

        $matches = [];
        foreach ($campuses as $campus) {
            $campusHost = self::normalizeHost($campus['url'] ?? null);
            if ($campusHost === '') {
                continue;
            }
            // EXACT match only. No str_ends_with, no contains, no regex.
            if ($campusHost === $host) {
                $matches[] = $campus;
            }
        }

        if (count($matches) === 0) {
            return self::result('none', null, null, []);
        }

        $candidateIds = array_values(array_map(static fn ($m) => (int) $m['id'], $matches));

        if (count($matches) > 1) {
            // Ambiguous mapping (e.g. two campuses share a URL). Fail loud — never
            // resolve to an arbitrary campus. The caller surfaces/blocks this.
            return self::result('ambiguous', null, null, $candidateIds);
        }

        $match = $matches[0];

        return self::result('ok', (int) $match['id'], $match['liff_id'] ?? null, $candidateIds);
    }

    /**
     * @param int[] $candidates
     * @return array{status:string,campus_id:?int,liff_id:?string,candidates:int[]}
     */
    private static function result(string $status, ?int $campusId, ?string $liffId, array $candidates): array
    {
        return [
            'status'     => $status,
            'campus_id'  => $campusId,
            'liff_id'    => $liffId,
            'candidates' => $candidates,
        ];
    }
}
