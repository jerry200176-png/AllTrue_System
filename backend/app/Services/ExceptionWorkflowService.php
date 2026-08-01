<?php

namespace App\Services;

use App\Models\ExceptionWorkflow;
use App\Models\ClassSession;
use App\Support\SessionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ExceptionWorkflowService
{
    /**
     * Create a workflow once per source key. Repeated calls return the original
     * record so parent retries or duplicate button clicks do not fork the case.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createOrGet(array $attributes): ExceptionWorkflow
    {
        $sourceKey = trim((string) ($attributes['source_key'] ?? ''));
        if ($sourceKey === '') {
            throw new \InvalidArgumentException('source_key is required');
        }

        return DB::transaction(function () use ($sourceKey, $attributes) {
            $existing = ExceptionWorkflow::where('source_key', $sourceKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $payload = [
                'source_key' => $sourceKey,
                'campus_id' => (int) ($attributes['campus_id'] ?? 0),
                'type' => (string) ($attributes['type'] ?? 'student_leave'),
                'status' => (string) ($attributes['status'] ?? 'open'),
                'severity' => (string) ($attributes['severity'] ?? 'medium'),
            ];

            foreach ([
                'student_id',
                'student_class_id',
                'class_session_id',
                'source_type',
                'source_id',
                'owner_user_id',
                'due_at',
                'closed_at',
                'closed_reason',
                'payload',
                'created_by_user_id',
                'parent_session_id',
            ] as $key) {
                if (array_key_exists($key, $attributes)) {
                    $payload[$key] = $attributes[$key];
                }
            }

            return ExceptionWorkflow::create($payload);
        });
    }

    /**
     * @param  array<int>  $campusIds  Empty array means all campuses.
     */
    public function queryForCampusIds(array $campusIds): Builder
    {
        $query = ExceptionWorkflow::query();
        $ids = array_values(array_unique(array_map('intval', $campusIds)));

        if (!empty($ids)) {
            $query->whereIn('campus_id', $ids);
        }

        return $query->orderByRaw('due_at IS NULL ASC')
            ->orderBy('due_at')
            ->orderByDesc('id');
    }

    /**
     * Replace the candidate snapshot for one workflow. The confirmation path
     * must still re-check conflicts; candidates are recommendations, not locks.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     */
    public function replaceCandidates(ExceptionWorkflow $workflow, array $candidates): void
    {
        DB::transaction(function () use ($workflow, $candidates) {
            $workflow->candidates()->delete();

            foreach ($candidates as $candidate) {
                $workflow->candidates()->create([
                    'rank' => (int) ($candidate['rank'] ?? 1),
                    'candidate_date' => $candidate['candidate_date'],
                    'start_time' => $candidate['start_time'],
                    'end_time' => $candidate['end_time'],
                    'teacher_id' => $candidate['teacher_id'] ?? null,
                    'room_id' => $candidate['room_id'] ?? null,
                    'status' => (string) ($candidate['status'] ?? 'available'),
                    'score' => (int) ($candidate['score'] ?? 0),
                    'reasons' => $candidate['reasons'] ?? null,
                    'expires_at' => $candidate['expires_at'],
                ]);
            }
        });
    }

    /** Resolve the original parent request and operational session together. */
    public function approveLeave(ExceptionWorkflow $workflow, string $closedReason, array $payload = []): ExceptionWorkflow
    {
        return DB::transaction(function () use ($workflow, $closedReason, $payload) {
            /** @var ExceptionWorkflow|null $locked */
            $locked = ExceptionWorkflow::query()
                ->where('id', (int) $workflow->getAttribute('id'))
                ->lockForUpdate()
                ->first();
            if (!$locked) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            }
            $classSessionId = (int) $locked->getAttribute('class_session_id');
            /** @var ClassSession|null $session */
            $session = $classSessionId > 0
                ? ClassSession::query()->where('id', $classSessionId)->lockForUpdate()->first()
                : null;

            if ($session && in_array(strtolower((string) $session->getAttribute('Status')), [SessionStatus::LEAVE_REQUESTED, SessionStatus::SCHEDULED, 'rescheduled'], true)) {
                $session->setAttribute('Status', SessionStatus::LEAVE);
                $session->setAttribute('Note', $this->appendNote($session->getAttribute('Note'), 'parent-leave-approved'));
                $session->save();
            }

            $locked->setAttribute('status', $closedReason === 'candidate_confirmed' ? 'confirmed' : 'waived');
            $locked->setAttribute('closed_at', now());
            $locked->setAttribute('closed_reason', $closedReason);
            $locked->setAttribute('payload', array_merge($locked->getAttribute('payload') ?? [], $payload, [
                'approved_at' => now()->toIso8601String(),
            ]));
            $locked->save();

            return $locked;
        });
    }

    /** Reject a pending parent request and restore the session to scheduled. */
    public function rejectLeave(ExceptionWorkflow $workflow, string $reason, int $operatorId = 0): ExceptionWorkflow
    {
        return DB::transaction(function () use ($workflow, $reason, $operatorId) {
            /** @var ExceptionWorkflow|null $locked */
            $locked = ExceptionWorkflow::query()
                ->where('id', (int) $workflow->getAttribute('id'))
                ->lockForUpdate()
                ->first();
            if (!$locked) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            }
            if ($locked->getAttribute('closed_at') || in_array($locked->getAttribute('status'), ['confirmed', 'waived', 'rejected', 'closed'], true)) {
                throw new \InvalidArgumentException('此請假案件已結案，無法退回');
            }

            $classSessionId = (int) $locked->getAttribute('class_session_id');
            /** @var ClassSession|null $session */
            $session = $classSessionId > 0
                ? ClassSession::query()->where('id', $classSessionId)->lockForUpdate()->first()
                : null;
            if ($session && strtolower((string) $session->getAttribute('Status')) !== SessionStatus::LEAVE_REQUESTED) {
                throw new \InvalidArgumentException('原堂次已不是待審核請假狀態，無法退回');
            }

            if ($session) {
                $session->setAttribute('Status', SessionStatus::SCHEDULED);
                $session->setAttribute('Note', $this->appendNote($session->getAttribute('Note'), 'parent-leave-rejected'));
                $session->save();
            }

            $locked->setAttribute('status', 'rejected');
            $locked->setAttribute('closed_at', now());
            $locked->setAttribute('closed_reason', 'parent_leave_rejected');
            $locked->setAttribute('payload', array_merge($locked->getAttribute('payload') ?? [], [
                'rejected_at' => now()->toIso8601String(),
                'rejected_by_user_id' => $operatorId ?: null,
                'rejection_reason' => trim($reason),
            ]));
            $locked->save();

            return $locked;
        });
    }

    private function appendNote(?string $note, string $suffix): string
    {
        $current = trim((string) $note);
        if ($current === '') return $suffix;
        if (str_contains($current, $suffix)) return $current;
        return $current . '|' . $suffix;
    }
}
