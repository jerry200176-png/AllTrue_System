<?php

namespace App\Operations;

use App\Models\SecurityAuditEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Durable request/approval/execution boundary for the POP self-hosted runner. */
final class PopOperationService
{
    private const COMMIT_SHA_PATTERN = '/^[0-9a-f]{40}$/i';
    private const ACTOR_PATTERN = '/^[A-Za-z0-9:_-]{1,128}$/';
    private const REFERENCE_PATTERN = '/^[A-Za-z0-9_.:#\/-]{3,128}$/';

    public function __construct(private readonly PopOperationCatalog $catalog)
    {
    }

    /** @param array<string,mixed> $parameters @param array<int,int> $actorCampusIds */
    public function createDraft(
        string $operationId,
        array $parameters,
        string $idempotencyKey,
        string $actor,
        ?string $actorRole = null,
        array $actorCampusIds = [],
        ?int $actorId = null
    ): array {
        $entry = $this->catalog->operation($operationId);
        $this->assertActor($actor);
        $this->assertIdempotencyKey($idempotencyKey);
        $normalized = self::canonicalParameters($parameters);
        $this->assertParameterContract($entry, $normalized);
        $this->assertCampusScope($normalized, $actorRole, $actorCampusIds);
        $hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($entry, $operationId, $normalized, $idempotencyKey, $actor, $actorId, $hash): array {
            $existing = DB::table('pop_operation_requests')
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $this->assertIdempotencyMatch($existing, $operationId, $hash);

                return (array) $existing;
            }

            $id = (string) Str::uuid();
            $inserted = DB::table('pop_operation_requests')->insertOrIgnore([
                'id' => $id,
                'operation_id' => $operationId,
                'strategy_id' => (string) $entry['strategy_class'],
                'catalog_version' => $this->catalog->version(),
                'parameters' => json_encode($normalized, JSON_THROW_ON_ERROR),
                'parameters_hash' => $hash,
                'idempotency_key' => $idempotencyKey,
                'status' => 'draft',
                'actor' => substr($actor, 0, 128),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $request = $inserted
                ? DB::table('pop_operation_requests')->where('id', $id)->first()
                : DB::table('pop_operation_requests')->where('idempotency_key', $idempotencyKey)->first();
            if (!$request) {
                throw new RuntimeException('POP draft was not persisted; fail closed.');
            }
            $this->assertIdempotencyMatch($request, $operationId, $hash);
            if (!$inserted) {
                return (array) $request;
            }
            $this->audit($request, 'draft', 'success', $normalized, $actorId);

            return (array) $request;
        });
    }

