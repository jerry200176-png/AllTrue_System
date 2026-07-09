<?php

namespace App\Http\Controllers;

use App\Models\CampusRoutingRule;
use App\Models\CampusRoutingVersion;
use App\Services\RoutingRuleStore;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Admin API for the CRDE Phase 4 immutable routing store.
 *
 * Hard rule: this API NEVER overwrites existing rules. Every change creates a NEW
 * version (validated before it can be published). There is intentionally no update
 * or delete endpoint for individual rules.
 */
class AdminRoutingRuleController extends Controller
{
    public function __construct(private RoutingRuleStore $store)
    {
    }

    /** Active version + its rules + version history. */
    public function index()
    {
        $active = $this->store->activeVersion();

        return response()->json([
            'active'   => $active ? [
                'version'      => $active->version,
                'checksum'     => $active->checksum,
                'published_at' => $active->published_at,
                'rules'        => $this->store->activeRules(),
            ] : null,
            'versions' => CampusRoutingVersion::orderByDesc('version')
                ->get(['version', 'status', 'checksum', 'note', 'published_at', 'created_at']),
        ]);
    }

    /**
     * Create a NEW version from a proposed ruleset (never overwrites).
     * Optionally publish it (?publish=true). Ambiguous/invalid rulesets are rejected (422).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'rules'              => 'required|array|min:1',
            'rules.*.domain'     => 'required|string|max:191',
            'rules.*.campus_id'  => 'required|integer|min:1',
            'rules.*.match_type' => 'nullable|in:exact,wildcard',
            'rules.*.priority'   => 'nullable|integer',
            'rules.*.enabled'    => 'nullable|boolean',
            'rules.*.liff_id'    => 'nullable|string|max:64',
            'note'               => 'nullable|string|max:255',
            'publish'            => 'nullable|boolean',
        ]);

        try {
            $version = $this->store->createVersion(
                $data['rules'],
                $data['note'] ?? null,
                (bool) ($data['publish'] ?? false),
            );
        } catch (RuntimeException $e) {
            // Validation (ambiguity / fuzzy / invalid) — reject, never publish a bad ruleset.
            return response()->json(['message' => $e->getMessage(), 'error' => 'invalid_ruleset'], 422);
        }

        return response()->json([
            'version'   => $version->version,
            'status'    => $version->status,
            'checksum'  => $version->checksum,
            'rule_count' => CampusRoutingRule::where('routing_version_id', $version->id)->count(),
        ], 201);
    }

    /** Publish a previously-created draft version (supersedes the active one). */
    public function publish(int $version)
    {
        /** @var CampusRoutingVersion $row */
        $row = CampusRoutingVersion::where('version', $version)->firstOrFail();
        $published = $this->store->publish($row->id);

        return response()->json([
            'version'      => $published->version,
            'status'       => $published->status,
            'published_at' => $published->published_at,
        ]);
    }

    /** Verification layer: confirm the active version resolves every domain uniquely. */
    public function check()
    {
        $rules = $this->store->activeRules();
        $report = [];
        $problems = 0;
        foreach ($rules as $r) {
            $res = $this->store->resolve($r['domain']);
            if ($res['status'] !== 'ok') {
                $problems++;
            }
            $report[] = ['domain' => $r['domain'], 'status' => $res['status'], 'campus_id' => $res['campus_id']];
        }

        return response()->json([
            'ok'       => $problems === 0,
            'version'  => $this->store->activeVersion()?->version,
            'problems' => $problems,
            'report'   => $report,
        ], $problems === 0 ? 200 : 409);
    }
}
