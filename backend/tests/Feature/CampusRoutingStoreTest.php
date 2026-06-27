<?php

namespace Tests\Feature;

use App\Models\CampusRoutingRule;
use App\Models\CampusRoutingVersion;
use App\Services\RoutingRuleStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * CRDE Phase 4 — immutable versioned routing store invariants.
 */
class CampusRoutingStoreTest extends TestCase
{
    use RefreshDatabase;

    private function store(): RoutingRuleStore
    {
        return app(RoutingRuleStore::class);
    }

    public function test_create_and_publish_version_resolves_exactly(): void
    {
        $store = $this->store();
        $v = $store->createVersion([
            ['domain' => 'daan.lifenet.com.tw', 'campus_id' => 15, 'liff_id' => 'LIFF-15'],
        ], 'test', true);

        $this->assertSame('published', $v->status);

        $r = $store->resolve('daan.lifenet.com.tw');
        $this->assertSame('ok', $r['status']);
        $this->assertSame(15, $r['campus_id']);
        $this->assertSame('LIFF-15', $r['liff_id']);

        // unknown host → none, never fallback
        $this->assertSame('none', $store->resolve('random.example.com')['status']);
    }

    public function test_duplicate_domain_ruleset_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->store()->createVersion([
            ['domain' => 'daan.lifenet.com.tw', 'campus_id' => 13],
            ['domain' => 'daan.lifenet.com.tw', 'campus_id' => 15],
        ], 'ambiguous', false);
    }

    public function test_new_version_supersedes_and_old_version_is_immutable(): void
    {
        $store = $this->store();
        $vA = $store->createVersion([['domain' => 'daan.lifenet.com.tw', 'campus_id' => 13, 'liff_id' => 'OLD']], 'A', true);
        $aRuleSnapshot = CampusRoutingRule::where('routing_version_id', $vA->id)->get()->map->only(['domain', 'campus_id', 'liff_id'])->toArray();

        $vB = $store->createVersion([['domain' => 'daan.lifenet.com.tw', 'campus_id' => 15, 'liff_id' => 'NEW']], 'B', true);

        // active is now B; A is superseded; resolution reflects B
        $this->assertSame($vB->version, $store->activeVersion()->version);
        $this->assertSame('superseded', CampusRoutingVersion::where('version', $vA->version)->first()->status);
        $this->assertSame(15, $store->resolve('daan.lifenet.com.tw')['campus_id']);

        // version A's rules are byte-for-byte unchanged (immutable snapshot)
        $aRuleAfter = CampusRoutingRule::where('routing_version_id', $vA->id)->get()->map->only(['domain', 'campus_id', 'liff_id'])->toArray();
        $this->assertEquals($aRuleSnapshot, $aRuleAfter);
    }

    public function test_resolve_liff_endpoint_uses_published_store(): void
    {
        $this->store()->createVersion([
            ['domain' => 'daan.lifenet.com.tw', 'campus_id' => 15, 'liff_id' => 'LIFF-15'],
        ], 'live', true);

        $res = $this->getJson('/api/v1/parent/resolve-liff', ['Host' => 'daan.lifenet.com.tw']);
        $res->assertOk()->assertJson(['liff_id' => 'LIFF-15', 'campus_id' => 15]);
    }
}
