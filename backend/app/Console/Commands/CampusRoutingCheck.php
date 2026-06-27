<?php

namespace App\Console\Commands;

use App\Services\CampusDomainResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CRDE verification layer — routing consistency check (read-only).
 *
 * The staging gate (Phase 7.1) and post-deploy verification (Phase 8) tool.
 * Asserts that every configured campus URL resolves deterministically to exactly
 * one campus — i.e. no two active campuses share a host (the 2026-06-27 failure).
 * Read-only: no writes, safe to run against staging or production.
 *
 * Usage:
 *   php artisan campus:routing-check
 *   php artisan campus:routing-check --expect=daan.lifenet.com.tw=15
 *
 * Exit code 0 = clean; 1 = ambiguity / unexpected mapping found (BLOCK deploy).
 */
class CampusRoutingCheck extends Command
{
    protected $signature = 'campus:routing-check {--expect=* : host=campus_id assertions, e.g. --expect=daan.lifenet.com.tw=15}';

    protected $description = 'Verify deterministic, unambiguous campus domain routing (read-only).';

    public function handle(): int
    {
        $campuses = DB::table('Campus')
            ->whereNotNull('LIFFID')->where('LIFFID', '!=', '')
            ->whereNotNull('URL')->where('URL', '!=', '')
            ->get(['id', 'name', 'URL', 'LIFFID'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'url' => $c->URL, 'liff_id' => $c->LIFFID])
            ->all();

        $hosts = [];
        foreach ($campuses as $c) {
            $host = CampusDomainResolver::normalizeHost($c['url']);
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }

        $problems = 0;
        $this->line('CRDE routing-check — ' . count($campuses) . ' campus URL mapping(s)');

        // 1. Every configured host must resolve to exactly one campus.
        foreach (array_keys($hosts) as $host) {
            $r = CampusDomainResolver::resolve($host, $campuses);
            $status = $r['status'];
            if ($status === 'ambiguous') {
                $problems++;
                $this->error("  AMBIGUOUS  {$host} → candidates [" . implode(',', $r['candidates']) . ']');
            } elseif ($status !== 'ok') {
                $problems++;
                $this->error("  UNRESOLVED {$host} → {$status}");
            } else {
                $this->line("  ok         {$host} → campus {$r['campus_id']}");
            }
        }

        // 2. Explicit expectations (--expect=host=campus_id).
        foreach ((array) $this->option('expect') as $pair) {
            [$host, $expected] = array_pad(explode('=', $pair, 2), 2, null);
            $r = CampusDomainResolver::resolve((string) $host, $campuses);
            if ((string) $r['campus_id'] !== (string) $expected) {
                $problems++;
                $this->error("  EXPECT FAIL {$host} → got " . ($r['campus_id'] ?? 'null') . " expected {$expected}");
            } else {
                $this->info("  expect ok  {$host} → campus {$expected}");
            }
        }

        if ($problems > 0) {
            $this->error("FAILED: {$problems} routing problem(s) — deployment must be BLOCKED.");
            return self::FAILURE;
        }

        $this->info('PASSED: all campus domains resolve deterministically and unambiguously.');
        return self::SUCCESS;
    }
}
