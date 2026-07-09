<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRDE Phase 4 — versioned immutable routing store.
 *
 * Replaces the mutable, overwrite-in-place Campus.URL mapping with an append-only
 * versioned store: a published routing version is a frozen snapshot; new config is
 * a NEW version, never an UPDATE of existing rows. A per-version UNIQUE(domain)
 * makes an ambiguous (duplicate-domain) mapping impossible to persist — the exact
 * failure of 2026-06-27.
 *
 * Seeds version 1 (published) from the current Campus URL/LIFFID truth so runtime
 * behavior is identical (daan.lifenet.com.tw → campus 15) until a new version is
 * deliberately published.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campus_routing_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('status', 16)->default('draft'); // draft | published | superseded
            $table->string('checksum', 64)->nullable();      // sha256 of rules — replayability
            $table->string('note', 255)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('campus_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routing_version_id')->constrained('campus_routing_versions')->cascadeOnDelete();
            $table->string('domain', 191);                  // normalized host (lowercase, no www.)
            $table->unsignedInteger('campus_id');
            $table->string('match_type', 16)->default('exact'); // exact | wildcard
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('enabled')->default(true);
            $table->string('liff_id', 64)->nullable();
            $table->timestamps();
            // No two rules in one version may claim the same domain → ambiguity is unpersistable.
            $table->unique(['routing_version_id', 'domain'], 'routing_rule_version_domain_unique');
        });

        // Seed version 1 (published) from current Campus truth (URL + LIFFID both set).
        $campuses = DB::table('Campus')
            ->whereNotNull('URL')->where('URL', '!=', '')
            ->whereNotNull('LIFFID')->where('LIFFID', '!=', '')
            ->get(['id', 'URL', 'LIFFID']);

        $rules = [];
        foreach ($campuses as $c) {
            $host = parse_url((string) $c->URL, PHP_URL_HOST) ?: '';
            $host = strtolower($host);
            if (str_starts_with($host, 'www.')) {
                $host = substr($host, 4);
            }
            if ($host === '') {
                continue;
            }
            $rules[$host] = ['domain' => $host, 'campus_id' => (int) $c->id, 'liff_id' => $c->LIFFID];
        }
        ksort($rules);
        $checksum = hash('sha256', json_encode(array_values($rules)));

        $versionId = DB::table('campus_routing_versions')->insertGetId([
            'version'      => 1,
            'status'       => 'published',
            'checksum'     => $checksum,
            'note'         => 'Initial snapshot seeded from Campus URL/LIFFID',
            'published_at' => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        foreach (array_values($rules) as $r) {
            DB::table('campus_routing_rules')->insert([
                'routing_version_id' => $versionId,
                'domain'             => $r['domain'],
                'campus_id'          => $r['campus_id'],
                'match_type'         => 'exact',
                'priority'           => 100,
                'enabled'            => true,
                'liff_id'            => $r['liff_id'],
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campus_routing_rules');
        Schema::dropIfExists('campus_routing_versions');
    }
};
