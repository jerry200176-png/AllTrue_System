<?php

namespace Tests\Unit;

use App\Services\CampusDomainResolver;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic domain → campus routing (CampusDomainResolver).
 *
 * Regression for the 2026-06-27 parent-portal mis-binding: campus 13 (新莊中平)
 * and campus 15 (大安) both carried URL `https://daan.lifenet.com.tw`. The legacy
 * resolver used fuzzy `str_ends_with` matching + `->first()`, so `daan.lifenet.com.tw`
 * silently resolved to the lower-id campus (13) and served the wrong LIFF channel,
 * breaking LINE auto-login for every 大安 parent. The resolver now: matches EXACTLY,
 * returns 'ambiguous' (never picks first) when two campuses share a host, and never
 * falls back to a "nearest" campus.
 */
class CampusDomainResolverTest extends TestCase
{
    private function prod(): array
    {
        return [
            ['id' => 13, 'url' => '', 'liff_id' => '1657309280-RNowmJ3z'],
            ['id' => 15, 'url' => 'https://daan.lifenet.com.tw', 'liff_id' => '1660958962-veQZzPWJ'],
            ['id' => 3, 'url' => '', 'liff_id' => '2009814318-KfrRcGvn'],
        ];
    }

    public function test_exact_host_resolves_to_correct_campus(): void
    {
        $r = CampusDomainResolver::resolve('daan.lifenet.com.tw', $this->prod());
        $this->assertSame('ok', $r['status']);
        $this->assertSame(15, $r['campus_id']);
        $this->assertSame('1660958962-veQZzPWJ', $r['liff_id']);
    }

    public function test_www_and_case_are_normalized(): void
    {
        foreach (['www.daan.lifenet.com.tw', 'DAAN.LIFENET.COM.TW', 'https://daan.lifenet.com.tw/'] as $host) {
            $r = CampusDomainResolver::resolve($host, $this->prod());
            $this->assertSame(15, $r['campus_id'], "host {$host} should resolve to campus 15");
        }
    }

    public function test_duplicate_url_is_ambiguous_not_first_picked(): void
    {
        $dup = $this->prod();
        $dup[0]['url'] = 'https://daan.lifenet.com.tw'; // campus 13 also carries daan

        $r = CampusDomainResolver::resolve('daan.lifenet.com.tw', $dup);

        $this->assertSame('ambiguous', $r['status'], 'shared URL must be ambiguous, never silently resolved');
        $this->assertNull($r['campus_id'], 'ambiguous mapping must NOT pick the first (lower-id) campus');
        $this->assertEqualsCanonicalizing([13, 15], $r['candidates']);
    }

    public function test_unknown_domain_returns_none_no_fallback(): void
    {
        $r = CampusDomainResolver::resolve('random.example.com', $this->prod());
        $this->assertSame('none', $r['status']);
        $this->assertNull($r['campus_id']);
    }

    public function test_no_fuzzy_suffix_matching(): void
    {
        // Parent domain and foreign subdomains must NOT match (legacy str_ends_with bug).
        foreach (['lifenet.com.tw', 'evil.daan.lifenet.com.tw'] as $host) {
            $r = CampusDomainResolver::resolve($host, $this->prod());
            $this->assertSame('none', $r['status'], "host {$host} must not fuzzy-match");
        }
    }

    public function test_empty_request_host_returns_none(): void
    {
        $this->assertSame('none', CampusDomainResolver::resolve('', $this->prod())['status']);
    }

    /**
     * CRDE Phase 6 — cache-safety enforced as an invariant. The resolver must be a
     * pure read-through function of its arguments: no memoization or cross-call
     * state that could serve a stale mapping after the underlying data changes.
     */
    public function test_resolver_is_stateless_read_through(): void
    {
        $before = $this->prod(); // campus 15 owns daan
        $this->assertSame(15, CampusDomainResolver::resolve('daan.lifenet.com.tw', $before)['campus_id']);

        // Reassign the same host to a different campus set — a cached resolver would
        // wrongly keep returning 15; a pure one reflects the new input immediately.
        $after = [
            ['id' => 99, 'url' => 'https://daan.lifenet.com.tw', 'liff_id' => 'L99'],
        ];
        $this->assertSame(99, CampusDomainResolver::resolve('daan.lifenet.com.tw', $after)['campus_id']);

        // And repeated identical calls are stable (deterministic / replayable).
        $a = CampusDomainResolver::resolve('daan.lifenet.com.tw', $before);
        $b = CampusDomainResolver::resolve('daan.lifenet.com.tw', $before);
        $this->assertSame($a, $b);
    }
}