    /**
     * Record one approval. A token is issued only after every catalog-required
     * role has approved the same immutable request and commit SHA.
     *
     * @param array<int,int> $approverCampusIds
     * @return array{request_id:string,status:string,ready:bool,required_roles:array<int,string>,approved_roles:array<int,string>,token:?string,expires_at:?string}
     */
    public function approve(
        string $requestId,
        string $approvalReference,
        string $approver,
        string $approverRole,
        string $commitSha,
        ?int $approverId = null,
        array $approverCampusIds = [],
        int $ttlMinutes = 15
    ): array {
        $request = $this->request($requestId);
        $entry = $this->catalog->operation((string) $request->operation_id);
        if ((string) $entry['lifecycle'] !== 'active') {
            throw new RuntimeException('POP operation is not active; Founder activation is required.');
        }
        $this->assertCommitSha($commitSha);
        $this->assertReference($approvalReference);
        $this->assertActor($approver);
        if ($ttlMinutes < 1 || $ttlMinutes > 60) {
            throw new RuntimeException('POP approval TTL is outside the allowed range.');
        }
        $requiredRoles = $this->approvalRoles($entry);
        if (!in_array($approverRole, $requiredRoles, true)) {
            throw new RuntimeException('POP approver role is not authorized for this operation.');
        }
        $parameters = json_decode((string) $request->parameters, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCampusScope($parameters, $approverRole, $approverCampusIds);
        if ($approverId !== null && (string) $request->actor === 'user:' . $approverId) {
            throw new RuntimeException('POP approval requires separation of duties.');
        }
        $dryRun = $this->latest($request, 'dry-run');
        if (!$dryRun || (string) $dryRun->result !== 'succeeded') {
            throw new RuntimeException('POP approval requires a successful dry-run.');
        }
        if ((int) $request->catalog_version !== $this->catalog->version()) {
            throw new RuntimeException('POP request catalog version is stale; create a new draft.');
        }

        return DB::transaction(function () use ($requestId, $approvalReference, $approver, $approverRole, $commitSha, $approverId, $ttlMinutes, $parameters, $requiredRoles): array {
            $locked = DB::table('pop_operation_requests')->where('id', $requestId)->lockForUpdate()->first();
            if (!$locked) {
                throw new RuntimeException('POP request not found; fail closed.');
            }
            $sameRole = DB::table('pop_approval_events')
                ->where('operation_id', $requestId)
                ->where('event_type', 'approved')
                ->where('approver_role', $approverRole)
                ->exists();
            if ($sameRole) {
                throw new RuntimeException('POP approval role has already approved this request.');
            }
            $priorCommits = DB::table('pop_approval_events')
                ->where('operation_id', $requestId)
                ->where('event_type', 'approved')
                ->pluck('commit_sha')
                ->map(fn ($sha): string => (string) $sha)
                ->unique()
                ->values()
                ->all();
            if ($priorCommits !== [] && $priorCommits !== [$commitSha]) {
                throw new RuntimeException('POP approvals must bind one exact commit SHA.');
            }

            DB::table('pop_approval_events')->insert([
                'operation_id' => $requestId,
                'event_type' => 'approved',
                'approval_reference' => substr($approvalReference, 0, 128),
                'approver' => substr($approver, 0, 128),
                'approver_role' => $approverRole,
                'approver_id' => $approverId,
                'commit_sha' => $commitSha,
                'phase' => 'execute',
                'parameters_hash' => $locked->parameters_hash,
                'token_hash' => null,
                'expires_at' => now()->addMinutes($ttlMinutes),
                'created_at' => now(),
            ]);

            $approvedRoles = $this->approvedRoles($requestId);
            $ready = array_diff($requiredRoles, $approvedRoles) === [];
            $token = null;
            $expiresAt = null;
            if ($ready) {
                $expiresAt = now()->addMinutes($ttlMinutes);
                $token = $this->sign($locked, 'execute', $commitSha, $expiresAt->timestamp);
                DB::table('pop_approval_events')
                    ->where('operation_id', $requestId)
                    ->where('approver_role', $approverRole)
                    ->where('commit_sha', $commitSha)
                    ->whereNull('token_hash')
                    ->update(['token_hash' => hash('sha256', $token), 'expires_at' => $expiresAt]);
            }
            DB::table('pop_operation_requests')->where('id', $requestId)->update([
                'status' => $ready ? 'approved' : 'awaiting_approval',
                'updated_at' => now(),
            ]);
            $this->audit($locked, 'approval', 'success', $parameters, $approverId, $approverRole);

            return [
                'request_id' => $requestId,
                'status' => $ready ? 'approved' : 'awaiting_approval',
                'ready' => $ready,
                'required_roles' => $requiredRoles,
                'approved_roles' => $approvedRoles,
                'token' => $token,
                'expires_at' => $expiresAt?->toIso8601String(),
            ];
        });
    }

    /** @return array<string,mixed> */
    public function run(string $requestId, string $phase, ?string $token = null, ?string $commitSha = null, string $actor = 'pop-runner'): array
    {
        $request = $this->request($requestId);
        $entry = $this->catalog->operation((string) $request->operation_id);
        if ((int) $request->catalog_version !== $this->catalog->version()) {
            throw new RuntimeException('POP request catalog version is stale; fail closed.');
        }
        if (!in_array($phase, ['dry-run', 'execute', 'verify', 'rollback'], true)) {
            throw new RuntimeException('POP phase is invalid; fail closed.');
        }
        $this->assertActor($actor);
        $parameters = json_decode((string) $request->parameters, true, 512, JSON_THROW_ON_ERROR);
        $startedAt = microtime(true);

        if ($phase === 'dry-run') {
            $existing = $this->existing($request, $phase);
            if ($existing) {
                return $existing;
            }
            $plan = $this->safePlan($entry, $parameters);

            return $this->recordDryRun($request, $entry, $parameters, $plan, $actor, $this->durationMs($startedAt));
        }
        if ((string) $entry['lifecycle'] !== 'active') {
            throw new RuntimeException('POP operation is not active; Founder activation is required.');
        }
        if (!$token || !$commitSha) {
            throw new RuntimeException('POP approval token is missing; fail closed.');
        }
        $approval = $this->validApproval($request, $token, $commitSha);
        if (!$approval) {
            throw new RuntimeException('POP approval token is missing, expired, or not bound to this request.');
        }
        $existing = $this->existing($request, $phase);
        if ($existing) {
            return $existing;
        }
        $plan = $this->safePlan($entry, $parameters);
        if (!(bool) ($plan['ok'] ?? false)) {
            return $this->record($request, $entry, $parameters, $phase, [
                'ok' => false,
                'errors' => $plan['errors'] ?? ['precondition_failed'],
                'plan' => $plan,
            ], $actor, $approval, $commitSha, $this->durationMs($startedAt), 'precondition_failed');
        }

        try {
            if ($phase === 'execute') {
                $strategy = app((string) $entry['strategy_class']);

                return $this->record(
                    $request,
                    $entry,
                    $parameters,
                    'execute',
                    $strategy->execute($plan, ['actor' => $actor, 'operation_id' => $request->id]),
                    $actor,
                    $approval,
                    $commitSha,
                    $this->durationMs($startedAt)
                );
            }
            $execution = $this->latest($request, 'execute');
            if (!$execution) {
                throw new RuntimeException('POP cannot verify or rollback without an execution record.');
            }
            $payload = json_decode((string) $execution->payload, true, 512, JSON_THROW_ON_ERROR);
            $strategy = app((string) $entry['strategy_class']);
            $result = $phase === 'verify'
                ? $strategy->verify($plan, $payload)
                : $strategy->rollback($payload['snapshot'] ?? [], ['actor' => $actor, 'operation_id' => $request->id]);

            return $this->record($request, $entry, $parameters, $phase, $result, $actor, $approval, $commitSha, $this->durationMs($startedAt));
        } catch (Throwable) {
            return $this->record($request, $entry, $parameters, $phase, [
                'ok' => false,
                'errors' => ['execution_failed'],
            ], $actor, $approval, $commitSha, $this->durationMs($startedAt), 'execution_failed');
        }
    }

    /**
     * Execute the next already-approved request on the production host.
     *
     * The raw approval token is reconstructed only inside the Pi process from
     * its database hash and local APP_KEY; it is never returned or logged.
     */
    public function runApprovedLocally(?string $requestId = null): array
    {
        $candidate = $requestId === null
            ? DB::table('pop_operation_requests')->where('status', 'approved')->oldest('created_at')->first()
            : $this->request($requestId);
        if (!$candidate) {
            return ['ok' => true, 'status' => 'idle', 'processed' => 0];
        }

        $lockName = 'alltrue:pop:' . (string) $candidate->id;
        $lock = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        if ((int) ($lock->acquired ?? 0) !== 1) {
            return ['ok' => true, 'status' => 'busy', 'request_id' => (string) $candidate->id];
        }

        try {
            $request = $this->request((string) $candidate->id);
            if ((string) $request->status !== 'approved') {
                return ['ok' => true, 'status' => 'skipped', 'request_id' => (string) $request->id];
            }
            $approval = $this->localApproval($request);
            $commitSha = (string) $approval->commit_sha;
            $this->assertLocalDeployment($commitSha);
            $token = $this->reconstructToken($request, $approval, $commitSha);
            $execute = $this->run((string) $request->id, 'execute', $token, $commitSha, 'pop-pi-local');
            if (($execute['result'] ?? null) !== 'succeeded') {
                return $this->localSummary($request, $execute);
            }
            $verify = $this->run((string) $request->id, 'verify', $token, $commitSha, 'pop-pi-local');

            return $this->localSummary($request, $verify, $execute);
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    /** @param array<string,mixed> $parameters */
    public static function canonicalParameters(array $parameters): array
    {
        ksort($parameters);
        foreach ($parameters as $key => $value) {
            if (is_array($value)) {
                $parameters[$key] = self::canonicalParameters($value);
            }
        }

        return $parameters;
    }

    private function request(string $id): object
    {
        $request = DB::table('pop_operation_requests')->where('id', $id)->first();
        if (!$request) {
            throw new RuntimeException('POP request not found; fail closed.');
        }

        return $request;
    }

    /** @param array<string,mixed> $entry @param array<string,mixed> $parameters */
    private function assertParameterContract(array $entry, array $parameters): void
    {
        $keys = array_values(array_map('strval', (array) $entry['parameter_keys']));
        $actual = array_map('strval', array_keys($parameters));
        if (array_diff($actual, $keys) !== [] || array_diff($keys, $actual) !== []) {
            throw new RuntimeException('POP parameters do not exactly match the catalog contract.');
        }
    }

    /** @param array<string,mixed> $parameters @param array<int,int> $campusIds */
    private function assertCampusScope(array $parameters, ?string $role, array $campusIds): void
    {
        if ($role === null || $role === 'super_admin') {
            return;
        }
        $campusId = (int) ($parameters['campus_id'] ?? 0);
        if ($campusId <= 0 || !in_array($campusId, array_map('intval', $campusIds), true)) {
            throw new RuntimeException('POP campus is outside the actor scope.');
        }
    }

    private function assertActor(string $actor): void
    {
        if (!preg_match(self::ACTOR_PATTERN, $actor)) {
            throw new RuntimeException('POP actor reference is invalid; fail closed.');
        }
    }

    private function assertIdempotencyKey(string $key): void
    {
        if (!preg_match(self::ACTOR_PATTERN, $key)) {
            throw new RuntimeException('POP idempotency key is invalid; fail closed.');
        }
    }

    private function assertReference(string $reference): void
    {
        if (!preg_match(self::REFERENCE_PATTERN, $reference)) {
            throw new RuntimeException('POP approval reference is invalid; fail closed.');
        }
    }

    private function assertCommitSha(string $commitSha): void
    {
        if (!preg_match(self::COMMIT_SHA_PATTERN, $commitSha)) {
            throw new RuntimeException('POP commit SHA must be an exact 40-hex commit; fail closed.');
        }
    }

    private function assertIdempotencyMatch(object $existing, string $operationId, string $hash): void
    {
        if ((string) $existing->parameters_hash !== $hash || (string) $existing->operation_id !== $operationId) {
            throw new RuntimeException('POP idempotency key is already bound to different parameters.');
        }
    }

    /** @param array<string,mixed> $entry @return array<int,string> */
    private function approvalRoles(array $entry): array
    {
        $policy = $this->catalog->approvalPolicy($entry);
        $roles = $this->catalog->requiredApprovalRoles($entry);
        if ($policy !== 'critical-dual-approval' || !in_array('director', $roles, true) || !in_array('super_admin', $roles, true)) {
            throw new RuntimeException('POP approval policy is not supported by this execution slice; fail closed.');
        }

        return $roles;
    }

    private function sign(object $request, string $phase, string $commitSha, int $expiresAt): string
    {
        $body = implode('|', [$request->id, $request->operation_id, $request->strategy_id, $request->parameters_hash, $phase, $commitSha, $expiresAt]);
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('POP signing key is unavailable; fail closed.');
        }

        return $expiresAt . '.' . hash_hmac('sha256', $body, $key);
    }

    private function validApproval(object $request, string $token, string $commitSha): ?object
    {
        $this->assertCommitSha($commitSha);
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return null;
        }
        $expiresAt = (int) $parts[0];
        if ($expiresAt <= now()->timestamp) {
            return null;
        }
        $entry = $this->catalog->operation((string) $request->operation_id);
        $requiredRoles = $this->approvalRoles($entry);
        $approvals = DB::table('pop_approval_events')
            ->where('operation_id', $request->id)
            ->where('event_type', 'approved')
            ->get();
        $approvedRoles = $approvals->pluck('approver_role')->map(fn ($role): string => (string) $role)->unique()->values()->all();
        if (array_diff($requiredRoles, $approvedRoles) !== []) {
            return null;
        }
        $commits = $approvals->pluck('commit_sha')->map(fn ($sha): string => (string) $sha)->unique()->values()->all();
        if ($commits !== [$commitSha]) {
            return null;
        }
        $approval = $approvals->first(fn ($row): bool => (string) $row->token_hash === hash('sha256', $token));
        if (!$approval || Carbon::parse((string) $approval->expires_at)->isPast()) {
            return null;
        }
        if ((string) $approval->parameters_hash !== (string) $request->parameters_hash) {
            return null;
        }

        return hash_equals((string) $approval->token_hash, hash('sha256', $token))
            && hash_equals($token, $this->sign($request, 'execute', $commitSha, $expiresAt))
            ? $approval
            : null;
    }

    private function localApproval(object $request): object
    {
        $entry = $this->catalog->operation((string) $request->operation_id);
        $requiredRoles = $this->approvalRoles($entry);
        $approvals = DB::table('pop_approval_events')
            ->where('operation_id', $request->id)
            ->where('event_type', 'approved')
            ->get();
        $roles = $approvals->pluck('approver_role')->map(fn ($role): string => (string) $role)->unique()->values()->all();
        $commits = $approvals->pluck('commit_sha')->map(fn ($sha): string => (string) $sha)->unique()->values()->all();
        $approval = $approvals->first(fn ($row): bool => (string) $row->token_hash !== '');
        if (array_diff($requiredRoles, $roles) !== [] || count($commits) !== 1 || !$approval) {
            throw new RuntimeException('POP local executor found incomplete approval evidence; fail closed.');
        }
        if ((string) $approval->parameters_hash !== (string) $request->parameters_hash) {
            throw new RuntimeException('POP local executor found parameter hash drift; fail closed.');
        }
        if (Carbon::parse((string) $approval->expires_at)->isPast()) {
            throw new RuntimeException('POP local executor found an expired approval; fail closed.');
        }

        return $approval;
    }

    private function reconstructToken(object $request, object $approval, string $commitSha): string
    {
        $expiresAt = Carbon::parse((string) $approval->expires_at)->timestamp;
        $token = $this->sign($request, 'execute', $commitSha, $expiresAt);
        if (!hash_equals((string) $approval->token_hash, hash('sha256', $token))) {
            throw new RuntimeException('POP local executor token hash mismatch; fail closed.');
        }

        return $token;
    }

    private function assertLocalDeployment(string $commitSha): void
    {
        $path = base_path('public/deployment.json');
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $deployedSha = is_array($manifest) ? (string) ($manifest['backend_sha'] ?? '') : '';
        if (!preg_match(self::COMMIT_SHA_PATTERN, $deployedSha) || !hash_equals(strtolower($commitSha), strtolower($deployedSha))) {
            throw new RuntimeException('POP local executor deployment SHA does not match approval; fail closed.');
        }
    }

    /** @return array<string,mixed> */
    private function localSummary(object $request, array $result, ?array $execute = null): array
    {
        $summary = [
            'ok' => (bool) ($result['ok'] ?? false),
            'status' => (string) ($result['result'] ?? 'failed'),
            'request_id' => (string) $request->id,
            'phase' => (string) ($result['phase'] ?? ''),
            'execution_id' => (string) ($result['execution_id'] ?? ''),
        ];
        if ($execute !== null) {
            $summary['execute_id'] = (string) ($execute['execution_id'] ?? '');
        }

        return $summary;
    }

    /** @return array<int,string> */
    private function approvedRoles(string $requestId): array
    {
        return DB::table('pop_approval_events')
            ->where('operation_id', $requestId)
            ->where('event_type', 'approved')
            ->pluck('approver_role')
            ->map(fn ($role): string => (string) $role)
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $entry @param array<string,mixed> $parameters @return array<string,mixed> */
    private function safePlan(array $entry, array $parameters): array
    {
        try {
            $strategy = app((string) $entry['strategy_class']);
            $plan = $strategy->plan($parameters);

            return is_array($plan) ? $plan : ['ok' => false, 'errors' => ['strategy_plan_invalid']];
        } catch (Throwable) {
            return ['ok' => false, 'errors' => ['strategy_plan_failed']];
        }
    }

    /** @param array<string,mixed> $entry @param array<string,mixed> $parameters @param array<string,mixed> $plan */
    private function recordDryRun(object $request, array $entry, array $parameters, array $plan, string $actor, int $durationMs): array
    {
        return $this->record($request, $entry, $parameters, 'dry-run', [
            'ok' => (bool) ($plan['ok'] ?? false),
            'plan' => $plan,
        ], $actor, null, null, $durationMs);
    }

    /** @param array<string,mixed> $entry @param array<string,mixed> $parameters @param array<string,mixed> $payload */
    private function record(
        object $request,
        array $entry,
        array $parameters,
        string $phase,
        array $payload,
        string $actor,
        ?object $approval,
        ?string $commitSha,
        int $durationMs,
        ?string $failureReason = null
    ): array {
        $executionId = (string) Str::uuid();
        $correlationId = (string) Str::uuid();
        $result = $this->resultFor($phase, (bool) ($payload['ok'] ?? false), $failureReason);
        $executionRecord = [
            'schema_version' => '1.0',
            'operation_id' => (string) $request->id,
            'execution_id' => $executionId,
            'strategy' => (string) $request->strategy_id,
            'target' => $this->target($parameters),
            'actor' => $actor,
            'approver' => $approval ? (string) $approval->approver_role . ':' . (string) $approval->approver : null,
            'approval_reference' => $approval ? (string) $approval->approval_reference : null,
            'commit_sha' => $commitSha,
            'snapshot_id' => isset($payload['snapshot']) ? 'pop-snapshot:' . $executionId : null,
            'backup_reference' => null,
            'phases' => [$phase => $result],
            'invariants' => $this->invariants($phase, $payload),
            'verification' => $phase === 'verify' ? ['passed' => (bool) ($payload['ok'] ?? false), 'checks' => $payload['checks'] ?? []] : null,
            'rollback_reference' => $phase === 'rollback' ? 'pop-execution:' . (string) $request->id . ':execute' : null,
            'duration_ms' => $durationMs,
            'result' => $result,
            'audit_reference' => 'pop-execution-record:' . $executionId,
            'failure_reason' => $failureReason,
            'correlation_id' => $correlationId,
            'version_pins' => [
                'strategy' => (string) $request->strategy_id,
                'policy' => 'default@' . $this->catalog->policyVersion(),
                'catalog' => 'catalog@' . (string) $request->catalog_version,
                'engine' => 'pop-execution-v1',
                'invariant_packs' => array_values(array_map('strval', (array) ($entry['invariant_packs'] ?? []))),
            ],
        ];
        $storedPayload = $payload + $executionRecord;
        $inserted = DB::table('pop_execution_records')->insertOrIgnore([
            'id' => $executionId,
            'operation_id' => $request->id,
            'execution_id' => $executionId,
            'phase' => $phase,
            'result' => $result,
            'idempotency_key' => $request->idempotency_key . ':' . $phase,
            'correlation_id' => $correlationId,
            'commit_sha' => $commitSha,
            'approval_reference' => $approval?->approval_reference,
            'snapshot' => isset($payload['snapshot']) ? json_encode($payload['snapshot'], JSON_THROW_ON_ERROR) : null,
            'payload' => json_encode($storedPayload, JSON_THROW_ON_ERROR),
            'actor' => substr($actor, 0, 128),
            'created_at' => now(),
        ]);
        $stored = $this->findExecutionByIdempotency($request, $phase);
        if (!$stored) {
            throw new RuntimeException('POP execution record was not persisted; fail closed.');
        }
        if ($inserted) {
            $status = $this->statusFor($phase, $result);
            if ($status !== null) {
                DB::table('pop_operation_requests')->where('id', $request->id)->update(['status' => $status, 'updated_at' => now()]);
            }
            $this->audit($request, $phase, $result === 'succeeded' || $result === 'rolled_back' ? 'success' : 'failure', $parameters, null, null, $correlationId);
        }

        return json_decode((string) $stored->payload, true, 512, JSON_THROW_ON_ERROR) + ['execution_id' => $stored->execution_id, 'phase' => $phase];
    }

    private function resultFor(string $phase, bool $ok, ?string $failureReason): string
    {
        if ($ok) {
            return $phase === 'rollback' ? 'rolled_back' : 'succeeded';
        }

        return $phase === 'verify' ? 'verification_failed' : 'failed';
    }

    private function statusFor(string $phase, string $result): ?string
    {
        return match ($phase) {
            'dry-run' => $result === 'succeeded' ? 'awaiting_approval' : 'failed',
            'execute' => $result === 'succeeded' ? 'verifying' : 'failed',
            'verify' => $result === 'succeeded' ? 'succeeded' : 'verification_failed',
            'rollback' => $result === 'rolled_back' ? 'rolled_back' : 'failed',
            default => null,
        };
    }

    /** @param array<string,mixed> $parameters @return array<string,mixed> */
    private function target(array $parameters): array
    {
        $keys = [
            'student_id', 'campus_id', 'subject_id', 'source_student_class_id',
            'target_student_class_id', 'preserve_session_ids', 'transfer_session_ids',
        ];
        $target = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $parameters)) {
                $target[$key] = $parameters[$key];
            }
        }

