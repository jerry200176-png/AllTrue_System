<?php

namespace App\Operations\Inapp;

use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\InappReportReporterTimeoutStrategy;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ADR-POP-015 standing approval; authority FOUNDER_STANDING_DECISIONS.md 2026-10-14 (II) (#4069, ADR #4073).
 * Approves only when every machine check passes; otherwise one denial, then the super_admin quorum.
 */
final class InappStandingPolicy
{
    public const ID = 'founder-standing-inapp-lifecycle';
    public const FILE = 'inapp-lifecycle.yaml';
    public const OPERATIONS = [
        InappReportReporterTimeoutStrategy::OPERATION => InappReportReporterTimeoutStrategy::class,
    ];

    public function __construct(private readonly PopOperationCatalog $catalog) {}

    /** The exact catalog shape this policy may approve. */
    public static function governs(array $entry): bool
    {
        $id = (string) ($entry['id'] ?? '');

        return isset(self::OPERATIONS[$id])
            && ($entry['strategy_class'] ?? null) === self::OPERATIONS[$id]
            && ($entry['approval_policy'] ?? null) === self::ID
            && ($entry['approver_roles'] ?? null) === ['super_admin']
            && ($entry['execution_authority'] ?? null) === 'pop-pi-local'
            && ($entry['approval_required'] ?? null) === true
            && ($entry['transaction_required'] ?? null) === true
            && ($entry['verification_required'] ?? null) === true
            && ($entry['reversible'] ?? null) === false;
    }

    /** @return array<string,mixed> */
    public function definition(): array
    {
        $out = [];
        foreach (file($this->catalog->policyFile(self::FILE), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^([a-z_]+):\s*(.+)$/', $line, $m)) {
                $out[$m[1]] = preg_match('/^\[(.*)\]$/', $m[2], $l) ? array_map('trim', explode(',', $l[1])) : trim($m[2]);
            }
        }

        return $out;
    }

    /** Off unless the env flag is on and the policy file is the expected, active version. */
    public function active(): bool
    {
        if (config('pop_inapp.standing_policy_enabled') !== true || !is_file($this->catalog->policyFile(self::FILE))) return false;
        $d = $this->definition();

        return ($d['id'] ?? null) === self::ID && ($d['lifecycle'] ?? null) === 'active'
            && ctype_digit((string) ($d['version'] ?? '')) && (int) $d['version'] > 0
            && ($d['operations'] ?? null) === array_keys(self::OPERATIONS);
    }

    public function decisionReference(): string
    {
        return (string) ($this->definition()['decision_reference'] ?? '');
    }

    public function approverRef(): string
    {
        return 'policy:' . self::ID . '@' . (int) ($this->definition()['version'] ?? 0);
    }

    /** @return list<string> denial codes; [] approves. Status/catalog/hash are rechecked under lock by recordApproval(). */
    public function evaluate(object $request): array
    {
        $entry = $this->catalog->operation((string) $request->operation_id);
        if (!$this->active() || !self::governs($entry)) return ['policy_inactive_or_operation_not_governed'];
        $reasons = app(InappRequesterGuard::class)->allows($request) ? [] : ['requester_client_unverified'];
        $params = json_decode((string) $request->parameters, true, 512, JSON_THROW_ON_ERROR);
        $dryRun = DB::table('pop_execution_records')->where('operation_id', $request->id)->where('phase', 'dry-run')
            ->where('result', 'succeeded')->orderByDesc('attempt_no')->first();
        $dryDigest = $dryRun ? data_get(json_decode((string) $dryRun->payload, true), 'plan.digest') : null;
        if (!is_string($dryDigest)) $reasons[] = 'dry_run_missing';
        $rid = $params['request_id'] ?? null;
        if (!is_string($rid) || DB::table('pop_operation_requests')->whereIn('operation_id', array_keys(self::OPERATIONS))
            ->where('id', '!=', $request->id)->where('parameters->request_id', $rid)->exists()) $reasons[] = 'request_id_replayed';
        if (!$this->capAvailable($request)) $reasons[] = 'daily_cap_exceeded';
        if ($reasons !== []) return $reasons;
        try {
            $plan = app(self::OPERATIONS[$entry['id']])->plan($params);
        } catch (Throwable) {
            return ['plan_failed'];
        }
        if (($plan['ok'] ?? false) !== true) {
            return ['plan_failed', ...array_map(fn ($e) => 'plan:' . $e, (array) ($plan['errors'] ?? []))];
        }
        if ((array) ($plan['policy_blockers'] ?? []) !== []) {
            return array_map(fn ($e) => 'plan:' . $e, (array) $plan['policy_blockers']);
        }

        return ($plan['digest'] ?? null) === $dryDigest ? [] : ['dry_run_digest_mismatch'];
    }

    /** ADR-POP-015 §4 kill switches for a policy-approved request (flag, frozen/re-versioned policy, requester client). */
    public static function assertKillSwitchesOpen(string $popRequestId): void
    {
        $approvers = self::policyApprovers($popRequestId);
        if ($approvers->isEmpty()) return;
        $policy = app(self::class);
        $request = DB::table('pop_operation_requests')->where('id', $popRequestId)->first();
        if (!$request || !$policy->active() || !app(InappRequesterGuard::class)->allows($request)
            || $approvers->contains(fn ($a): bool => (string) $a !== $policy->approverRef())) {
            throw new \RuntimeException('inapp_standing_policy_kill_switch');
        }
    }

    /** Write-back author: the issuing super admin (policy approval, part-2 seam) or the approving super admin (human). */
    public static function attributionUserId(?object $request): int
    {
        $human = DB::table('pop_approval_events')->where('operation_id', $request?->id)->where('event_type', 'approved')
            ->where('approver_role', 'super_admin')->value('approver_id');
        $id = $human !== null ? (int) $human : ($request ? app(InappRequesterGuard::class)->attributionUserId($request) : null);
        if (!$id || !User::query()->whereKey($id)->where('type', 'S')->exists()) throw new \RuntimeException('inapp_attribution_unresolved');

        return $id;
    }

    public static function policyApprovers(string $popRequestId): \Illuminate\Support\Collection
    {
        return DB::table('pop_approval_events')->where('operation_id', $popRequestId)
            ->where('event_type', 'approved')->where('approver_role', 'policy')->pluck('approver');
    }

    /** Decide each undecided request of a governed operation once. Output is IDs and counts only. */
    public function approvePending(PopOperationService $service, int $limit = 1): array
    {
        if (!$this->active()) return ['status' => 'disabled', 'approved' => [], 'denied' => []];
        // The executor runs one request a minute and tokens live 15 minutes: approve only when none waits.
        if (DB::table('pop_operation_requests')->whereIn('operation_id', array_keys(self::OPERATIONS))->where('status', 'approved')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('pop_approval_events')->whereColumn('operation_id', 'pop_operation_requests.id')
                ->where('approver_role', 'policy')->where('event_type', 'approved')->where('expires_at', '>', now()))->exists()) {
            return ['status' => 'waiting_for_executor', 'approved' => [], 'denied' => []];
        }
        // One lock for every policy decision (a superset of a per-operation/day lock): the daily cap is
        // counted and reserved by recordApproval()'s transaction while it is held.
        $lock = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', ['alltrue:pop:standing-policy']);
        if ((int) ($lock->acquired ?? 0) !== 1) return ['status' => 'busy', 'approved' => [], 'denied' => []];
        $out = ['status' => 'ok', 'approved' => [], 'denied' => []];
        try {
            $pending = DB::table('pop_operation_requests')->where('status', 'awaiting_approval')
                ->whereIn('operation_id', array_keys(self::OPERATIONS))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('pop_approval_events')
                    ->whereColumn('operation_id', 'pop_operation_requests.id')->whereIn('event_type', ['approved', 'policy_denied']))
                ->oldest('created_at')->limit($limit)->get();
            foreach ($pending as $request) {
                $reasons = $this->evaluate($request);
                $service->recordPolicyDecision((string) $request->id, $this, $reasons);
                SecurityAuditEvent::append('pop.operation.policy_decision', $reasons === [] ? 'success' : 'failure', ['actor_type' => 'policy',
                    'subject_type' => 'pop_operation', 'subject_id' => (string) $request->id], ['reason_code' => implode(',', $reasons) ?: 'approved']);
                $out[$reasons === [] ? 'approved' : 'denied'][] = (string) $request->id;
            }
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', ['alltrue:pop:standing-policy']);
        }

        return $out;
    }

    /** Daily cap in target reports per operation per Asia/Taipei day. Inside recordApproval() the inserted event is the reservation. */
    public function capAvailable(object $request): bool
    {
        $units = fn ($p): int => count((array) (json_decode((string) $p, true)['bug_ids'] ?? [1]));
        $used = DB::table('pop_approval_events as e')->join('pop_operation_requests as r', 'r.id', '=', 'e.operation_id')
            ->where('e.event_type', 'approved')->where('e.approver_role', 'policy')->where('r.operation_id', $request->operation_id)
            ->where('e.created_at', '>=', now('Asia/Taipei')->startOfDay()->setTimezone(config('app.timezone')))->pluck('r.parameters')->sum($units);

        return $used + $units($request->parameters) <= (int) config('pop_inapp.daily_cap', 20);
    }
}
