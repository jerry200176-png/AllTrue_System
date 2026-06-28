<?php

/**
 * Campus Domain Resolver — safety simulator (Phase 5).
 *
 * Standalone: requires no Laravel bootstrap, no DB, no composer autoload.
 * Run:  php backend/tests/campus_domain_resolver_simulator.php
 *
 * Replays the deterministic resolver over (a) the real production campus
 * mapping and (b) adversarial edge domains, prints the mandated report table,
 * and exits non-zero if any case regresses. This is the "testable before
 * production" gate for any change to domain → campus routing.
 */

require_once __DIR__ . '/../app/Services/CampusDomainResolver.php';

use App\Services\CampusDomainResolver;

/** Production campus mapping AFTER the 2026-06-27 data fix (campus 13 URL cleared). */
$PROD = [
    ['id' => 3,  'url' => '',                             'liff_id' => '2009814318-KfrRcGvn'],
    ['id' => 9,  'url' => '',                             'liff_id' => '2009768626-KUJR0xso'],
    ['id' => 11, 'url' => '',                             'liff_id' => '1656795001-8nkzg6Xw'],
    ['id' => 13, 'url' => '',                             'liff_id' => '1657309280-RNowmJ3z'], // 新莊中平 — URL cleared
    ['id' => 15, 'url' => 'https://daan.lifenet.com.tw',  'liff_id' => '1660958962-veQZzPWJ'], // 大安
    ['id' => 16, 'url' => '',                             'liff_id' => '1657378447-MeBG7eEw'],
];

/** The PRE-fix state — two campuses share the daan URL (the actual defect). */
$DUP = $PROD;
$DUP[3]['url'] = 'https://daan.lifenet.com.tw'; // campus 13 also carries daan (duplicate)

$cases = [
    // [label, requestHost, campuses, expectedStatus, expectedCampusId]
    ['prod: daan exact',            'daan.lifenet.com.tw',       $PROD, 'ok',        15],
    ['prod: daan w/ www',           'www.daan.lifenet.com.tw',   $PROD, 'ok',        15],
    ['prod: daan UPPERCASE',        'DAAN.LIFENET.COM.TW',       $PROD, 'ok',        15],
    ['prod: unknown domain',        'random.example.com',        $PROD, 'none',      null],
    ['prod: bare parent domain',    'lifenet.com.tw',            $PROD, 'none',      null], // no fuzzy suffix match
    ['prod: foreign subdomain',     'evil.daan.lifenet.com.tw',  $PROD, 'none',      null], // exact only, no suffix
    ['prod: empty host',            '',                          $PROD, 'none',      null],
    ['REGRESSION: duplicate daan',  'daan.lifenet.com.tw',       $DUP,  'ambiguous', null], // must NOT pick first (13)
];

$rows = [];
$failures = 0;
foreach ($cases as [$label, $host, $campuses, $expStatus, $expCampus]) {
    $r = CampusDomainResolver::resolve($host, $campuses);
    $ok = ($r['status'] === $expStatus) && ($r['campus_id'] === $expCampus);
    if (!$ok) {
        $failures++;
    }
    $rows[] = [
        'domain'     => $host === '' ? '(empty)' : $host,
        'resolved'   => $r['campus_id'] === null ? '—' : (string) $r['campus_id'],
        'status'     => $r['status'],
        'candidates' => $r['candidates'] ? implode(',', $r['candidates']) : '—',
        'expect'     => $expStatus . ($expCampus !== null ? "/{$expCampus}" : ''),
        'pass'       => $ok ? 'PASS' : 'FAIL',
    ];
}

// ── Report table ────────────────────────────────────────────────────────────
$w = ['domain' => 28, 'resolved' => 9, 'status' => 10, 'candidates' => 11, 'expect' => 13, 'pass' => 5];
$line = function (array $c) use ($w) {
    $out = '|';
    foreach ($w as $k => $width) {
        $out .= ' ' . str_pad((string) $c[$k], $width) . ' |';
    }
    return $out;
};
echo "Campus Domain Resolver — Simulator Report\n";
echo $line(['domain' => 'domain', 'resolved' => 'campus', 'status' => 'status', 'candidates' => 'candidates', 'expect' => 'expect', 'pass' => 'result']) . "\n";
echo str_repeat('-', 90) . "\n";
foreach ($rows as $c) {
    echo $line($c) . "\n";
}
echo "\n";

if ($failures > 0) {
    fwrite(STDERR, "SIMULATOR FAILED: {$failures} case(s) regressed — deployment BLOCKED.\n");
    exit(1);
}
echo "SIMULATOR PASSED: all cases deterministic; ambiguity detected (not first-picked); zero fuzzy fallback.\n";
exit(0);