        return $target;
    }

    /** @param array<string,mixed> $payload @return array<int,array{id:string,passed:bool}> */
    private function invariants(string $phase, array $payload): array
    {
        $errors = array_map('strval', (array) ($payload['errors'] ?? []));
        $planOk = (bool) data_get($payload, 'plan.ok', $payload['ok'] ?? false);

        return [
            ['id' => 'catalog_binding', 'passed' => true],
            ['id' => 'precondition_plan', 'passed' => $planOk],
            ['id' => 'payment_boundary', 'passed' => !in_array('payment_evidence_present', $errors, true)],
            ['id' => 'transaction_boundary', 'passed' => $phase === 'dry-run' || !in_array('execution_failed', $errors, true)],
            ['id' => 'verification', 'passed' => $phase !== 'verify' || (bool) ($payload['ok'] ?? false)],
        ];
    }

    /** @param array<string,mixed> $parameters */
    private function audit(object $request, string $phase, string $outcome, array $parameters, ?int $actorId = null, ?string $actorRole = null, ?string $correlationId = null): void
    {
        SecurityAuditEvent::append('pop.operation.' . $phase, $outcome, [
            'correlation_id' => $correlationId,
            'campus_id' => isset($parameters['campus_id']) ? (int) $parameters['campus_id'] : null,
            'actor_type' => $actorRole ?: 'pop',
            'actor_id' => $actorId,
            'subject_type' => 'pop_operation',
            'subject_id' => (string) $request->id,
        ], [
            'reason_code' => 'catalog_bound_pop_execution',
            'outcome' => $outcome,
        ]);
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private function existing(object $request, string $phase): ?array
    {
        $row = $this->findExecutionByIdempotency($request, $phase);

        return $row ? json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR) + ['execution_id' => $row->execution_id, 'phase' => $phase] : null;
    }

    private function findExecutionByIdempotency(object $request, string $phase): ?object
    {
        return DB::table('pop_execution_records')
            ->where('idempotency_key', $request->idempotency_key . ':' . $phase)
            ->first();
    }

    private function latest(object $request, string $phase): ?object
    {
        return DB::table('pop_execution_records')
            ->where('operation_id', $request->id)
            ->where('phase', $phase)
            ->latest('created_at')
            ->first();
    }
}
