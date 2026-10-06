<?php

namespace App\Operations\Strategies;

use App\Models\SecurityAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * TD-076 Track B production repairs (design §5): campus-scoped, digest-pinned, reversible POP strategies.
 * A dry-run (expected_digest = '') writes nothing and returns an ids-only manifest plus its sha256 digest; execute
 * only runs when the same digest is pinned in the request and the live data still hashes to it (no names, no PII).
 */
abstract class Td076OccurrenceRepair
{
    abstract protected function ref(): string;

    abstract protected function event(): string;

    /** @return array{actions: list<array<string,mixed>>, quarantine: list<array<string,mixed>>} ids only, sorted */
    abstract protected function build(int $campusId): array;

    /** @param list<array<string,mixed>> $actions @return list<array<string,mixed>> undo entries, each with an `id` */
    abstract protected function apply(array $actions): array;

    /** @param array<string,mixed> $entry true when undone, false when later activity made it unsafe to touch */
    abstract protected function undo(array $entry): bool;

    /** @param array<string,mixed> $parameters */
    public function plan(array $parameters): array
    {
        $campus = (int) ($parameters['campus_id'] ?? 0);
        $want = (string) ($parameters['expected_digest'] ?? '');
        $errors = [];
        if (($parameters['decision_reference'] ?? null) !== $this->ref()) {
            $errors[] = 'case_parameters_mismatch';
        }
        if ($campus <= 0) {
            $errors[] = 'campus_required';
        }
        if ($want !== '' && !preg_match('/^[0-9a-f]{64}$/', $want)) {
            $errors[] = 'digest_format';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'state' => 'invalid'];
        }
        $manifest = $this->build($campus);
        $digest = $this->digest($manifest);
        $state = $want === '' ? 'unpinned' : ($want === $digest ? 'pinned' : ($manifest['actions'] === [] ? 'after' : 'digest_mismatch'));

        return [
            'ok' => $state !== 'digest_mismatch', 'errors' => $state === 'digest_mismatch' ? ['digest_mismatch'] : [],
            'state' => $state, 'campus_id' => $campus, 'digest' => $digest,
            'counts' => [
                'actions' => array_count_values(array_column($manifest['actions'], 'type')),
                'quarantine' => array_count_values(array_column($manifest['quarantine'], 'reason')),
            ],
            'manifest' => $manifest,
        ];
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $context */
    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) && ($plan['state'] ?? null) === 'after') {
            return ['ok' => true, 'already_applied' => true, 'applied' => 0, 'snapshot' => ['entries' => []]];
        }
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'pinned') {
            throw new RuntimeException('td076_plan_not_pinned');
        }

        return DB::transaction(function () use ($plan, $context): array {
            $manifest = $this->build((int) $plan['campus_id']);
            if ($this->digest($manifest) !== $plan['digest']) {
                throw new RuntimeException('td076_digest_drift');
            }
            $entries = $this->apply($manifest['actions']);
            $this->audit($this->event(), ['reason_code' => $this->ref(), 'operation_id' => (string) ($context['operation_id'] ?? ''),
                'campus_id' => (int) $plan['campus_id'], 'applied' => count($entries), 'outcome' => 'success']);

            return ['ok' => true, 'applied' => count($entries), 'snapshot' => ['entries' => $entries]];
        }, 3);
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $result */
    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        if (($plan['state'] ?? null) !== 'after') {
            $errors[] = 'actions_remaining';
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'checks' => ['no_action_remaining']];
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $context */
    public function rollback(array $snapshot, array $context): array
    {
        $entries = $snapshot['entries'] ?? null;
        if (!is_array($entries)) {
            throw new RuntimeException('td076_rollback_snapshot_invalid');
        }

        return DB::transaction(function () use ($entries): array {
            $restored = 0;
            $skipped = [];
            foreach ($entries as $entry) {
                $this->undo($entry) ? $restored++ : $skipped[] = $entry['id'];
            }
            $this->audit($this->event() . '.rollback', ['reason_code' => $this->ref(), 'restored' => $restored,
                'skipped' => count($skipped), 'outcome' => 'success']);

            return ['ok' => $skipped === [], 'partial' => $skipped !== [], 'restored' => $restored, 'skipped_ids' => $skipped];
        }, 3);
    }

    /** @param array<string,mixed> $manifest */
    private function digest(array $manifest): string
    {
        return hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    /** Strict audit: append() swallows insert failures, so confirm the row exists or roll the transaction back. @param array<string,mixed> $metadata */
    private function audit(string $event, array $metadata): void
    {
        $correlationId = (string) Str::uuid();
        SecurityAuditEvent::append($event, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'schedule_batch',
            'correlation_id' => $correlationId], $metadata);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('td076_audit_not_persisted');
        }
    }
}
